<?php

namespace Exceedone\Exment\Console;

/**
 * Shared guard for meili:* commands: the Meilisearch SDK is an optional
 * (suggest) dependency, so a friendly error beats a "class not found" fatal
 * when the command runs on an install without it.
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
}
