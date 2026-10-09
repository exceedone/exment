<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\IntegerCommaRule;

class IntegerCommaRuleTest extends PureTestBase
{
    public function testIntegerWithCommaPasses(): void
    {
        $this->assertTrue((bool)(new IntegerCommaRule())->passes('attr', '1,234'));
    }

    public function testNegativeIntegerPasses(): void
    {
        $this->assertTrue((bool)(new IntegerCommaRule())->passes('attr', '-100'));
    }

    public function testDecimalFails(): void
    {
        $this->assertFalse((bool)(new IntegerCommaRule())->passes('attr', '1.5'));
    }

    public function testArrayFails(): void
    {
        $this->assertFalse((new IntegerCommaRule())->passes('attr', ['1']));
    }
}
