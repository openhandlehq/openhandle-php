<?php

declare(strict_types=1);

namespace OpenHandle;

/**
 * Authoritative accounting metadata returned in response headers.
 *
 * Monetary values remain decimal strings to avoid precision loss.
 */
final class Billing
{
    public function __construct(
        public readonly ?string $cost,
        public readonly ?string $datasetVersion,
        public readonly ?string $disposition,
        public readonly ?string $environment,
        public readonly ?string $listPrice,
    ) {}
}
