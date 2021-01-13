<?php
namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\OperationValueType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomOperationTest extends UnitTestBase
{
    protected function init(){
        System::clearCache();
    }

    /**
     * update multiple data at once
     */
    public function testUpdateSelectIds()
    {
        $this->init();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BULK_UPDATE],
                'operation_name' => 'test bulk update all',
                'update_columns' => [[
                    'column_name' => 'date',
                    'update_value_text' => 'execute_datetime',
                    'update_type' => 'system'
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $ids = '3,9,15';
            $result = $operation->execute($custom_table, $ids);

            $this->assertTrue($result);
            $this->checkUpdateValue($custom_table, $ids, $settings);
        } finally {
            DB::rollback();
        }
    }

    protected function checkUpdateValue($custom_table, $ids, $settings = [])
    {
        $ids = stringToArray($id);
        $custom_values = $custom_table->getValueModel()->find($ids);

        foreach($custom_values as $custom_value) {
            foreach ($settings['update_columns'] as $update_column)
            {
                $value = $custom_value->getValue($update_column['column_name']);
                if ($update_column['update_type'] == 'system') {
                    switch ($update_column['update_value_text']) {
                        case OperationValueType::EXECUTE_DATETIME:
                            $now = \Carbon\Carbon::now();
                            
                        case OperationValueType::LOGIN_USER:
                            $login_user = \Exment::user();
                            $this->assertEquals($value, $login_user->getUserId());
                            
                        case OperationValueType::BERONG_ORGANIZATIONS:
                    }
                } else {
                    $this->assertEquals($value, $update_column['update_value_text']);
                }
            }
        }
    }

    protected function prepareCustomOperation(array $settings = [])
    {
        $settings = array_merge(
            [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BUTTON],
                'operation_name' => 'unit test',
                'options' => [
                    'condition_join' => 'and'
                ],
                'update_columns' => [],
            ], 
            $settings
        );
        extract($settings);

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($custom_table_name);

        $custom_operation = CustomOperation::create([
            'custom_table_id' => $custom_table->id,
            'operation_type' => $operation_type,
            'operation_name' => $operation_name,
            'options' => $options,
        ]);

        foreach ($update_columns as $update_column)
        {
            $target_column = CustomColumn::where('custom_table_id', $custom_table->id)
                ->where('column_name', $update_column['column_name'])->first();

            $custom_operation_column = CustomOperationColumn::create([
                'custom_operation_id' => $custom_operation->id,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_target_id' => $target_column->id,
                'update_value_text' => $update_column['update_value_text'],
                'options' => [
                    'operation_update_type' => isset($update_column['update_type'])? $update_column['update_type']: 'default'
                ],
            ]);
        }

        return $custom_operation;
    }
}