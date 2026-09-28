<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\MaxLengthExRule;

class MaxLengthExRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new MaxLengthExRule(5))->passes('attr', null));
    }

    public function testUnderMaxPasses(): void
    {
        $this->assertTrue((new MaxLengthExRule(5))->passes('attr', 'abc'));
    }

    public function testEqualMaxPasses(): void
    {
        $this->assertTrue((new MaxLengthExRule(3))->passes('attr', 'abc'));
    }

    public function testOverMaxFails(): void
    {
        $this->assertFalse((new MaxLengthExRule(3))->passes('attr', 'abcd'));
    }

    public function testCrlfCountedAsSingleChar(): void
    {
        // "a\r\nb" -> normalized to "a\nb" = 3 chars
        $this->assertTrue((new MaxLengthExRule(3))->passes('attr', "a\r\nb"));
    }
}
