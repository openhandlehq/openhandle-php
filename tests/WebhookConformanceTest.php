<?php

declare(strict_types=1);

namespace OpenHandle\Tests;

use OpenHandle\Webhook;
use OpenHandle\WebhookVerificationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type WebhookFixtureEvent array{id: string, type: string, environment: string, createdAt: string, data: array{platform: string, platformId: string|null, handle: string|null, blockedAt: string}}
 * @phpstan-type WebhookFixtureCase array{name: string, body: string, headers: array<string, string>, now: int, toleranceSeconds?: int, event?: WebhookFixtureEvent, error?: string}
 * @phpstan-type WebhookFixture array{version: int, secret: string, defaultToleranceSeconds: int, cases: list<WebhookFixtureCase>}
 */
final class WebhookConformanceTest extends TestCase
{
    public function testFixtureVersionIsPinned(): void
    {
        self::assertSame(1, self::fixture()['version']);
        self::assertSame(300, self::fixture()['defaultToleranceSeconds']);
    }

    /**
     * @param array<string, string> $headers
     * @param WebhookFixtureEvent|null $event
     */
    #[DataProvider('cases')]
    public function testSharedWebhookConformance(
        string $body,
        array $headers,
        int $now,
        ?int $toleranceSeconds,
        ?array $event,
        ?string $error,
    ): void {
        $secret = self::fixture()['secret'];
        $verify = static fn() => $toleranceSeconds === null
            ? Webhook::verify($body, $headers, $secret, now: $now)
            : Webhook::verify($body, $headers, $secret, $toleranceSeconds, $now);

        if ($error === null) {
            self::assertNotNull($event);
            $verified = $verify();
            self::assertSame($event['id'], $verified->id);
            self::assertSame($event['type'], $verified->type);
            self::assertSame($event['environment'], $verified->environment);
            self::assertSame($event['createdAt'], $verified->createdAt);
            self::assertSame($event['data']['platform'], $verified->data->platform);
            self::assertSame($event['data']['platformId'], $verified->data->platformId);
            self::assertSame($event['data']['handle'], $verified->data->handle);
            self::assertSame($event['data']['blockedAt'], $verified->data->blockedAt);

            return;
        }

        try {
            $verify();
            self::fail("The webhook verified instead of failing with {$error}.");
        } catch (WebhookVerificationError $raised) {
            self::assertSame($error, $raised->code);
            self::assertSame($error, $raised->getCode());
        }
    }

    public function testPsr7StyleHeaderListsVerify(): void
    {
        $case = self::fixture()['cases'][0];
        $headers = [];
        foreach ($case['headers'] as $name => $value) {
            $headers[$name] = [$value];
        }

        $event = Webhook::verify($case['body'], $headers, self::fixture()['secret'], now: $case['now']);

        self::assertSame('826107ab-cbf7-4937-bd7b-f31c08be3283', $event->id);
    }

    public function testValidSignatureOverJsonThatIsNotAnEventIsAnInvalidPayload(): void
    {
        $secret = self::fixture()['secret'];
        $body = '[1,2,3]';
        $headers = [
            'X-Openhandle-Timestamp' => '1795521600',
            'X-Openhandle-Signature' => 'sha256=' . hash_hmac('sha256', "1795521600.{$body}", $secret),
        ];

        try {
            Webhook::verify($body, $headers, $secret, now: 1795521600);
            self::fail('The webhook verified instead of failing with invalid_payload.');
        } catch (WebhookVerificationError $raised) {
            self::assertSame(WebhookVerificationError::INVALID_PAYLOAD, $raised->code);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, int|null, WebhookFixtureEvent|null, string|null}>
     */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['name'] => [
                $case['body'],
                $case['headers'],
                $case['now'],
                $case['toleranceSeconds'] ?? null,
                $case['event'] ?? null,
                $case['error'] ?? null,
            ];
        }
    }

    /**
     * @return WebhookFixture
     */
    private static function fixture(): array
    {
        /** @var WebhookFixture */
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/webhook-conformance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
