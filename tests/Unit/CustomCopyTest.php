<?php
namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\DB;
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
    protected function init(){
        System::clearCache();
    }

    /**
     * copy to same table, all columns
     */
    public function testCopySameTableAll()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $copy_settings = [
                'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            ];
            $copy = $this->prepareCustomCopy($copy_settings);

            $custom_value = getModelName($copy_settings['from_table_name'])::find(5);
            $response = $copy->execute($custom_value);

            $this->assertTrue(array_get($response, 'result'));
            $this->compareCopyValue($custom_value, array_get($response, 'redirect'));
        } finally {
            DB::rollback();
        }
    }

    /**
     * copy to same table, input some columns
     */
    public function testCopySameTableInput()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $copy_settings = [
                'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'copy_columns' => ['text', 'user', 'integer', 'odd_even', 'email'],
                'input_columns' => [
                    'date' => '2021-01-17',
                    'decimal' => '12345.67',
                ],
            ];
            $copy = $this->prepareCustomCopy($copy_settings);

            $custom_value = getModelName($copy_settings['from_table_name'])::find(11);

            $response = $copy->execute($custom_value, $copy_settings['input_columns']);

            $this->assertTrue(array_get($response, 'result'));
            $this->compareCopyValue($custom_value, array_get($response, 'redirect'), $copy_settings);
        } finally {
            DB::rollback();
        }
    }

    /**
     * copy to another table, all columns
     */
    public function testCopyOtherTableAll()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $copy_settings = [
                'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'to_table_name' => TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL,
            ];
            $copy = $this->prepareCustomCopy($copy_settings);

            $custom_value = getModelName($copy_settings['from_table_name'])::find(5);
            $response = $copy->execute($custom_value);

            $this->assertTrue(array_get($response, 'result'));
            $this->compareCopyValue($custom_value, array_get($response, 'redirect'));
        } finally {
            DB::rollback();
        }
    }

    /**
     * copy to another table, input some columns
     */
    public function testCopyOtherTableInput()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $copy_settings = [
                'from_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'to_table_name' => TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL,
                'copy_columns' => ['index_text', 'date', 'multiples_of_3', 'init_text', 'currency'],
                'input_columns' => [
                    'integer' => '1789',
                    'email' => 'test123@mail.com',
                ],
            ];
            $copy = $this->prepareCustomCopy($copy_settings);

            $custom_value = getModelName($copy_settings['from_table_name'])::find(3);
            $response = $copy->execute($custom_value, $copy_settings['input_columns']);

            $this->assertTrue(array_get($response, 'result'));
            $this->compareCopyValue($custom_value, array_get($response, 'redirect'), $copy_settings);
        } finally {
            DB::rollback();
        }
    }

    protected function compareCopyValue($custom_value, $redirect, $settings = [])
    {
        $path_array = explode('/', $redirect);
        $new_id = end($path_array);
        $table_name = prev($path_array);

        $new_value = getModelName($table_name)::find($new_id);

        foreach($custom_value->getValues() as $key => $value) {
            if (isset($settings['input_columns']) && array_key_exists($key, $settings['input_columns'])) {
                $this->assertEquals($new_value->getValue($key), $settings['input_columns'][$key]);
            } else if (!isset($settings['copy_columns']) || in_array($key, $settings['copy_columns'])) {
                $this->assertEquals($value, $new_value->getValue($key));
            } else {
                $this->assertTrue(empty($new_value->getValue($key)));
            }
        }
    }

    protected function prepareCustomCopy(array $options = [])
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
        extract($options);

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($from_table_name);

        if (isset($to_table_name)) {
            $custom_table_to = CustomTable::getEloquent($to_table_name);
        }

        $custom_copy = CustomCopy::create([
            'from_custom_table_id' => $custom_table->id,
            'to_custom_table_id' => isset($custom_table_to)? $custom_table_to->id: $custom_table->id,
        ]);

        foreach ($custom_table->custom_columns as $custom_column)
        {
            if (empty($copy_columns) && empty($input_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->getCustomCopyColumnInfo($custom_copy, $custom_column));
            } else if (empty($copy_columns) || \in_array($custom_column->column_name, $copy_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->getCustomCopyColumnInfo($custom_copy, $custom_column));
            } else if (array_key_exists($custom_column->column_name, $input_columns)) {
                $custom_copy_column = CustomCopyColumn::create(
                    $this->getCustomCopyColumnInfo($custom_copy, $custom_column, CopyColumnType::INPUT));
            }
        }

        return $custom_copy;
    }

    protected function getCustomCopyColumnInfo($custom_copy, $custom_column, $column_type = CopyColumnType::DEFAULT)
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