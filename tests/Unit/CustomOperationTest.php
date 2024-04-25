<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
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
use Exceedone\Exment\Tests\TestDefine;

class CustomOperationTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    /**
     * update data at once
     */
    public function testUpdateSelectId()
    {
        $this->initAllTest();

        $settings = [
            'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'operation_type' => [CustomOperationType::BUTTON],
            'operation_name' => 'test operation update',
            'update_columns' => [[
                'column_name' => 'text',
                'update_value_text' => 'unit test update text',
            ]],
        ];
        $operation = $this->_prepareCustomOperation($settings);
        $id = array_get($operation, 'id');

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $ids = '5';
        $result = $operation->execute($custom_table, $ids);

        $this->assertTrue($result);
        $this->_checkUpdateValue($custom_table, $ids, $settings);

        // test delete custom operation setting
        $operation->delete();
        $this->assertNull(CustomOperation::find($id));
        $this->assertCount(0, CustomOperationColumn::where('custom_operation_id', $id)->get());
    }

    /**
     * update multiple data at once
     */
    public function testUpdateSelectIds()
    {
        $this->initAllTest();

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
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $ids = '3,9,15';
        $result = $operation->execute($custom_table, $ids);

        $this->assertTrue($result);
        $this->_checkUpdateValue($custom_table, $ids, $settings);
    }

    /**
     * update data with filter
     */
    public function testUpdateSelectFilter()
    {
        $this->initAllTest();

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
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $custom_column = CustomColumn::getEloquent('integer', $custom_table);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()
            ->where($custom_column->getQueryKey(), '>', 500)->first();

        $result = $operation->execute($custom_table, $custom_value->id);

        $this->assertTrue($result);
        $this->_checkUpdateValue($custom_table, $custom_value->id, $settings);
    }

    /**
     * update data with filter
     */
    public function testUpdateSelectFilterError()
    {
        $this->initAllTest();

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
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $custom_column = CustomColumn::getEloquent('integer', $custom_table);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()
            ->where($custom_column->getQueryKey(), '<=', 500)->first();

        $result = $operation->execute($custom_table, $custom_value->id);

        $this->assertFalse($result === true);
        $this->assertTrue(is_string($result));
    }

    /**
     * update data with filter
     */
    public function testUpdateSelectFilterNotError()
    {
        $this->initAllTest();

        $settings = [
            'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'operation_type' => [CustomOperationType::BULK_UPDATE],
            'operation_name' => 'test bulk update filter',
            'options' => [
                'condition_reverse' => '1'
            ],
            'update_columns' => [[
                'column_name' => 'user',
                'update_value_text' => TestDefine::TESTDATA_USER_LOGINID_USER1,
            ]],
            'conditions' => [[
                'column_name' => 'odd_even',
                'condition_type' => ConditionType::COLUMN,
                'condition_key' => FilterOption::EQ,
                'condition_value' => 'odd',
            ]],
        ];
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $custom_column = CustomColumn::getEloquent('odd_even', $custom_table);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()
            ->where($custom_column->getQueryKey(), 'odd')->first();

        $result = $operation->execute($custom_table, $custom_value->id);

        $this->assertFalse($result === true);
        $this->assertTrue(is_string($result));
    }

    /**
     * update data with filter
     */
    public function testUpdateSelectFilterNot()
    {
        $this->initAllTest();

        $settings = [
            'custom_table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL,
            'operation_type' => [CustomOperationType::BULK_UPDATE],
            'operation_name' => 'test bulk update filter',
            'options' => [
                'condition_join' => 'or',
                'condition_reverse' => '1'
            ],
            'update_columns' => [[
                'column_name' => 'user',
                'update_value_text' => TestDefine::TESTDATA_USER_LOGINID_USER1,
            ]],
            'conditions' => [[
                'column_name' => 'odd_even',
                'condition_type' => ConditionType::COLUMN,
                'condition_key' => FilterOption::EQ,
                'condition_value' => 'odd',
            ], [
                'column_name' => 'integer',
                'condition_type' => ConditionType::COLUMN,
                'condition_key' => FilterOption::NUMBER_LTE,
                'condition_value' => 1000,
            ]],
        ];
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $custom_column1 = CustomColumn::getEloquent('odd_even', $custom_table);
        $custom_column2 = CustomColumn::getEloquent('integer', $custom_table);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()
            ->whereNot($custom_column1->getQueryKey(), 'odd')
            ->whereNot($custom_column2->getQueryKey(), '<=', 1000)->first();

        $result = $operation->execute($custom_table, $custom_value->id);

        $this->assertTrue($result);
        $this->_checkUpdateValue($custom_table, $custom_value->id, $settings);
    }

    /**
     * update data with create event
     */
    public function testOperationByCreate()
    {
        $this->initAllTest();

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
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        $custom_value = $custom_table->getValueModel();
        $custom_value->setValue("text", 'test operation data create');
        $custom_value->save();

        $this->_checkUpdateValue($custom_table, $custom_value->id, $settings);
    }

    /**
     * update data with update event
     */
    public function testOperationByUpdate()
    {
        $this->initAllTest();

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
        $operation = $this->_prepareCustomOperation($settings);

        $custom_table = CustomTable::getEloquent($settings['custom_table_name']);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()
            ->where('value->organization', '<>', TestDefine::TESTDATA_ORGANIZATION_DEV)->first();
        $custom_value->setValue("text", 'test operation data update');
        $custom_value->save();

        $this->_checkUpdateValue($custom_table, $custom_value->id, $settings);
    }

    /**
     * delete custom table with operation setting
     */
    public function testTableDeleteWithOperation()
    {
        $this->_createSimpleTable('operation_test');
        $settings = [
            'custom_table_name' => 'operation_test',
            'operation_type' => [CustomOperationType::CREATE],
            'operation_name' => 'test operation by create',
            'update_columns' => [[
                'column_name' => 'name',
                'update_value_text' => 'operation update value',
            ]],
        ];
        $operation = $this->_prepareCustomOperation($settings);
        $id = array_get($operation, 'id');

        // test delete custom table with copy setting
        $res = CustomTable::getEloquent($settings['custom_table_name'])->delete();
        $this->assertTrue($res);

        $this->assertNull(CustomOperation::find($id));
        $this->assertCount(0, CustomOperationColumn::where('custom_operation_id', $id)->get());
    }

    protected function _checkUpdateValue($custom_table, $ids, $settings = [])
    {
        $ids = stringToArray($ids);
        $custom_values = $custom_table->getValueModel()->find($ids);

        foreach ($custom_values as $custom_value) {
            foreach ($settings['update_columns'] as $update_column) {
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
                            $orgs = $login_user->getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_JOIN);
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

    protected function _prepareCustomOperation(array $settings = [])
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
        $login_user_id = $settings['login_user_id'];
        $custom_table_name = $settings['custom_table_name'];
        $operation_type = $settings['operation_type'];
        $operation_name = $settings['operation_name'];
        $options = $settings['options'];
        $update_columns = $settings['update_columns'];
        $conditions = $settings['conditions'];

        // Login user.
        $this->be(LoginUser::find($login_user_id));

        $custom_table = CustomTable::getEloquent($custom_table_name);

        /** @var CustomOperation $custom_operation */
        $custom_operation = CustomOperation::create([
            'custom_table_id' => $custom_table->id,
            'operation_type' => $operation_type,
            'operation_name' => $operation_name,
            'options' => $options,
        ]);

        foreach ($update_columns as $update_column) {
            $target_column = CustomColumn::where('custom_table_id', $custom_table->id)
                ->where('column_name', $update_column['column_name'])->first();

            $custom_operation_column = CustomOperationColumn::create([
                'custom_operation_id' => $custom_operation->id,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_target_id' => $target_column->id,
                'update_value_text' => $update_column['update_value_text'],
                'options' => [
                    'operation_update_type' => isset($update_column['update_type']) ? $update_column['update_type'] : 'default'
                ],
            ]);
        }

        foreach ($conditions as $condition) {
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
