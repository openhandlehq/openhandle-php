<?php

declare(strict_types=1);

use OpenHandle\OpenHandle;
use OpenHandle\OpenHandleError;

/**
 * @return array{string, string|null, bool}|null
 */
function describeFailure(OpenHandle $openhandle): ?array
{
    try {
        $openhandle->instagram->profile('wanderline_private_test')->get();
    } catch (OpenHandleError $error) {
        return [$error->code, $error->requestId, $error->retryable];
    }

    return null;
}
