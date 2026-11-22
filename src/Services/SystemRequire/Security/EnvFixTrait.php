<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Services\EnvService;

/**
 * A fix that writes a line of .env. The config cache, when there is one, would keep
 * the old value: it is removed (the tuning page makes it again).
 */
trait EnvFixTrait
{
    protected function envWritable(): bool
    {
        return is_writable(path_join(base_path(), '.env'));
    }

    /**
     * @param array<string, string> $values
     */
    protected function writeEnv(array $values): void
    {
        EnvService::setEnv($values);
        if (app()->configurationIsCached()) {
            @unlink(app()->getCachedConfigPath());
        }
    }
}
