<?php

namespace Exceedone\Exment\Tests;

trait TestTrait
{
    protected function assertMatch($value1, $value2){
        $this->assertTrue($value1 == $value2, "value1 is $value1, but value2 is $value2");

        return $this;
    }
}
