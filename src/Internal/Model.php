<?php

declare(strict_types=1);

namespace OpenHandle\Internal;

/**
 * A generated response model that hydrates from a decoded JSON object.
 *
 * @internal
 */
interface Model
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = ''): static;
}
