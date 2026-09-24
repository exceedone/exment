<?php

namespace Exceedone\Exment\Services\Meili;

use GuzzleHttp\Client as GuzzleClient;
use Meilisearch\Client;

/**
 * Create a Meilisearch client from config/meilisearch.php.
 *
 * Pass a Guzzle client with short timeouts: if Meili is down, the request fails fast so the
 * controller falls back to MySQL immediately (no hanging the user for the ~default few-dozen seconds).
 */
class MeiliClientFactory
{
    public static function make(): Client
    {
        $http = new GuzzleClient([
            'connect_timeout' => (float) config('meilisearch.connect_timeout', 1.0),
            'timeout'         => (float) config('meilisearch.timeout', 5.0),
        ]);

        return new Client(
            config('meilisearch.host'),
            config('meilisearch.key'),
            $http
        );
    }
}
