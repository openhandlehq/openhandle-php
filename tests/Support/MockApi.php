<?php

declare(strict_types=1);

namespace OpenHandle\Tests\Support;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OpenHandle\OpenHandle;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An in-memory OpenHandle API that records every request it receives.
 */
final class MockApi
{
    public const RESPONSE_HEADERS = [
        'Content-Type' => 'application/json',
        'OpenHandle-Billing-Disposition' => 'test',
        'OpenHandle-Cost' => '0.000',
        'OpenHandle-Environment' => 'test',
        'X-Request-ID' => 'req_test',
    ];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<array<array-key, mixed>> */
    public array $options = [];

    /**
     * @param Closure(RequestInterface): ResponseInterface $handler
     */
    public function __construct(private readonly Closure $handler) {}

    public function client(int $maxRetries = 0): OpenHandle
    {
        $handler = function (RequestInterface $request, array $options): PromiseInterface {
            $this->requests[] = $request;
            $this->options[] = $options;

            return Create::promiseFor(($this->handler)($request));
        };

        return new OpenHandle(
            'oh_test_sdk',
            baseUrl: 'https://api.openhandle.test',
            maxRetries: $maxRetries,
            httpClient: new Client(['handler' => HandlerStack::create($handler)]),
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    public function query(int $index): array
    {
        parse_str($this->requests[$index]->getUri()->getQuery(), $query);

        return $query;
    }

    public static function profile(): ResponseInterface
    {
        return self::envelope([
            'platform' => 'instagram',
            'resource' => 'profile',
            'capturedAt' => '2026-08-26T12:00:00Z',
            'source' => 'live',
            'data' => ['id' => '25025320', 'handle' => 'openai', 'url' => 'https://www.instagram.com/openai/'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function page(?string $cursor, array $items = []): ResponseInterface
    {
        return self::envelope([
            'platform' => 'instagram',
            'resource' => 'comment',
            'capturedAt' => '2026-08-26T12:00:00Z',
            'source' => 'live',
            'data' => $items,
            'meta' => ['cursors' => ['next' => $cursor]],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function envelope(array $body, int $status = 200, array $headers = self::RESPONSE_HEADERS): ResponseInterface
    {
        return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $error
     * @param array<string, string> $headers
     */
    public static function error(int $status, array $error, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode(['error' => $error], JSON_THROW_ON_ERROR));
    }
}
