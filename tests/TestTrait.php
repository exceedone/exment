<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Model\System;

trait TestTrait
{
    protected function assertMatch($value1, $value2){
        $this->assertTrue($value1 == $value2, "value1 is $value1, but value2 is $value2");

        return $this;
    }

    /**
     * Initialize all test
     *
     * @return void
     */
    protected function initAllTest(){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }
}
