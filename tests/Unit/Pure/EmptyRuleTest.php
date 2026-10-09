<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\EmptyRule;

class EmptyRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new EmptyRule())->passes('attr', null));
    }

    public function testEmptyStringPasses(): void
    {
        $this->assertTrue((new EmptyRule())->passes('attr', ''));
    }

    public function testZeroPasses(): void
    {
        $this->assertTrue((new EmptyRule())->passes('attr', '0'));
    }

    public function testNonEmptyFails(): void
    {
        $this->assertFalse((new EmptyRule())->passes('attr', 'abc'));
    }
}
