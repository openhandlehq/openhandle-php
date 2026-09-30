<?php

declare(strict_types=1);

namespace OpenHandle\Internal;

use Closure;
use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use JsonException;
use OpenHandle\Billing;
use OpenHandle\OpenHandleError;
use OpenHandle\Page;
use OpenHandle\Response;
use OutOfBoundsException;
use Psr\Http\Message\ResponseInterface;
use TypeError;
use UnexpectedValueException;

/**
 * Sends requests, applies retries, and wraps envelopes in typed responses.
 *
 * @internal
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.openhandle.dev';
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_TIMEOUT = 30.0;
    private const MAX_RESPONSE_BYTES = 32 << 20;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly ClientInterface $client;

    public function __construct(
        string $apiKey,
        ?string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        ?ClientInterface $client,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('OpenHandle requires a non-empty apiKey.');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout must be positive.');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('maxRetries must not be negative.');
        }
        $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('baseUrl must be an absolute HTTP or HTTPS URL.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->client = $client ?? new Client();
    }

    public static function version(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('openhandle/sdk');
        } catch (OutOfBoundsException) {
            return '0.0.0';
        }
        if ($version === null || preg_match('/^v?(\d+\.\d+\.\d+)$/', $version, $matches) !== 1) {
            return '0.0.0';
        }

        return $matches[1];
    }

    /**
     * @template T
     *
     * @param array<string, string> $bindings
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $body
     * @param Closure(mixed, array<string, mixed>): T $hydrate
     *
     * @return Response<T>
     */
    public function response(
        string $method,
        string $apiPath,
        array $bindings,
        array $params,
        ?array $body,
        ?float $timeout,
        ?int $maxRetries,
        Closure $hydrate,
    ): Response {
        [$raw, $requestId, $billing] = $this->request($method, $apiPath, $bindings, $params, $body, $timeout, $maxRetries);

        return $this->hydrate(
            $requestId,
            static fn(): Response => new Response($raw, $requestId, $billing, $hydrate($raw['data'] ?? null, $raw)),
        );
    }

    /**
     * @template T
     *
     * @param array<string, string> $bindings
     * @param array<string, mixed> $params
     * @param Closure(mixed, string): T $hydrateItem
     *
     * @return Page<T>
     */
    public function page(
        string $method,
        string $apiPath,
        array $bindings,
        array $params,
        ?float $timeout,
        ?int $maxRetries,
        Closure $hydrateItem,
    ): Page {
        [$raw, $requestId, $billing] = $this->request($method, $apiPath, $bindings, $params, null, $timeout, $maxRetries);
        $fetchNext = fn(string $cursor): Page => $this->page(
            $method,
            $apiPath,
            $bindings,
            [...$params, 'cursor' => $cursor],
            $timeout,
            $maxRetries,
            $hydrateItem,
        );

        return $this->hydrate(
            $requestId,
            static fn(): Page => new Page($raw, $requestId, $billing, Wire::list($raw['data'] ?? null, 'data', $hydrateItem), $fetchNext),
        );
    }

    /**
     * @param array<string, string> $bindings
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $body
     *
     * @return array{array<string, mixed>, string, Billing}
     */
    private function request(
        string $method,
        string $apiPath,
        array $bindings,
        array $params,
        ?array $body,
        ?float $timeout,
        ?int $maxRetries,
    ): array {
        $retries = $maxRetries ?? $this->maxRetries;
        if ($retries < 0) {
            throw new InvalidArgumentException('maxRetries must not be negative.');
        }
        $url = $this->baseUrl . self::bindPath($apiPath, $bindings);
        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::QUERY => self::encodeParams($params),
            RequestOptions::TIMEOUT => $timeout ?? $this->timeout,
            RequestOptions::HEADERS => $this->headers($body !== null),
        ];
        if ($body !== null) {
            $options[RequestOptions::BODY] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $attempt = 0;
        while (true) {
            try {
                return $this->attempt($method, $url, $options);
            } catch (OpenHandleError $error) {
                if (!$error->retryable || $attempt >= $retries) {
                    throw $error;
                }
                usleep((int) round(self::retryDelay($error, $attempt) * 1_000_000));
                ++$attempt;
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{array<string, mixed>, string, Billing}
     */
    private function attempt(string $method, string $url, array $options): array
    {
        try {
            $response = $this->client->request(strtoupper($method), $url, $options);
        } catch (GuzzleException $cause) {
            throw new OpenHandleError(
                code: 'TRANSPORT_ERROR',
                message: 'OpenHandle request failed.',
                retryable: true,
                cause: $cause,
            );
        }

        $body = self::decodeBody($response);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw self::apiError($response, $body);
        }

        return [$body, $response->getHeaderLine('X-Request-ID'), self::billing($response)];
    }

    /**
     * @template T
     *
     * @param Closure(): T $hydrate
     *
     * @return T
     */
    private function hydrate(string $requestId, Closure $hydrate): mixed
    {
        try {
            return $hydrate();
        } catch (UnexpectedValueException|TypeError $cause) {
            throw new OpenHandleError(
                code: 'INVALID_RESPONSE',
                message: 'OpenHandle returned a response that does not match the SDK contract: ' . $cause->getMessage(),
                requestId: $requestId !== '' ? $requestId : null,
                cause: $cause,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $hasBody): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$this->apiKey}",
            'X-OpenHandle-Client' => 'openhandle-php/' . self::version(),
        ];
        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /**
     * @param array<string, string> $bindings
     */
    private static function bindPath(string $apiPath, array $bindings): string
    {
        $segments = array_map(static function (string $segment) use ($apiPath, $bindings): string {
            if (!str_starts_with($segment, '{') || !str_ends_with($segment, '}')) {
                return $segment;
            }
            $name = substr($segment, 1, -1);
            $value = $bindings[$name] ?? '';
            if ($value === '') {
                throw new InvalidArgumentException("Missing bound SDK reference {$name} for {$apiPath}.");
            }

            return rawurlencode($value);
        }, explode('/', $apiPath));

        return implode('/', $segments);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    private static function encodeParams(array $params): array
    {
        $encoded = [];
        foreach ($params as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $encoded[$name] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339),
                is_scalar($value) => (string) $value,
                default => throw new InvalidArgumentException("Unsupported value for the {$name} option."),
            };
        }

        return $encoded;
    }

    private static function billing(ResponseInterface $response): Billing
    {
        $header = static fn(string $name): ?string => $response->hasHeader($name) ? $response->getHeaderLine($name) : null;

        return new Billing(
            cost: $header('OpenHandle-Cost'),
            datasetVersion: $header('OpenHandle-Dataset-Version'),
            disposition: $header('OpenHandle-Billing-Disposition'),
            environment: $header('OpenHandle-Environment'),
            listPrice: $header('OpenHandle-List-Price'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(ResponseInterface $response): array
    {
        $requestId = $response->getHeaderLine('X-Request-ID');
        $stream = $response->getBody();
        $contents = $stream->read(self::MAX_RESPONSE_BYTES + 1);
        while (!$stream->eof() && strlen($contents) <= self::MAX_RESPONSE_BYTES) {
            $contents .= $stream->read(self::MAX_RESPONSE_BYTES + 1 - strlen($contents));
        }
        if (strlen($contents) > self::MAX_RESPONSE_BYTES) {
            throw new OpenHandleError(
                code: 'INVALID_RESPONSE',
                message: 'OpenHandle response exceeded the maximum supported size.',
                requestId: $requestId !== '' ? $requestId : null,
                status: $response->getStatusCode(),
            );
        }
        if ($contents === '') {
            return [];
        }
        try {
            $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new OpenHandleError(
                code: 'INVALID_RESPONSE',
                message: 'OpenHandle returned invalid JSON.',
                requestId: $requestId !== '' ? $requestId : null,
                status: $response->getStatusCode(),
                cause: $cause,
            );
        }
        if (!is_array($body) || (array_is_list($body) && $body !== [])) {
            return [];
        }

        return Wire::map($body, 'body');
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function apiError(ResponseInterface $response, array $body): OpenHandleError
    {
        $status = $response->getStatusCode();
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        $requestId = $error['requestId'] ?? null;
        $retryable = $error['retryable'] ?? null;
        $details = $error['details'] ?? null;
        $headerRequestId = $response->getHeaderLine('X-Request-ID');

        return new OpenHandleError(
            code: is_string($code) && $code !== '' ? $code : "HTTP_{$status}",
            message: is_string($message) && $message !== '' ? $message : "OpenHandle request failed with status {$status}.",
            requestId: is_string($requestId) && $requestId !== '' ? $requestId : ($headerRequestId !== '' ? $headerRequestId : null),
            retryable: is_bool($retryable) ? $retryable : $status === 429 || $status >= 500,
            retryAfter: self::parseRetryAfter($response->getHeaderLine('Retry-After')),
            status: $status,
            details: is_array($details) && !array_is_list($details) ? Wire::map($details, 'error.details') : null,
        );
    }

    private static function parseRetryAfter(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        try {
            $target = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return max(0.0, (float) $target->format('U.u') - microtime(true));
    }

    private static function retryDelay(OpenHandleError $error, int $attempt): float
    {
        if ($error->retryAfter !== null && $error->retryAfter > 0) {
            return $error->retryAfter;
        }
        $base = min(4.0, 0.25 * (2.0 ** min($attempt, 4)));

        return $base * (0.75 + (mt_rand() / mt_getrandmax()) * 0.5);
    }
}
