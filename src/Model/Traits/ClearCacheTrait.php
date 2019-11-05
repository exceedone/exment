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
    public function clearCache()
    {
        System::resetCache();
    }
}
