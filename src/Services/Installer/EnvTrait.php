<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Services\EnvService;

/**
 * OLD : LoginService
 */
trait EnvTrait
{
    // @phpstan-ignore-next-line
    protected function setEnv($data = [], $matchRemove = false)
    {
        return EnvService::setEnv($data, $matchRemove);
    }

    // @phpstan-ignore-next-line
    protected function removeEnv($data = [])
    {
        return EnvService::removeEnv($data);
    }

    // @phpstan-ignore-next-line
    protected function getEnv($key, $path = null, $matchPrefix = false)
    {
        return EnvService::getEnv($key, $path, $matchPrefix);
    }
}
