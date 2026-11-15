<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\YesNoRule;

class YesNoRuleTest extends PureTestBase
{
    public function testNullPasses(): void
    {
        $this->assertTrue((new YesNoRule())->passes('attr', null));
    }

    public function testBooleanPasses(): void
    {
        $this->assertTrue((new YesNoRule())->passes('attr', true));
        $this->assertTrue((new YesNoRule())->passes('attr', false));
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testAcceptedStringsPass(string $value): void
    {
        $this->assertTrue((new YesNoRule())->passes('attr', $value));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function acceptedProvider(): array
    {
        return [['0'], ['1'], ['yes'], ['no'], ['YES'], ['NO'], ['true'], ['false']];
    }

    public function testUnknownValueFails(): void
    {
        $this->assertFalse((new YesNoRule())->passes('attr', 'maybe'));
    }
}
