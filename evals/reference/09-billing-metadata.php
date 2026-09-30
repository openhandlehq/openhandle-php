<?php

declare(strict_types=1);

use OpenHandle\Models\InstagramProfile;
use OpenHandle\OpenHandle;
use OpenHandle\Response;

/**
 * @return array{string|null, string|null, string}
 */
function getProfileAccounting(OpenHandle $openhandle): array
{
    $response = getProfileResponse($openhandle);

    return [$response->billing->cost, $response->billing->environment, $response->requestId];
}

/**
 * @return Response<InstagramProfile>
 */
function getProfileResponse(OpenHandle $openhandle): Response
{
    return $openhandle->instagram->profile('northstar_forge_test')->get();
}
