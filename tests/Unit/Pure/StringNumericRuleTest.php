<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\StringNumericRule;

class StringNumericRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new StringNumericRule())->passes('attr', null));
    }

    public function testStringPasses(): void
    {
        $this->assertTrue((new StringNumericRule())->passes('attr', 'abc'));
    }

    public function testNumericPasses(): void
    {
        $this->assertTrue((new StringNumericRule())->passes('attr', 123));
    }

    public function testArrayFails(): void
    {
        $this->assertFalse((new StringNumericRule())->passes('attr', ['a']));
    }
}
