<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;

/**
 * Test cases: auto_number_type "other" option is removed from the option form
 * (it had no value-generation logic, always produced empty value).
 * - Valid types (format, random25, random32) still generate values (regression).
 * - Legacy columns saved with auto_number_type=other do not throw and generate no value.
 * *The option form itself (select not containing "other") is tested on
 *  CustomColumnUniqueFilterFormTest via columnTypeHtml endpoint.
 */
class AutoNumberOptionTest extends UnitTestBase
{
    use DatabaseTransactions;

    public const TARGET_TABLE = 'information';

    /**
     * Create and save auto number column to TARGET_TABLE, and clear cache.
     *
     * @param string $column_name
     * @param array<string, mixed> $options
     * @return CustomColumn
     */
    protected function createAutoNumberColumn($column_name, $options = [])
    {
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        $custom_column = new CustomColumn([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name,
            'column_view_name' => $column_name,
            'column_type' => ColumnType::AUTO_NUMBER,
            'options' => $options,
        ]);
        $custom_column->save();

        // clear cache, so custom_columns_cache on saving custom value contains the new column
        System::clearCache();

        return $custom_column;
    }

    /**
     * Create and save custom value to TARGET_TABLE.
     *
     * @return mixed CustomValue
     */
    protected function createValue()
    {
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        $custom_value = $custom_table->getValueModel();
        $custom_value->save();

        return $custom_value;
    }

    /**
     * auto_number_type=format still generates value.
     *
     * @return void
     */
    public function testFormatTypeGeneratesValue()
    {
        $this->createAutoNumberColumn('utest_an_format', [
            'auto_number_type' => 'format',
            'auto_number_format' => 'UTEST-FIXED-FORMAT',
        ]);

        $custom_value = $this->createValue();

        $this->assertMatch($custom_value->getValue('utest_an_format'), 'UTEST-FIXED-FORMAT');
    }

    /**
     * auto_number_type=random25 still generates license-code style value.
     *
     * @return void
     */
    public function testRandom25TypeGeneratesValue()
    {
        $this->createAutoNumberColumn('utest_an_random25', [
            'auto_number_type' => 'random25',
        ]);

        $custom_value = $this->createValue();

        $value = $custom_value->getValue('utest_an_random25');
        $this->assertMatchesRegularExpression('/^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$/', $value ?? '', 'random25 value is not generated.');
    }

    /**
     * auto_number_type=random32 still generates uuid value.
     *
     * @return void
     */
    public function testRandom32TypeGeneratesValue()
    {
        $this->createAutoNumberColumn('utest_an_random32', [
            'auto_number_type' => 'random32',
        ]);

        $custom_value = $this->createValue();

        $value = $custom_value->getValue('utest_an_random32');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ?? '', 'random32 value is not generated.');
    }

    /**
     * Legacy column saved with auto_number_type=other (selectable before this fix):
     * saving custom value does not throw, and no auto number value is generated.
     *
     * @return void
     */
    public function testLegacyOtherTypeGeneratesNoValue()
    {
        $this->createAutoNumberColumn('utest_an_other', [
            'auto_number_type' => 'other',
        ]);

        $custom_value = $this->createValue();

        $this->assertTrue(is_nullorempty($custom_value->getValue('utest_an_other')), 'auto_number_type=other generated a value unexpectedly.');
    }
}
