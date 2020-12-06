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
    
    public function testFloatDigitZero(){
        $this->assertMatch(floorDigit(37, 0, true), '37');
        $this->assertMatch(floorDigit(37, 1, true), '37.0');
        $this->assertMatch(floorDigit(37, 2, true), '37.00');

        $this->assertMatch(floorDigit(37.1, 0, true), '37');
        $this->assertMatch(floorDigit(37.1, 1, true), '37.1');
        $this->assertMatch(floorDigit(37.1, 2, true), '37.10');

        $this->assertMatch(floorDigit(36.3, 0, true), '36');
        $this->assertMatch(floorDigit(36.3, 1, true), '36.3');
        $this->assertMatch(floorDigit(36.3, 2, true), '36.30');

        $this->assertMatch(floorDigit(36.8, 0, true), '36');
        $this->assertMatch(floorDigit(36.8, 1, true), '36.8');
        $this->assertMatch(floorDigit(36.8, 2, true), '36.80');

        $this->assertMatch(floorDigit(36.81, 0, true), '36');
        $this->assertMatch(floorDigit(36.81, 1, true), '36.8');
        $this->assertMatch(floorDigit(36.81, 2, true), '36.81');
        $this->assertMatch(floorDigit(36.81, 3, true), '36.810');

        $this->assertMatch(floorDigit(36.2, 0, true), '36');
        $this->assertMatch(floorDigit(36.2, 1, true), '36.2');
        $this->assertMatch(floorDigit(36.29, 2, true), '36.29');
        $this->assertMatch(floorDigit(36.29, 3, true), '36.290');
    }
    
    public function testFloatDigitZeroMinus(){
        $this->assertMatch(floorDigit(-37, 0, true), '-37');
        $this->assertMatch(floorDigit(-37, 1, true), '-37.0');
        $this->assertMatch(floorDigit(-37, 2, true), '-37.00');

        $this->assertMatch(floorDigit(-37.1, 0, true), '-37');
        $this->assertMatch(floorDigit(-37.1, 1, true), '-37.1');
        $this->assertMatch(floorDigit(-37.1, 2, true), '-37.10');

        $this->assertMatch(floorDigit(-36.3, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.3, 1, true), '-36.3');
        $this->assertMatch(floorDigit(-36.3, 2, true), '-36.30');

        $this->assertMatch(floorDigit(-36.8, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.8, 1, true), '-36.8');
        $this->assertMatch(floorDigit(-36.8, 2, true), '-36.80');

        $this->assertMatch(floorDigit(-36.81, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.81, 1, true), '-36.8');
        $this->assertMatch(floorDigit(-36.81, 2, true), '-36.81');
        $this->assertMatch(floorDigit(-36.81, 3, true), '-36.810');

        $this->assertMatch(floorDigit(-36.2, 0, true), '-36');
        $this->assertMatch(floorDigit(-36.2, 1, true), '-36.2');
        $this->assertMatch(floorDigit(-36.29, 2, true), '-36.29');
        $this->assertMatch(floorDigit(-36.29, 3, true), '-36.290');
    }
}
