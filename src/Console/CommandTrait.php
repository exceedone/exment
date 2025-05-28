<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;
use Exceedone\Exment\Enums\SystemTableName;

trait CommandTrait
{
    /**
     * @return void
     */
    protected function initExmentCommand()
    {
        Middleware\Morph::defineMorphMap();

        $dbSetting = canConnection() && hasTable(SystemTableName::SYSTEM);
        Middleware\Initialize::initializeConfig($dbSetting);
    }
}
