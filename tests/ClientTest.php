<?php

declare(strict_types=1);

namespace OpenHandle\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenHandle\Internal\Operations;
use OpenHandle\Models\InstagramHighlight;
use OpenHandle\Models\InstagramProfile;
use OpenHandle\Models\InstagramStory;
use OpenHandle\OpenHandle;
use OpenHandle\OpenHandleError;
use OpenHandle\OpenHandleReferenceError;
use OpenHandle\ReferenceMismatchError;
use OpenHandle\Tests\Support\MockApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    public function testGeneratesEveryOpenApiOperationExactlyOnce(): void
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/../openapi/openhandle.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['paths']);
        $expected = [];
        foreach ($document['paths'] as $apiPath => $pathItem) {
            self::assertIsArray($pathItem);
            foreach (array_keys($pathItem) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $expected[] = "{$method} {$apiPath}";
                }
            }
        }
        $generated = array_map(static fn(array $operation): string => "{$operation['method']} {$operation['apiPath']}", Operations::ALL);
        sort($expected);
        sort($generated);

        self::assertSame($expected, $generated);
        self::assertCount(count(Operations::ALL), array_unique(array_column(Operations::ALL, 'path')));
    }

    public function testTreatsProfileStringsAsUsernamesAndIdsAsExplicitStrings(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());
        $openhandle = $api->client();

        $openhandle->instagram->profile('12356')->get(freshness: '24h');
        $openhandle->instagram->profile(id: '12356')->get();

        self::assertSame('/v1/instagram/profiles/%4012356', $api->requests[0]->getUri()->getPath());
        self::assertSame(['freshness' => '24h'], $api->query(0));
        self::assertSame('/v1/instagram/profiles/12356', $api->requests[1]->getUri()->getPath());
    }

    public function testParsesExplicitSocialUrlsLocallyAndRejectsResourceMismatches(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());
        $openhandle = $api->client();

        $openhandle->instagram->profile(url: 'https://www.instagram.com/openai/?hl=en')->get();
        self::assertSame('/v1/instagram/profiles/%40openai', $api->requests[0]->getUri()->getPath());

        $this->expectException(ReferenceMismatchError::class);
        $openhandle->instagram->profile(url: 'https://www.instagram.com/p/Db04otPRpRH/');
    }

    public function testRejectsNumericReferencesBeforeMakingARequest(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());

        try {
            $api->client()->instagram->profile(12356); // @phpstan-ignore argument.type
            self::fail('A numeric reference was accepted.');
        } catch (OpenHandleReferenceError $error) {
            self::assertStringContainsString('opaque strings', $error->getMessage());
        }
        self::assertSame([], $api->requests);
    }

    public function testRejectsAmbiguousReferencesBeforeMakingARequest(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());

        $this->expectException(OpenHandleReferenceError::class);
        $api->client()->instagram->profile(username: 'openai', id: '25025320');
    }

    public function testBindsNestedResourcesAndFollowsOpaquePaginationCursors(): void
    {
        $api = new MockApi(static function (RequestInterface $request): ResponseInterface {
            return MockApi::page(str_contains($request->getUri()->getQuery(), 'cursor=') ? null : 'next-page');
        });
        $openhandle = $api->client();

        $first = $openhandle->instagram->post('Db04otPRpRH')->comment('18120112390529134')->replies->list();

        self::assertTrue($first->hasNextPage);
        self::assertSame('next-page', $first->nextCursor);
        self::assertEquals(new DateTimeImmutable('2026-08-26T12:00:00Z'), $first->capturedAt);
        self::assertSame('req_test', $first->requestId);
        self::assertSame('0.000', $first->billing->cost);
        self::assertSame('test', $first->billing->environment);

        $second = $first->next();

        self::assertNotNull($second);
        self::assertFalse($second->hasNextPage);
        self::assertNull($second->next());
        self::assertCount(2, $api->requests);
        self::assertSame('/v1/instagram/posts/Db04otPRpRH/comments/18120112390529134/replies', $api->requests[0]->getUri()->getPath());
        self::assertSame('next-page', $api->query(1)['cursor']);
    }

    public function testItemsIteratesLazilyAcrossPages(): void
    {
        $api = new MockApi(static function (RequestInterface $request): ResponseInterface {
            if (str_contains($request->getUri()->getQuery(), 'cursor=')) {
                return MockApi::page(null, [self::post('3')]);
            }

            return MockApi::page('next-page', [self::post('1'), self::post('2')]);
        });

        $items = $api->client()->instagram->profile('openai')->posts->items();
        self::assertSame([], $api->requests);

        $ids = [];
        foreach ($items as $post) {
            $ids[] = $post->id;
        }

        self::assertSame(['1', '2', '3'], $ids);
        self::assertCount(2, $api->requests);
    }

    public function testThrowsTypedApiErrorsWithoutRetryingNonRetryableFailures(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::error(403, [
            'code' => 'PROFILE_PRIVATE',
            'message' => 'This profile is private.',
            'requestId' => 'req_private',
            'retryable' => false,
        ]));

        try {
            $api->client(maxRetries: 2)->instagram->profile('private')->get();
            self::fail('The API error was not thrown.');
        } catch (OpenHandleError $error) {
            self::assertSame('PROFILE_PRIVATE', $error->code);
            self::assertSame('PROFILE_PRIVATE', $error->getCode());
            self::assertSame('req_private', $error->requestId);
            self::assertFalse($error->retryable);
            self::assertSame(403, $error->status);
        }
        self::assertCount(1, $api->requests);
    }

    public function testRetriesAnExplicitlyRetryableFailure(): void
    {
        $responses = 0;
        $api = new MockApi(static function () use (&$responses): ResponseInterface {
            ++$responses;

            return $responses === 1
                ? MockApi::error(503, ['code' => 'UPSTREAM_DEGRADED', 'message' => 'Try again.', 'retryable' => true], ['Retry-After' => '0.01'])
                : MockApi::profile();
        });

        $response = $api->client(maxRetries: 1)->instagram->profile('openai')->get();

        self::assertSame('openai', $response->data->handle);
        self::assertCount(2, $api->requests);
    }

    public function testDoesNotRetryAnUpstreamSwitch(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::error(409, [
            'code' => 'UPSTREAM_SWITCHED',
            'message' => 'Restart the list from its first page.',
            'retryable' => false,
        ]));

        try {
            $api->client(maxRetries: 2)->instagram->profile('openai')->posts->list(cursor: 'stale');
            self::fail('The upstream switch was not thrown.');
        } catch (OpenHandleError $error) {
            self::assertSame('UPSTREAM_SWITCHED', $error->code);
        }
        self::assertCount(1, $api->requests);
    }

    public function testSendsUrlFetchesAsTypedJsonRequests(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());

        $response = $api->client()->fetch('https://www.instagram.com/openai/', freshness: '7d');

        self::assertSame('POST', $api->requests[0]->getMethod());
        self::assertSame('application/json', $api->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame(
            ['url' => 'https://www.instagram.com/openai/', 'freshness' => '7d'],
            json_decode((string) $api->requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(InstagramProfile::class, $response->data);
    }

    public function testFetchTellsAHighlightFromAStoryByItsFields(): void
    {
        $highlight = ['id' => '17900000000000001', 'title' => 'Launch', 'stories' => [], 'cover' => null, 'isPinned' => false];
        $story = ['id' => '3100000000000000001', 'code' => 'DSTORY', 'fbid' => null, 'expiresAt' => '2026-08-27T12:00:00Z'];
        $bodies = [$highlight, $story];
        $api = new MockApi(static function () use (&$bodies): ResponseInterface {
            return MockApi::envelope([
                'platform' => 'instagram',
                'resource' => 'entity',
                'capturedAt' => '2026-08-26T12:00:00Z',
                'source' => 'live',
                'data' => array_shift($bodies),
            ]);
        });
        $openhandle = $api->client();

        $first = $openhandle->fetch('https://www.instagram.com/stories/highlights/17900000000000001/');
        $second = $openhandle->fetch('https://www.instagram.com/stories/openai/3100000000000000001/');

        self::assertInstanceOf(InstagramHighlight::class, $first->data);
        self::assertSame('Launch', $first->data->title);
        self::assertInstanceOf(InstagramStory::class, $second->data);
        self::assertSame('DSTORY', $second->data->code);
    }

    public function testKeepsOmittedMetricsNullInsteadOfZero(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::envelope([
            'platform' => 'instagram',
            'resource' => 'profile',
            'capturedAt' => '2026-08-26T12:00:00Z',
            'source' => 'cache',
            'data' => ['id' => '25025320', 'handle' => 'openai', 'metrics' => ['followers' => 1200]],
        ]));

        $profile = $api->client()->instagram->profile('openai')->get()->data;

        self::assertSame(1200, $profile->metrics->followers);
        self::assertNull($profile->metrics->following);
        self::assertNull($profile->bio);
    }

    public function testReportsAResponseThatBreaksTheContractAsInvalidResponse(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::envelope([
            'platform' => 'instagram',
            'resource' => 'profile',
            'capturedAt' => '2026-08-26T12:00:00Z',
            'source' => 'live',
            'data' => ['id' => '25025320', 'handle' => 42],
        ]));

        try {
            $api->client()->instagram->profile('openai')->get();
            self::fail('The invalid response was accepted.');
        } catch (OpenHandleError $error) {
            self::assertSame('INVALID_RESPONSE', $error->code);
            self::assertSame('req_test', $error->requestId);
            self::assertStringContainsString('data.handle', $error->message);
        }
    }

    public function testRequiresANonEmptyApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OpenHandle('   ');
    }

    public function testSendsAuthorizationAndClientHeaders(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());

        $api->client()->instagram->profile('openai')->get();

        self::assertSame('Bearer oh_test_sdk', $api->requests[0]->getHeaderLine('Authorization'));
        self::assertMatchesRegularExpression('#^openhandle-php/\d+\.\d+\.\d+$#', $api->requests[0]->getHeaderLine('X-OpenHandle-Client'));
    }

    public function testAppliesAPerRequestTimeoutOverTheClientDefault(): void
    {
        $api = new MockApi(static fn(): ResponseInterface => MockApi::profile());
        $openhandle = $api->client();

        $openhandle->twitter->profile('openai')->get();
        $openhandle->twitter->profile('openai')->get(timeout: 5.0);

        self::assertSame(30.0, $api->options[0]['timeout']);
        self::assertSame(5.0, $api->options[1]['timeout']);
    }

    #[DataProvider('followerLimits')]
    public function testPreservesFollowerLimitWithoutInventingPagination(?bool $limited): void
    {
        $meta = ['cursors' => ['next' => null]];
        if ($limited !== null) {
            $meta['isLimited'] = $limited;
        }
        $api = new MockApi(static fn(): ResponseInterface => MockApi::envelope([
            'platform' => 'instagram',
            'resource' => 'profile',
            'capturedAt' => '2026-08-26T12:00:00Z',
            'source' => 'live',
            'data' => [],
            'meta' => $meta,
        ]));

        $page = $api->client()->instagram->profile('example')->followers->list();

        self::assertSame($limited, $page->isLimited);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->hasNextPage);
    }

    /**
     * @return iterable<string, array{bool|null}>
     */
    public static function followerLimits(): iterable
    {
        yield 'not reported' => [null];
        yield 'not limited' => [false];
        yield 'limited' => [true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function post(string $id): array
    {
        return ['id' => $id, 'url' => "https://www.instagram.com/p/{$id}/"];
    }
}
