<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * Not belongs test
 */
class SomeTest extends UnitTestBase
{
    public function testFloatDigit(){
        $this->assertMatch(floorDigit(37, 0), 37);
        $this->assertMatch(floorDigit(37, 1), 37);
        $this->assertMatch(floorDigit(37, 2), 37);

        $this->assertMatch(floorDigit(37.1, 0), 37);
        $this->assertMatch(floorDigit(37.1, 1), 37.1);
        $this->assertMatch(floorDigit(37.1, 2), 37.1);

        $this->assertMatch(floorDigit(36.3, 0), 36);
        $this->assertMatch(floorDigit(36.3, 1), 36.3);
        $this->assertMatch(floorDigit(36.3, 2), 36.3);

        $this->assertMatch(floorDigit(36.8, 0), 36);
        $this->assertMatch(floorDigit(36.8, 1), 36.8);
        $this->assertMatch(floorDigit(36.8, 2), 36.8);

        $this->assertMatch(floorDigit(36.81, 0), 36);
        $this->assertMatch(floorDigit(36.81, 1), 36.8);
        $this->assertMatch(floorDigit(36.81, 2), 36.81);
        $this->assertMatch(floorDigit(36.81, 3), 36.81);

        $this->assertMatch(floorDigit(36.2, 0), 36);
        $this->assertMatch(floorDigit(36.2, 1), 36.2);
        $this->assertMatch(floorDigit(36.29, 2), 36.29);
        $this->assertMatch(floorDigit(36.29, 3), 36.29);
    }
    
    public function testFloatDigitMinus(){
        $this->assertMatch(floorDigit(-37, 0), -37);
        $this->assertMatch(floorDigit(-37, 1), -37);
        $this->assertMatch(floorDigit(-37, 2), -37);

        $this->assertMatch(floorDigit(-37.1, 0), -37);
        $this->assertMatch(floorDigit(-37.1, 1), -37.1);
        $this->assertMatch(floorDigit(-37.1, 2), -37.1);

        $this->assertMatch(floorDigit(-36.3, 0), -36);
        $this->assertMatch(floorDigit(-36.3, 1), -36.3);
        $this->assertMatch(floorDigit(-36.3, 2), -36.3);

        $this->assertMatch(floorDigit(-36.8, 0), -36);
        $this->assertMatch(floorDigit(-36.8, 1), -36.8);
        $this->assertMatch(floorDigit(-36.8, 2), -36.8);

        $this->assertMatch(floorDigit(-36.81, 0), -36);
        $this->assertMatch(floorDigit(-36.81, 1), -36.8);
        $this->assertMatch(floorDigit(-36.81, 2), -36.81);
        $this->assertMatch(floorDigit(-36.81, 3), -36.81);

        $this->assertMatch(floorDigit(-36.2, 0), -36);
        $this->assertMatch(floorDigit(-36.2, 1), -36.2);
        $this->assertMatch(floorDigit(-36.29, 2), -36.29);
        $this->assertMatch(floorDigit(-36.29, 3), -36.29);
    }
}
