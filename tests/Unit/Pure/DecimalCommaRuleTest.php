<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\DecimalCommaRule;

class DecimalCommaRuleTest extends PureTestBase
{
    public function testDecimalWithCommaPasses(): void
    {
        $this->assertTrue((bool)(new DecimalCommaRule())->passes('attr', '1,234.56'));
    }

    public function testNegativeDecimalPasses(): void
    {
        $this->assertTrue((bool)(new DecimalCommaRule())->passes('attr', '-12.3'));
    }

    public function testAlphabeticFails(): void
    {
        $this->assertFalse((bool)(new DecimalCommaRule())->passes('attr', 'abc'));
    }

    public function testArrayFails(): void
    {
        $this->assertFalse((new DecimalCommaRule())->passes('attr', ['1.0']));
    }
}
