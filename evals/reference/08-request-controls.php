<?php

declare(strict_types=1);

use OpenHandle\Models\TwitterProfile;
use OpenHandle\OpenHandle;

function getProfileWithoutRetries(OpenHandle $openhandle): TwitterProfile
{
    $response = $openhandle->twitter->profile('northstar_forge_test')->get(timeout: 5.0, maxRetries: 0);

    return $response->data;
}
