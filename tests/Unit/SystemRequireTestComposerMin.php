<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult\Composer;

/**
 * For test check require, version is 1.9.2
 */
class SystemRequireTestComposerMin extends Composer
{
    protected function getComposerVersion(){
        return "1.9.2";
    }
}
