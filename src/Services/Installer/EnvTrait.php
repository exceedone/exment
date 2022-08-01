<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Services\EnvService;

/**
 * OLD : LoginService
 */
trait EnvTrait
{
    protected function setEnv($data = [], $matchRemove = false)
    {
        return EnvService::setEnv($data, $matchRemove);
    }

    protected function removeEnv($data = [])
    {
        return EnvService::removeEnv($data);
    }

    protected function getEnv($key, $path = null, $matchPrefix = false)
    {
        return EnvService::getEnv($key, $path, $matchPrefix);
    }
}
