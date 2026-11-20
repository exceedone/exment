<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;

/**
 * The formula of a calculated item of the Studio builder: the names of the
 * items in brackets become the columns Exment calculates with.
 */
class StudioCalcFormulaTest extends UnitTestBase
{
    /**
     * @return array<int, array{label: string, column_name: string, type: string}>
     */
    protected function fields(): array
    {
        return [
            ['label' => '数量', 'column_name' => 'quantity', 'type' => ColumnType::INTEGER],
            ['label' => '単価', 'column_name' => 'price', 'type' => ColumnType::CURRENCY],
            ['label' => '金額', 'column_name' => 'amount', 'type' => BlankFormBuilder::CALC],
            ['label' => 'メモ', 'column_name' => 'memo', 'type' => ColumnType::TEXT],
            ['label' => '重複', 'column_name' => 'dup1', 'type' => ColumnType::INTEGER],
            ['label' => '重複', 'column_name' => 'dup2', 'type' => ColumnType::INTEGER],
        ];
    }

    public function testTheNamesBecomeColumns(): void
    {
        $this->assertSame(['${value:quantity} * ${value:price}', null], BlankFormBuilder::calcFormula('[数量] * [単価]', 2, $this->fields()));
        $this->assertSame(['(${value:quantity} + 1) * 1.1', null], BlankFormBuilder::calcFormula(' ([数量] + 1) * 1.1 ', 2, $this->fields()));
    }

    public function testMistakes(): void
    {
        $fields = $this->fields();
        $this->assertSame([null, 'formula_empty'], BlankFormBuilder::calcFormula('', 2, $fields));
        // an item that is not there, itself, a name two items have
        $this->assertSame([null, 'formula_item'], BlankFormBuilder::calcFormula('[税率] * 2', 2, $fields));
        $this->assertSame([null, 'formula_item'], BlankFormBuilder::calcFormula('[金額] + 1', 2, $fields));
        $this->assertSame([null, 'formula_item'], BlankFormBuilder::calcFormula('[重複] + 1', 2, $fields));
        // an item that is not a number
        $this->assertSame([null, 'formula_number'], BlankFormBuilder::calcFormula('[メモ] + 1', 2, $fields));
        // anything else than numbers and + - * / ( )
        $this->assertSame([null, 'formula_symbol'], BlankFormBuilder::calcFormula('[数量] ^ 2', 2, $fields));
        $this->assertSame([null, 'formula_symbol'], BlankFormBuilder::calcFormula('${count:other}', 2, $fields));
    }
}
