<?php

declare(strict_types=1);

namespace OpenHandle;

use DateTimeImmutable;
use Exception;
use OpenHandle\Internal\Wire;
use OpenHandle\Models\ResponseMeta;

/**
 * Envelope metadata shared by singular responses and pages.
 */
abstract class ResponseMetadata
{
    public readonly string $platform;

    public readonly string $resource;

    /**
     * "live" or "cache".
     */
    public readonly string $source;

    public readonly ?DateTimeImmutable $capturedAt;

    /**
     * Supplied collection context, counts, and pagination flags.
     */
    public readonly ?ResponseMeta $meta;

    /**
     * @param array<string, mixed> $raw the complete decoded response body, exactly as returned by the API
     */
    public function __construct(
        public readonly array $raw,
        public readonly string $requestId,
        public readonly Billing $billing,
    ) {
        $this->platform = is_string($raw['platform'] ?? null) ? $raw['platform'] : '';
        $this->resource = is_string($raw['resource'] ?? null) ? $raw['resource'] : '';
        $this->source = is_string($raw['source'] ?? null) ? $raw['source'] : '';
        $this->capturedAt = self::timestamp($raw['capturedAt'] ?? null);
        $this->meta = Wire::nullableObject($raw['meta'] ?? null, 'meta', ResponseMeta::class);
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
