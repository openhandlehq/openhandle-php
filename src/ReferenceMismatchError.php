<?php

declare(strict_types=1);

namespace OpenHandle;

/**
 * A social URL for a different platform or resource than the selector.
 */
final class ReferenceMismatchError extends OpenHandleReferenceError
{
    public function __construct(
        public readonly string $expectedPlatform,
        public readonly string $expectedResource,
        public readonly string $actualPlatform,
        public readonly string $actualResource,
    ) {
        parent::__construct(
            "Expected a {$expectedPlatform} {$expectedResource} URL, received a {$actualPlatform} {$actualResource} URL.",
        );
    }
}
