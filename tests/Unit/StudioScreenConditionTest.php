<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Studio\ScreenCondition;

/**
 * The condition an item of a Studio screen is shown on (ISS00231 phase 3).
 */
class StudioScreenConditionTest extends UnitTestBase
{
    public function testTheConditionsAreReadByTheIndexOfTheirItem(): void
    {
        $conditions = ScreenCondition::normalize([
            ['label' => 'status'],
            ['label' => 'memo', 'visible_if' => ['target' => '0', 'operator' => 'eq', 'value' => ' 休止 ']],
            ['label' => 'other', 'visible_if' => ['target' => null]],
        ]);

        $this->assertSame([1 => ['target' => 0, 'operator' => 'eq', 'value' => '休止']], $conditions);
    }

    /**
     * The keys of the mistakes of one condition of the item at $index (items 0 and 1 optional, 2 required).
     *
     * @return array<int, string>
     */
    protected function errorOf(int $target, string $operator, string $value, int $index = 1): array
    {
        $conditions = [$index => ['target' => $target, 'operator' => $operator, 'value' => $value]];
        return array_keys(ScreenCondition::validate($conditions, [0 => false, 1 => false, 2 => true]));
    }

    public function testMistakes(): void
    {
        $this->assertSame([], $this->errorOf(0, 'eq', 'a'));
        $this->assertSame([], $this->errorOf(0, 'empty', ''));

        // on itself, or on an item that is not there
        $this->assertSame(['fields.1.visible_if'], $this->errorOf(1, 'eq', 'a'));
        $this->assertSame(['fields.1.visible_if'], $this->errorOf(9, 'eq', 'a'));
        // an operator not offered, a value missing
        $this->assertSame(['fields.1.visible_if'], $this->errorOf(0, 'gt', '1'));
        $this->assertSame(['fields.1.visible_if'], $this->errorOf(0, 'ne', ''));
        // a required item: hidden, it could never be filled in
        $this->assertSame(['fields.2.visible_if'], $this->errorOf(0, 'not_empty', '', 2));
    }

    public function testTheConditionsAreGivenWithTheirKeysInOrder(): void
    {
        // as MySQL gives a JSON column back: the keys sorted by length
        $stored = ['all' => [['value' => 1, 'column' => 'status', 'operator' => 'eq']]];
        $this->assertSame([['column' => 'status', 'operator' => 'eq', 'value' => '1']], ScreenCondition::conditionsOf($stored));
        $this->assertSame(['column', 'operator', 'value'], array_keys(ScreenCondition::conditionsOf($stored)[0]));
        $this->assertNull(ScreenCondition::conditionsOf(null));
        $this->assertNull(ScreenCondition::conditionsOf(['any' => []]));
    }

    public function testTheShapeKeptAndGiven(): void
    {
        $this->assertSame(['all' => [['column' => 'status', 'operator' => 'eq', 'value' => '休止']]], ScreenCondition::shape('status', 'eq', '休止'));
        // an operator without a value keeps none
        $this->assertSame(['all' => [['column' => 'status', 'operator' => 'empty', 'value' => null]]], ScreenCondition::shape('status', 'empty', 'x'));
    }
}
