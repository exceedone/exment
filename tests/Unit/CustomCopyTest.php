<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomCopyColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomCopyTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    protected function init()
    {
        System::clearCache();
    }

    /**
     * copy to same table, all columns
     */
    public function testCopySameTableAll()
    {
        $this->init();

        $copy_settings = [
            'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
        ];
        $copy = $this->_prepareCustomCopy($copy_settings);
        $id = array_get($copy, 'id');

        $custom_value = getModelName($copy_settings['from_table_name'])::find(5);
        $response = $copy->execute($custom_value);

        $this->assertTrue(array_get($response, 'result'));
        $this->_compareCopyValue($custom_value, array_get($response, 'redirect'));

        // test delete custom copy setting
        $copy->delete();
        $this->assertNull(CustomCopy::find($id));
        $this->assertCount(0, CustomCopyColumn::where('custom_copy_id', $id)->get());
    }

    /**
     * copy to same table, input some columns
     */
    public function testCopySameTableInput()
    {
        $this->init();

        $copy_settings = [
            'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'copy_columns' => ['text', 'user', 'integer', 'odd_even', 'email'],
            'input_columns' => [
                'date' => '2021-01-17',
                'decimal' => '12345.67',
            ],
        ];
        $copy = $this->_prepareCustomCopy($copy_settings);

        $custom_value = getModelName($copy_settings['from_table_name'])::find(11);

        $response = $copy->execute($custom_value, $copy_settings['input_columns']);

        $this->assertTrue(array_get($response, 'result'));
        $this->_compareCopyValue($custom_value, array_get($response, 'redirect'), $copy_settings);
    }

    /**
     * copy to another table, all columns
     */
    public function testCopyOtherTableAll()
    {
        $this->init();

        $copy_settings = [
            'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'to_table_name' => TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL,
        ];
        $copy = $this->_prepareCustomCopy($copy_settings);

        $custom_value = getModelName($copy_settings['from_table_name'])::find(5);
        $response = $copy->execute($custom_value);

        $this->assertTrue(array_get($response, 'result'));
        $this->_compareCopyValue($custom_value, array_get($response, 'redirect'));
    }

    /**
     * copy to another table, input some columns
     */
    public function testCopyOtherTableInput()
    {
        $this->init();

        $copy_settings = [
            'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'to_table_name' => TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL,
            'copy_columns' => ['index_text', 'date', 'multiples_of_3', 'init_text', 'currency'],
            'input_columns' => [
                'integer' => '1789',
                'email' => 'test123@mail.com',
            ],
        ];
        $copy = $this->_prepareCustomCopy($copy_settings);

        $custom_value = getModelName($copy_settings['from_table_name'])::find(3);
        $response = $copy->execute($custom_value, $copy_settings['input_columns']);

        $this->assertTrue(array_get($response, 'result'));
        $this->_compareCopyValue($custom_value, array_get($response, 'redirect'), $copy_settings);
    }

    /**
     * delete custom table with copy setting
     */
    public function testTableDeleteWithCopy()
    {
        $this->_createSimpleTable('copy_from');
        $this->_createSimpleTable('copy_to');
        $copy_settings = [
            'from_table_name' => 'copy_from',
            'to_table_name' => 'copy_to',
        ];
        $copy = $this->_prepareCustomCopy($copy_settings);
        $id = array_get($copy, 'id');

        // test delete custom table with copy setting
        $res = CustomTable::getEloquent($copy_settings['from_table_name'])->delete();
        $this->assertTrue($res);
        $res = CustomTable::getEloquent($copy_settings['to_table_name'])->delete();
        $this->assertTrue($res);

        $this->assertNull(CustomCopy::find($id));
        $this->assertCount(0, CustomCopyColumn::where('custom_copy_id', $id)->get());
    }

    protected function _compareCopyValue($custom_value, $redirect, $settings = [])
    {
        $path_array = explode('/', $redirect);
        $new_id = end($path_array);
        $table_name = prev($path_array);

        $new_value = getModelName($table_name)::find($new_id);

        foreach ($custom_value->getValues() as $key => $value) {
            if (isset($settings['input_columns']) && array_key_exists($key, $settings['input_columns'])) {
                $this->assertEquals($new_value->getValue($key), $settings['input_columns'][$key]);
            } elseif (!isset($settings['copy_columns']) || in_array($key, $settings['copy_columns'])) {
                $this->assertEquals($value, $new_value->getValue($key));
            } else {
                $this->assertTrue(empty($new_value->getValue($key)));
            }
        }
    }

    protected function _prepareCustomCopy(array $options = [])
    {
        $options = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'to_table_name' => null,
                'copy_columns' => [],
                'input_columns' => [],
            ],
            $options
        );
        $login_user_id = $options['login_user_id'];
        $from_table_name = $options['from_table_name'];
        $to_table_name = $options['to_table_name'];
        $copy_columns = $options['copy_columns'];
        $input_columns = $options['input_columns'];

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($from_table_name);

        if (isset($to_table_name)) {
            $custom_table_to = CustomTable::getEloquent($to_table_name);
        }

        $custom_copy = CustomCopy::create([
            'from_custom_table_id' => $custom_table->id,
            'to_custom_table_id' => isset($custom_table_to) ? $custom_table_to->id : $custom_table->id,
        ]);

        foreach ($custom_table->custom_columns as $custom_column) {
            if (empty($copy_columns) && empty($input_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->_getCustomCopyColumnInfo($custom_copy, $custom_column)
                );
            } elseif (empty($copy_columns) || \in_array($custom_column->column_name, $copy_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->_getCustomCopyColumnInfo($custom_copy, $custom_column)
                );
            } elseif (array_key_exists($custom_column->column_name, $input_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->_getCustomCopyColumnInfo($custom_copy, $custom_column, CopyColumnType::INPUT)
                );
            }
        }

        return $custom_copy;
    }

    /**
     * @param mixed $custom_copy
     * @param mixed $custom_column
     * @param mixed $column_type
     * @return array
     */
    protected function _getCustomCopyColumnInfo($custom_copy, $custom_column, $column_type = CopyColumnType::DEFAULT)
    {
        $copy_column = [
            'custom_copy_id' => $custom_copy->id,
            'from_column_type' => ConditionType::COLUMN,
            'from_column_table_id' => $custom_copy->from_custom_table_id,
            'from_column_target_id' => $custom_column->id,
            'to_column_type' => ConditionType::COLUMN,
            'to_column_table_id' => $custom_copy->to_custom_table_id,
            'to_column_target_id' => $custom_column->id,
            'copy_column_type' => $column_type,
        ];
        if ($custom_copy->from_custom_table_id !== $custom_copy->to_custom_table_id) {
            $to_column = CustomColumn::where('custom_table_id', $custom_copy->to_custom_table_id)
                ->where('column_name', $custom_column->column_name)->first();
            $copy_column['to_column_target_id'] = $to_column->id;
        }
        return $copy_column;
    }
}
