<?php

declare(strict_types=1);

use OpenHandle\Models\InstagramProfile;
use OpenHandle\OpenHandle;

function getProfile(OpenHandle $openhandle): InstagramProfile
{
    $response = $openhandle->instagram->profile('northstar_forge_test')->get(freshness: '24h');

    return $response->data;
}
