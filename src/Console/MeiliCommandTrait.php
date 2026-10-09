<?php

namespace Exceedone\Exment\Console;

/**
 * Shared guards for the meili:* commands: the Meilisearch SDK is an optional
 * (suggest) dependency and the server may be down, so a friendly error beats a
 * "class not found" fatal or a raw HTTP stack trace in the middle of a command.
 */
trait MeiliCommandTrait
{
    protected function assertMeiliSdkInstalled(): bool
    {
        if (class_exists(\Meilisearch\Client::class)) {
            return true;
        }

        $this->error('meilisearch/meilisearch-php is not installed. Run "composer require meilisearch/meilisearch-php" to use this command.');

        return false;
    }

    /**
     * A server that answers but does not report itself available is no more
     * usable than one that is down, so both end the command the same way.
     *
     * @param \Meilisearch\Client $client
     */
    protected function assertMeiliReachable($client): bool
    {
        try {
            $health = $client->health();
        } catch (\Throwable $e) {
            $this->error('Could not connect to Meilisearch (' . config('meilisearch.host') . '): ' . $e->getMessage());

            return false;
        }

        if (($health['status'] ?? null) !== 'available') {
            $this->error('Meilisearch is not available: ' . json_encode($health));

            return false;
        }

        return true;
    }
}
