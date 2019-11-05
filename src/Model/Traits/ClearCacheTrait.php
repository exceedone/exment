<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\System;

trait ClearCacheTrait
{
    /**
     * Clear cache
     *
     * @return void
     */
    public static function clearCacheTrait()
    {
        System::clearCache();
    }
}
