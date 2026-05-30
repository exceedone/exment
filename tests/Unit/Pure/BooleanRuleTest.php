<?php

namespace Exceedone\Exment\Tests\Unit\Pure;

use Exceedone\Exment\Validator\BooleanRule;

class BooleanRuleTest extends PureTestBase
{
    /**
     * Mirrors the real option shape built by Boolean::getImportValueOption():
     * [ storedValue => displayLabel, ... ]. passes() accepts either the key
     * (stored value) or the value (label).
     *
     * @return BooleanRule
     */
    private function rule(): BooleanRule
    {
        /** @var array<string, mixed> $option */
        $option = ['0' => 'No', '1' => 'Yes'];

        return new BooleanRule($option);
    }

    public function testNullPasses(): void
    {
        $this->assertTrue($this->rule()->passes('attr', null));
    }

    public function testStoredValueMatchesKey(): void
    {
        $this->assertTrue($this->rule()->passes('attr', '0'));
        $this->assertTrue($this->rule()->passes('attr', '1'));
    }

    public function testLabelMatchesValue(): void
    {
        $this->assertTrue($this->rule()->passes('attr', 'No'));
        $this->assertTrue($this->rule()->passes('attr', 'Yes'));
    }

    public function testUnknownValueFails(): void
    {
        $this->assertFalse($this->rule()->passes('attr', 'maybe'));
    }
}
