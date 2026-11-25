<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomCopyColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Calc\CalcEvaluator;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;

/**
 * The calc columns of a record made by a data copy (ISS02870): the input dialog and the
 * "copy from a record" screen have no calc of the form, so the server works them out from
 * the values copied and typed.
 *
 * custom_value_edit_all of the test data has the columns integer, decimal and currency; the
 * test gives currency the formula "integer * 2 + decimal". Everything is rolled back.
 */
class CustomCopyCalcTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->formula('currency', '${value:integer} * 2 + ${value:decimal}');
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertInstanceOf(CustomTable::class, $table);
        return $table;
    }

    protected function column(string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, $this->table());
        $this->assertInstanceOf(CustomColumn::class, $column);
        return $column;
    }

    protected function formula(string $column_name, string $formula): void
    {
        $column = $this->column($column_name);
        $column->setOption('calc_formula', $formula);
        $column->save();
        System::clearCache();
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function record(array $values): CustomValue
    {
        $record = $this->table()->getValueModel();
        $record->setValue(array_merge(['text' => 'calc 2870'], $values));
        $record->save();
        return $record;
    }

    /**
     * A copy into the same table: the columns copied, and the ones asked in the dialog.
     *
     * @param array<int, string> $copied
     * @param array<int, string> $asked
     */
    protected function copy(array $copied, array $asked, bool $use_on_create = false): CustomCopy
    {
        $table = $this->table();
        $copy = CustomCopy::create([
            'from_custom_table_id' => $table->id,
            'to_custom_table_id' => $table->id,
            'options' => ['use_on_create' => $use_on_create ? '1' : '0'],
        ]);
        foreach ([CopyColumnType::DEFAULT => $copied, CopyColumnType::INPUT => $asked] as $type => $names) {
            foreach ($names as $name) {
                $column = $this->column($name);
                CustomCopyColumn::create([
                    'custom_copy_id' => $copy->id,
                    'from_column_type' => ConditionType::COLUMN,
                    'from_column_table_id' => $table->id,
                    'from_column_target_id' => $column->id,
                    'to_column_type' => ConditionType::COLUMN,
                    'to_column_table_id' => $table->id,
                    'to_column_target_id' => $column->id,
                    'copy_column_type' => $type,
                ]);
            }
        }
        $copy = CustomCopy::find($copy->id);
        $this->assertInstanceOf(CustomCopy::class, $copy);
        return $copy;
    }

    /**
     * @param array<string, mixed> $response the answer of CustomCopy::execute
     */
    protected function made(array $response): CustomValue
    {
        $this->assertTrue(array_get($response, 'result'));
        $id = intval(basename(strval(array_get($response, 'redirect'))));
        System::clearRequestSession();
        $made = getModelName($this->table())::find($id);
        $this->assertInstanceOf(CustomValue::class, $made);
        return $made;
    }

    public function testTheDialogWorksOutTheCalcColumnFromTheValuesTyped(): void
    {
        $from = $this->record(['integer' => 1, 'decimal' => 1, 'currency' => 3]);
        $copy = $this->copy(['text'], ['integer', 'decimal']);
        $made = $this->made($copy->execute($from, ['integer' => '10', 'decimal' => '1.5']));
        $this->assertEquals(21.5, floatval($made->getValue('currency')));
        $this->assertEquals(10, intval($made->getValue('integer')));
    }

    public function testTheValuesCopiedAreWorkedOutToo(): void
    {
        // the record copied has a result that is not the formula's any more
        $from = $this->record(['integer' => 7, 'decimal' => 0.25, 'currency' => 999]);
        $copy = $this->copy(['text', 'integer', 'decimal', 'currency'], []);
        $made = $this->made($copy->execute($from, null));
        $this->assertEquals(14.25, floatval($made->getValue('currency')));
    }

    public function testAnEmptyValueLeavesTheColumnAsTheFormWould(): void
    {
        // calc.ts does not work out a formula one of whose values is empty
        $from = $this->record(['integer' => 3, 'currency' => 5]);
        $copy = $this->copy(['text', 'integer', 'currency'], ['decimal']);
        $made = $this->made($copy->execute($from, ['decimal' => null]));
        $this->assertEquals(5, floatval($made->getValue('currency')));
    }

    public function testAnIntegerColumnTakesTheRoundedResult(): void
    {
        $this->formula('integer', '${value:decimal} * 3');
        $from = $this->record(['decimal' => 1.25]);
        $copy = $this->copy(['text', 'decimal'], []);
        $made = $this->made($copy->execute($from, null));
        // 3.75 -> 4, and currency from it: 4 * 2 + 1.25
        $this->assertSame(4, intval($made->getValue('integer')));
        $this->assertEquals(9.25, floatval($made->getValue('currency')));
    }

    public function testTheCopyFromScreenShowsTheWorkedOutValue(): void
    {
        $from = $this->record(['integer' => 2, 'decimal' => 0.5, 'currency' => 0]);
        $copy = $this->copy(['text', 'integer', 'decimal'], [], true);
        $model = $copy->makeNewModel($from);
        $this->assertFalse($model->exists, 'nothing is saved');
        $this->assertEquals(4.5, floatval(array_get($model->value, 'currency')));
    }

    public function testTheArithmetic(): void
    {
        $this->assertSame(7.0, CalcEvaluator::calculate('1 + 2 * 3'));
        $this->assertSame(9.0, CalcEvaluator::calculate('(1 + 2) * 3'));
        $this->assertSame(-1.5, CalcEvaluator::calculate('-(3) / 2'));
        $this->assertSame(2.0, CalcEvaluator::calculate('(4) - (-(2)) - 4'));
        $this->assertSame(0.5, CalcEvaluator::calculate('.5'));
        // a division by zero, a bracket left open, anything but numbers and + - * / ( ): nothing
        $this->assertNull(CalcEvaluator::calculate('1 / 0'));
        $this->assertNull(CalcEvaluator::calculate('(1 + 2'));
        $this->assertNull(CalcEvaluator::calculate('1 +'));
        $this->assertNull(CalcEvaluator::calculate('phpinfo()'));
        $this->assertNull(CalcEvaluator::calculate('2 ** 3'));
        $this->assertNull(CalcEvaluator::calculate(''));
    }
}
