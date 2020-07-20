<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Model\System;

trait TestTrait
{
    /**
     * Assert that the response is a superset of the given JSON.
     *
     * @param  array  $data
     * @param  bool  $strict
     * @return $this
     */
    public function assertJsonExment(array $data1, $data2, $strict = false)
    {
        \PHPUnit\Framework\Assert::assertArraySubset(
            $data1, $data2, $strict
        );

        return $this;
    }

    protected function assertMatch($value1, $value2){
        $isMatch = false;

        $messageV1 = is_array($value1) ? json_encode($value1) : $value1;
        $messageV2 = is_array($value2) ? json_encode($value2) : $value2;
        $this->assertTrue($value1 == $value2, "value1 is $messageV1, but value2 is $messageV2");

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
