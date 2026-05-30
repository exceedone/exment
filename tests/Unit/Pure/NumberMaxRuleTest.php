<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\NumberMaxRule;

class NumberMaxRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new NumberMaxRule(100))->passes('attr', null));
    }

    public function testNonNumericPasses(): void
    {
        $this->assertTrue((new NumberMaxRule(100))->passes('attr', 'abc'));
    }

    public function testValueWithCommaUnderMaxPasses(): void
    {
        $this->assertTrue((new NumberMaxRule(2000))->passes('attr', '1,500'));
    }

    public function testValueOverMaxFails(): void
    {
        $this->assertFalse((new NumberMaxRule(100))->passes('attr', '101'));
    }

    public function testValueEqualMaxPasses(): void
    {
        $this->assertTrue((new NumberMaxRule(100))->passes('attr', '100'));
    }
}
