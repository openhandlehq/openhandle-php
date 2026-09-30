<?php

declare(strict_types=1);

namespace OpenHandle;

use GuzzleHttp\ClientInterface;
use OpenHandle\Internal\Transport;
use OpenHandle\Resources\GeneratedClient;

/**
 * A reusable OpenHandle client.
 *
 * The API key selects the Test or Live environment; there is no separate
 * environment option. Never expose an API key in client-side code.
 */
final class OpenHandle extends GeneratedClient
{
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        float $timeout = Transport::DEFAULT_TIMEOUT,
        int $maxRetries = Transport::DEFAULT_MAX_RETRIES,
        ?ClientInterface $httpClient = null,
    ) {
        parent::__construct(new Transport($apiKey, $baseUrl, $timeout, $maxRetries, $httpClient));
    }

    /**
     * The installed SDK version, or 0.0.0 outside a tagged Composer install.
     */
    public static function version(): string
    {
        return Transport::version();
    }
}
