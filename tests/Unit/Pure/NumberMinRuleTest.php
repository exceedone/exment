<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\NumberMinRule;

class NumberMinRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new NumberMinRule(0))->passes('attr', null));
    }

    public function testNonNumericPasses(): void
    {
        $this->assertTrue((new NumberMinRule(0))->passes('attr', 'abc'));
    }

    public function testValueWithCommaOverMinPasses(): void
    {
        $this->assertTrue((new NumberMinRule(1000))->passes('attr', '1,500'));
    }

    public function testValueUnderMinFails(): void
    {
        $this->assertFalse((new NumberMinRule(100))->passes('attr', '99'));
    }

    public function testValueEqualMinPasses(): void
    {
        $this->assertTrue((new NumberMinRule(100))->passes('attr', '100'));
    }
}
