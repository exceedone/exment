<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;
use Exceedone\Exment\Enums\SystemTableName;

trait CommandTrait
{
    protected function initExmentCommand()
    {
        Middleware\Morph::defineMorphMap();

        $dbSetting = canConnection() && hasTable(SystemTableName::SYSTEM);
        Middleware\Initialize::initializeConfig($dbSetting);
    }
}
