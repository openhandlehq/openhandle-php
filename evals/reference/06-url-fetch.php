<?php

declare(strict_types=1);

use OpenHandle\Models\FetchResource;
use OpenHandle\OpenHandle;

function fetchUnknownResource(OpenHandle $openhandle): FetchResource
{
    return $openhandle->fetch('https://www.instagram.com/p/Db04otPRpRH/')->data;
}
