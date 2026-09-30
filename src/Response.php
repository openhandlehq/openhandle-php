<?php

declare(strict_types=1);

namespace OpenHandle;

/**
 * A typed singular response preserving the public envelope.
 *
 * @template-covariant TData
 */
final class Response extends ResponseMetadata
{
    /**
     * @param array<string, mixed> $raw
     * @param TData $data
     */
    public function __construct(
        array $raw,
        string $requestId,
        Billing $billing,
        public readonly mixed $data,
    ) {
        parent::__construct($raw, $requestId, $billing);
    }
}
