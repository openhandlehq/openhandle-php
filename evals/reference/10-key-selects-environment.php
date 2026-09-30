<?php

declare(strict_types=1);

use OpenHandle\OpenHandle;

function createClient(string $apiKey): OpenHandle
{
    return new OpenHandle(apiKey: $apiKey);
}
