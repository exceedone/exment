<?php
namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\OperationValueType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

class CustomOperationTest extends UnitTestBase
{
    /**
     * update data at once
     */
    public function testUpdateSelectId()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BUTTON],
                'operation_name' => 'test operation update',
                'update_columns' => [[
                    'column_name' => 'text',
                    'update_value_text' => 'unit test update text',
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $ids = '5';
            $result = $operation->execute($custom_table, $ids);

            $this->assertTrue($result);
            $this->checkUpdateValue($custom_table, $ids, $settings);
        } finally {
            DB::rollback();
        }
    }

    /**
     * update multiple data at once
     */
    public function testUpdateSelectIds()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BULK_UPDATE],
                'operation_name' => 'test bulk update all',
                'update_columns' => [[
                    'column_name' => 'date',
                    'update_value_text' => OperationValueType::EXECUTE_DATETIME,
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

    /**
     * update data with filter
     */
    public function testUpdateSelectFilter()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BULK_UPDATE],
                'operation_name' => 'test bulk update filter',
                'update_columns' => [[
                    'column_name' => 'user',
                    'update_value_text' => TestDefine::TESTDATA_USER_LOGINID_USER1,
                ]],
                'conditions' => [[
                    'column_name' => 'integer',
                    'condition_type' => ConditionType::COLUMN,
                    'condition_key' => FilterOption::NUMBER_GT,
                    'condition_value' => 500,
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $custom_value = $custom_table->getValueModel()
                ->where('value->integer', '>', 500)->first();

            $result = $operation->execute($custom_table, $custom_value->id);

            $this->assertTrue($result);
            $this->checkUpdateValue($custom_table, $custom_value->id, $settings);
        } finally {
            DB::rollback();
        }
    }

    /**
     * update data with filter
     */
    public function testUpdateSelectFilterError()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::BULK_UPDATE],
                'operation_name' => 'test bulk update filter',
                'update_columns' => [[
                    'column_name' => 'user',
                    'update_value_text' => TestDefine::TESTDATA_USER_LOGINID_USER1,
                ]],
                'conditions' => [[
                    'column_name' => 'integer',
                    'condition_type' => ConditionType::COLUMN,
                    'condition_key' => FilterOption::NUMBER_GT,
                    'condition_value' => 500,
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $custom_value = $custom_table->getValueModel()
                ->where('value->integer', '<=', 500)->first();

            $result = $operation->execute($custom_table, $custom_value->id);

            $this->assertFalse($result->getData()->result);
        } finally {
            DB::rollback();
        }
    }

    /**
     * update data with create event
     */
    public function testOperationByCreate()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
                'operation_type' => [CustomOperationType::CREATE],
                'operation_name' => 'test operation by create',
                'update_columns' => [[
                    'column_name' => 'user',
                    'update_value_text' => OperationValueType::LOGIN_USER,
                    'update_type' => 'system'
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $custom_value = $custom_table->getValueModel();
            $custom_value->setValue("text", 'test operation data create');
            $custom_value->save();

            $this->checkUpdateValue($custom_table, $custom_value->id, $settings);
        } finally {
            DB::rollback();
        }
    }

    /**
     * update data with update event
     */
    public function testOperationByUpdate()
    {
        $this->initAllTest();

        DB::beginTransaction();
        try {
            $settings = [
                'login_user_id' => TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
                'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
                'operation_type' => [CustomOperationType::UPDATE],
                'operation_name' => 'test operation by create',
                'update_columns' => [[
                    'column_name' => 'organization',
                    'update_value_text' => OperationValueType::BERONG_ORGANIZATIONS,
                    'update_type' => 'system'
                ]],
            ];
            $operation = $this->prepareCustomOperation($settings);

            $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
            $custom_value = $custom_table->getValueModel()
                ->where('value->organization', '<>', TestDefine::TESTDATA_ORGANIZATION_DEV)->first();
            $custom_value->setValue("text", 'test operation data update');
            $custom_value->save();

            $this->checkUpdateValue($custom_table, $custom_value->id, $settings);
        } finally {
            DB::rollback();
        }
    }

    protected function checkUpdateValue($custom_table, $ids, $settings = [])
    {
        $ids = stringToArray($ids);
        $custom_values = $custom_table->getValueModel()->find($ids);

        foreach($custom_values as $custom_value) {
            foreach ($settings['update_columns'] as $update_column)
            {
                $value = $custom_value->getValue($update_column['column_name']);
                if (isset($update_column['update_type']) && $update_column['update_type'] == 'system') {
                    switch ($update_column['update_value_text']) {
                        case OperationValueType::EXECUTE_DATETIME:
                            $value = \Carbon\Carbon::parse($value);
                            $this->assertTrue($value->isToday());
                            break;
                            
                        case OperationValueType::LOGIN_USER:
                            $login_user = \Exment::user();
                            $this->assertTrue($value instanceof CustomValue);
                            $this->assertEquals($value->id, $login_user->getUserId());
                            break;
                            
                        case OperationValueType::BERONG_ORGANIZATIONS:
                            $login_user = \Exment::user();
                            // get joined user's id
                            $orgs = $login_user->getOrganizationIds(JoinedOrgFilterType::ONLY_JOIN);
                            $this->assertTrue($value instanceof CustomValue);
                            $this->assertTrue(in_array($value->id, $orgs));
                            break;
                    }
                } else {
                    if ($value instanceof CustomValue) {
                        $this->assertEquals($value->id, $update_column['update_value_text']);
                    } else {
                        $this->assertEquals($value, $update_column['update_value_text']);
                    }
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
                'conditions' => [],
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

        foreach ($conditions as $condition)
        {
            $target_column = CustomColumn::where('custom_table_id', $custom_table->id)
                ->where('column_name', $condition['column_name'])->first();

            $operation_condition = Condition::create([
                'morph_type' => 'custom_operation',
                'morph_id' => $custom_operation->id,
                'condition_type' => $condition['condition_type'],
                'condition_key' => $condition['condition_key'],
                'target_column_id' => $target_column->id,
                'condition_value' => $condition['condition_value'],
            ]);
        }

        return $custom_operation;
    }
}