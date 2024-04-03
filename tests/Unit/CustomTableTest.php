<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums\SystemColumn;

class CustomTableTest extends UnitTestBase
{
    public function testFuncGetMatchedCustomValues1()
    {
        $info = CustomTable::getEloquent('information');

        $keys = [1,3,5];
        $values = $info->getMatchedCustomValues($keys);

        foreach ($keys as $key) {
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'id') == $key);
        }

        foreach ([2, 4] as $key) {
            $this->assertTrue(!array_has($values, $key));
        }
    }

    public function testFuncGetMatchedCustomValues2()
    {
        $info = CustomTable::getEloquent('information');

        $keys = ['3'];
        $values = $info->getMatchedCustomValues($keys, 'value.priority');

        foreach ($keys as $key) {
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'value.priority') == $key);
        }

        foreach (['2', '4'] as $key) {
            $this->assertTrue(!array_has($values, $key));
        }
    }

    public function testFuncCopyCustomTable()
    {
        $from_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $response = $from_table->copyTable([
            'table_name' => 'copy_table',
            'table_view_name' => 'コピーテーブル',
        ]);
        $this->assertTrue(is_array($response));
        $this->assertTrue(array_get($response, 'result'));

        // compare custom table
        $to_table = CustomTable::getEloquent('copy_table');
        $this->assertTrue($to_table instanceof CustomTable);
        $diff = collect($to_table->getAttributes())->diffAssoc(collect($from_table->getAttributes()));
        foreach ($diff as $key => $value) {
            switch ($key) {
                case 'table_name':
                    $this->assertEquals($value, 'copy_table');
                    break;
                case 'table_view_name':
                    $this->assertEquals($value, 'コピーテーブル');
                    break;
                default:
                    $option = SystemColumn::getOption(['sqlname' => $key]);
                    $this->assertTrue(is_array($option));
                    break;
            }
        }

        // compare custom column
        $this->assertEquals($to_table->custom_columns_cache->count(), $from_table->custom_columns_cache->count());
        foreach ($to_table->custom_columns_cache as $to_column) {
            $from_column = $from_table->custom_columns_cache->filter(function($column) use($to_column){
                return $column->column_name == $to_column->column_name;
            })->first();
            $diff = collect($to_column->getAttributes())->diffAssoc(collect($from_column->getAttributes()));
            foreach ($diff as $key => $value) {
                switch ($key) {
                    case 'custom_table_id':
                        $this->assertEquals($value, $to_table->id);
                        break;
                    default:
                        $option = SystemColumn::getOption(['sqlname' => $key]);
                        $this->assertTrue(is_array($option));
                        break;
                }
            }
        }

        // compare custom column multisettings
        $this->assertEquals($to_table->custom_column_multisettings->count(), $from_table->custom_column_multisettings->count());
        foreach ($to_table->custom_column_multisettings as $to_column) {
            $from_column = $from_table->custom_column_multisettings->filter(function($column) use($to_column){
                return $column->multisetting_type == $to_column->multisetting_type &&
                    $column->priority == $to_column->priority;
            })->first();
            $this->assertEquals($to_column->custom_table_id, $to_table->id);
            foreach ($from_column->options as $key => $value) {
                $to_value = array_get($to_column->options, $key);
                switch ($key) {
                    case 'table_label_id':
                    case 'unique1_id':
                    case 'unique2_id':
                    case 'unique3_id':
                    case 'share_column_id':
                    case 'compare_column1_id':
                    case 'compare_column2_id':
                        $from = $from_table->custom_columns_cache->filter(function($column) use($value){
                            return $column->id == $value;
                        })->first();
                        $to = $to_table->custom_columns_cache->filter(function($column) use($to_value){
                            return $column->id == $to_value;
                        })->first();
            
                        $this->assertEquals($from->column_name, $to->column_name);
                        break;
                    default:
                        $this->assertEquals($value, $to_value);
                        break;
                }
            }
        }
        $to_table->delete();
    }
}
