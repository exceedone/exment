<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\OperationUpdateType;
use Exceedone\Exment\Enums\OperationValueType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;
use Exceedone\Exment\Tests\TestDefine;
use Carbon\Carbon;

class CCustomOperationTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;
    use ColumnOptionQueryTrait;
    use DatabaseTransactions;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * Check custom value operation setting display.
     */
    public function testDisplayOperationSetting()
    {
        $suuid = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->suuid;

        // Check custom column view
        $this->visit(admin_url('operation/custom_value_edit_all'))
            ->seePageIs(admin_url('operation/custom_value_edit_all'))
            ->seeInElement('h1', 'データ更新設定 : custom_value_edit_all')
            ->seeInElement('th', '処理の名前')
            ->seeInElement('th', '更新のタイミング')
            ->seeInElement('th', '操作')
            ->visit(admin_url("operation/custom_value_edit_all/create"))
            ->seeInElement('h1', 'データ更新設定 : custom_value_edit_all')
            ->seeInElement('label', 'テーブル名(英数字)')
            ->seeInElement('label', 'テーブル表示名')
            ->seeInElement('label', '処理の名前')
            ->seeInElement('label', '更新のタイミング')
            ->seeInElement('h4[class=field-header]', '更新列設定')
            ->seeInElement('th', '対象列')
            ->seeInElement('th', '更新の種類')
            ->seeInElement('th', '更新値')
            ->seeInElement('th', '操作')
            ->seeInElement('h4[class=field-header]', '更新条件')
            ->seeInElement('th', '条件項目')
            ->seeInElement('th', '条件')
            ->seeInElement('th', '条件値')
            ->seeInElement('th', '操作')
            ->seeInElement('label.radio-inline', 'すべての条件に一致')
            ->seeInElement('label.radio-inline', 'いずれかの条件に一致')
            ->seeInElement('button[id=admin-submit]', '保存')
        ;
        $this->exactSelectOptions('select.operation_type', CustomOperationType::transKeyArray('custom_operation.operation_type_options'));
    }

    /**
     * operation type: buttoln.
     * operation target: single custom value.
     * filter: no
     */
    public function testOperationOneNoFilter()
    {
        $target_table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $target_table = CustomTable::getEloquent($target_table_name);

        $column_user = CustomColumn::getEloquent('user', $target_table_name);
        $column_date = CustomColumn::getEloquent('date', $target_table_name);

        $operation = $this->_addOperationData($target_table, [
            'operation_type' => [CustomOperationType::BUTTON],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::SYSTEM,
                'view_column_target' => $column_user->id . '?table_id=' . $target_table->id,
                'update_value_text' => OperationValueType::LOGIN_USER,
                '_remove_' => 0,
            ], [
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => $column_date->id . '?table_id=' . $target_table->id,
                'update_value_text' => '2021-01-01',
                '_remove_' => 0,
            ]]
        ]);

        $this->seeIsSelected('operation_type[]', CustomOperationType::BUTTON);

        foreach ($operation->custom_operation_columns as $index => $custom_operation_column) {
            $row_id = $custom_operation_column->id;
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][view_column_target]",
                $custom_operation_column->view_column_target_id . '?table_id=' . $target_table->id
            );
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][operation_update_type]",
                $custom_operation_column->getOption('operation_update_type')
            );
            if ($index == 0) {
                $this->seeIsSelected("custom_operation_columns[$row_id][update_value_text]", OperationValueType::LOGIN_USER);
            } else {
                $this->seeOuterElement("input.update_value_text.rowno-$row_id", '2021-01-01');
            }
            $this->exactSelectOptions("select[name='custom_operation_columns[$row_id][operation_update_type]']", OperationUpdateType::transKeyArray('custom_operation.operation_update_type_options'));
        }

        $this->exactSelectOptions('select.view_column_target', $this->getColumnSelectOptions($target_table_name, [
            'ignore_attachment' => true,
        ]));

        /** @var Model\CustomValue $custom_value */
        $custom_value = $target_table->getValueModel()->where('value->user', '<>', \Exment::user()->base_user->id)->first();
        $target_id = $custom_value->id;
        $this->post(admin_url("data/$target_table_name/$target_id/operationClick"), [
            'suuid' => array_get($operation, 'suuid')
        ]);
        $this->assertPostResponse($this->response, admin_url("data/$target_table_name/$target_id/operationClick"));

        /** @var Model\CustomValue $custom_value */
        $custom_value = $target_table->getValueModel()->find($target_id);
        $this->assertEquals($custom_value->getValue('user')->id, \Exment::user()->base_user->id);
        $this->assertEquals($custom_value->getValue('date'), '2021-01-01');
    }

    /**
     * operation type: bulk update.
     * operation target: multiple custom value.
     * filter: yes
     */
    public function testOperationMultiWithFilter()
    {
        $target_table_name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $target_table = CustomTable::getEloquent($target_table_name);

        $column_1 = CustomColumn::getEloquent('yesno', $target_table_name);
        $column_2 = CustomColumn::getEloquent('organization', $target_table_name);
        $filter_1 = CustomColumn::getEloquent('currency', $target_table_name);

        $operation = $this->_addOperationData($target_table, [
            'operation_type' => [CustomOperationType::BULK_UPDATE],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => $column_1->id . '?table_id=' . $target_table->id,
                'update_value_text' => 1,
                '_remove_' => 0,
            ], [
                'operation_update_type' => OperationUpdateType::SYSTEM,
                'view_column_target' => $column_2->id . '?table_id=' . $target_table->id,
                'update_value_text' => OperationValueType::BERONG_ORGANIZATIONS,
                '_remove_' => 0,
            ]],
            'custom_operation_conditions' => [[
                'condition_target' => 'USER',
                'condition_key' => FilterOption::SELECT_EXISTS,
                'condition_value' => [TestDefine::TESTDATA_USER_LOGINID_DEV_USERB],
                '_remove_' => 0,
            ], [
                'condition_target' => $filter_1->id,
                'condition_key' => FilterOption::NUMBER_GT,
                'condition_value' => 30000,
                '_remove_' => 0,
            ]]
        ]);

        $this->seeIsSelected('operation_type[]', CustomOperationType::BULK_UPDATE);

        foreach ($operation->custom_operation_columns as $index => $custom_operation_column) {
            $row_id = $custom_operation_column->id;
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][view_column_target]",
                $custom_operation_column->view_column_target_id . '?table_id=' . $target_table->id
            );
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][operation_update_type]",
                $custom_operation_column->getOption('operation_update_type')
            );
            if ($index == 0) {
                $this->seeOuterElement("input.update_value_text.rowno-$row_id", '1');
            } else {
                $this->seeIsSelected("custom_operation_columns[$row_id][update_value_text]", OperationValueType::BERONG_ORGANIZATIONS);
            }
        }

        foreach ($operation->custom_operation_conditions as $index => $custom_operation_condition) {
            $row_id = $custom_operation_condition->id;
            $this->seeIsSelected(
                "custom_operation_conditions[$row_id][condition_key]",
                $custom_operation_condition->condition_key
            );
            if ($index == 0) {
                $this->seeIsSelected("custom_operation_conditions[$row_id][condition_target]", 'USER');
                $this->seeIsSelected(
                    "custom_operation_conditions[$row_id][condition_value][]",
                    TestDefine::TESTDATA_USER_LOGINID_DEV_USERB
                );
                $this->exactSelectOptions("select[name='custom_operation_conditions[$row_id][condition_key]']", $this->getFilterSelectOptions(FilterType::CONDITION));
                $this->exactSelectOptions(
                    "select[name='custom_operation_conditions[$row_id][condition_value][]']",
                    $this->getUserSelectOptions()
                );
            } else {
                $this->seeIsSelected(
                    "custom_operation_conditions[$row_id][condition_target]",
                    $custom_operation_condition->target_column_id
                );
                $this->seeOuterElement("input.condition_value.rowno-$row_id", 30000);
                $this->exactSelectOptions("select[name='custom_operation_conditions[$row_id][condition_key]']", $this->getFilterSelectOptions(FilterType::NUMBER));
            }
        }

        $add_options = [];
        foreach (ConditionTypeDetail::CONDITION_OPTIONS() as $key => $enum) {
            $add_options[$enum->getKey()] = $enum->lowerKey();
        }
        $add_options = getTransArrayValue($add_options, 'condition.condition_type_options');

        $this->exactSelectOptions(
            'select.condition_target',
            $this->getColumnSelectOptions($target_table_name, [
                'is_index' => false,
                'append_tableid' => false,
                'add_options' => $add_options,
                'ignore_attachment' => true,
            ])
        );

        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        Model\System::clearCache();

        $ids = $target_table->getValueModel()->where('value->currency', '>', 30000)
            ->where('value->user', TestDefine::TESTDATA_USER_LOGINID_DEV_USERB)
            ->where('value->yesno', '<>', 1)
            ->where('value->organization', '<>', TestDefine::TESTDATA_ORGANIZATION_DEV)
            ->take(3)->pluck('id')->toArray();

        $this->post(admin_url("data/$target_table_name/operationClick"), [
            'suuid' => array_get($operation, 'suuid'),
            'id' => implode(',', $ids)
        ]);
        $this->assertPostResponse($this->response, admin_url("data/$target_table_name/operationClick"));

        $custom_values = $target_table->getValueModel()->find($ids);

        foreach ($custom_values as $custom_value) {
            $organization = $custom_value->getValue('organization');
            $this->assertEquals($custom_value->getValue('organization')->id, \Exment::user()->belong_organizations->first()->id);
            $this->assertEquals($custom_value->getValue('yesno'), '1');
        }

        $err_ids = $target_table->getValueModel()->where('value->currency', '<', 30000)
            ->where('value->user', TestDefine::TESTDATA_USER_LOGINID_DEV_USERB)
            ->take(2)->pluck('id')->toArray();

        $this->post(admin_url("data/$target_table_name/operationClick"), [
            'suuid' => array_get($operation, 'suuid'),
            'id' => implode(',', $err_ids)
        ]);
        /** @phpstan-ignore-next-line  */
        $this->assertFalse($this->response->getData()->result);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        Model\System::clearCache();

        $this->post(admin_url("data/$target_table_name/operationClick"), [
            'suuid' => array_get($operation, 'suuid'),
            'id' => implode(',', $ids)
        ]);
        /** @phpstan-ignore-next-line  */
        $this->assertFalse($this->response->getData()->result);
    }

    /**
     * operation type: create.
     * filter: yes
     */
    public function testOperationCreate()
    {
        $target_table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $target_table = CustomTable::getEloquent($target_table_name);

        $column_1 = CustomColumn::getEloquent('odd_even', $target_table_name);
        $column_2 = CustomColumn::getEloquent('decimal', $target_table_name);

        $operation = $this->_addOperationData($target_table, [
            'operation_type' => [CustomOperationType::CREATE],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => $column_1->id . '?table_id=' . $target_table->id,
                'update_value_text' => 'odd',
                '_remove_' => 0,
            ], [
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => $column_2->id . '?table_id=' . $target_table->id,
                'update_value_text' => '0.01',
                '_remove_' => 0,
            ]],
            'custom_operation_conditions' => [[
                'condition_target' => 'ROLE',
                'condition_key' => FilterOption::SELECT_EXISTS,
                'condition_value' => [TestDefine::TESTDATA_ROLEGROUP_GENERAL],
                '_remove_' => 0,
            ]]
        ]);

        foreach ($operation->custom_operation_columns as $index => $custom_operation_column) {
            $row_id = $custom_operation_column->id;
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][view_column_target]",
                $custom_operation_column->view_column_target_id . '?table_id=' . $target_table->id
            );
            $this->seeIsSelected("custom_operation_columns[$row_id][operation_update_type]", OperationUpdateType::DEFAULT);
            $this->seeOuterElement("input.update_value_text.rowno-$row_id", $custom_operation_column->update_value_text);
        }

        foreach ($operation->custom_operation_conditions as $index => $custom_operation_condition) {
            $row_id = $custom_operation_condition->id;
            $this->seeIsSelected(
                "custom_operation_conditions[$row_id][condition_key]",
                $custom_operation_condition->condition_key
            );
            $this->seeIsSelected("custom_operation_conditions[$row_id][condition_target]", 'ROLE');
            $this->seeIsSelected(
                "custom_operation_conditions[$row_id][condition_value][]",
                TestDefine::TESTDATA_ROLEGROUP_GENERAL
            );
            $this->exactSelectOptions("select[name='custom_operation_conditions[$row_id][condition_key]']", $this->getFilterSelectOptions(FilterType::CONDITION));
            $this->exactSelectOptions(
                "select[name='custom_operation_conditions[$row_id][condition_value][]']",
                $this->getRoleSelectOptions()
            );
        }

        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        Model\System::clearCache();

        $this->visit(admin_url("data/$target_table_name/create"))
                ->type('operation create test', 'value[text]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('id', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation create test');
        $this->assertEquals($custom_value->getValue('odd_even'), 'odd');
        $this->assertEquals($custom_value->getValue('decimal'), 0.01);

        $this->visit(admin_url("data/$target_table_name/" . $custom_value->id . '/edit'))
                ->type('operation create test update', 'value[text]')
                ->type('even', 'value[odd_even]')
                ->type(123.45, 'value[decimal]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get updated data row
        $custom_value = $target_table->getValueModel()->orderBy('updated_at', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation create test update');
        $this->assertEquals($custom_value->getValue('odd_even'), 'even');
        $this->assertEquals($custom_value->getValue('decimal'), 123.45);

        // user no role_group
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        Model\System::clearCache();

        $this->visit(admin_url("data/$target_table_name/create"))
                ->type('operation create test by admin', 'value[text]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('id', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation create test by admin');
        $this->assertNull($custom_value->getValue('odd_even'));
        $this->assertNull($custom_value->getValue('decimal'));
    }

    /**
     * operation type: update.
     * filter: yes
     */
    public function testOperationUpdate()
    {
        $target_table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $target_table = CustomTable::getEloquent($target_table_name);

        $column_1 = CustomColumn::getEloquent('date', $target_table_name);
        $column_2 = CustomColumn::getEloquent('email', $target_table_name);
        $filter_1 = CustomColumn::getEloquent('multiples_of_3', $target_table_name);

        $operation = $this->_addOperationData($target_table, [
            'operation_type' => [CustomOperationType::UPDATE],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::SYSTEM,
                'view_column_target' => $column_1->id . '?table_id=' . $target_table->id,
                'update_value_text' => OperationValueType::EXECUTE_DATETIME,
                '_remove_' => 0,
            ], [
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => $column_2->id . '?table_id=' . $target_table->id,
                'update_value_text' => 'test123@mail.co.jp',
                '_remove_' => 0,
            ]],
            'custom_operation_conditions' => [[
                'condition_target' => $filter_1->id,
                'condition_key' => FilterOption::EQ,
                'condition_value' => 0,
                '_remove_' => 0,
            ]]
        ]);

        foreach ($operation->custom_operation_columns as $index => $custom_operation_column) {
            $row_id = $custom_operation_column->id;
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][view_column_target]",
                $custom_operation_column->view_column_target_id . '?table_id=' . $target_table->id
            );
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][operation_update_type]",
                $custom_operation_column->getOption('operation_update_type')
            );
            if ($index == 0) {
                $this->seeIsSelected(
                    "custom_operation_columns[$row_id][update_value_text]",
                    OperationValueType::EXECUTE_DATETIME
                );
            } else {
                $this->seeOuterElement("input.update_value_text.rowno-$row_id", $custom_operation_column->update_value_text);
            }
        }

        foreach ($operation->custom_operation_conditions as $index => $custom_operation_condition) {
            $row_id = $custom_operation_condition->id;
            $this->seeIsSelected(
                "custom_operation_conditions[$row_id][condition_key]",
                $custom_operation_condition->condition_key
            );
            $this->seeIsSelected("custom_operation_conditions[$row_id][condition_target]", $filter_1->id);
            $this->seeOuterElement("input.condition_value.rowno-$row_id", $custom_operation_condition->condition_value);
            $this->exactSelectOptions("select[name='custom_operation_conditions[$row_id][condition_key]']", $this->getFilterSelectOptions(FilterType::YESNO));
        }

        $this->visit(admin_url("data/$target_table_name/create"))
                ->type('operation update test', 'value[text]')
                ->type('1', 'value[multiples_of_3]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('id', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation update test');
        $this->assertEquals($custom_value->getValue('multiples_of_3'), '1');
        $this->assertNull($custom_value->getValue('date'));
        $this->assertNull($custom_value->getValue('email'));

        $this->visit(admin_url("data/$target_table_name/" . $custom_value->id . '/edit'))
                ->type('operation update test update', 'value[text]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get updated data row
        $custom_value = $target_table->getValueModel()->orderBy('updated_at', 'desc')->orderBy('id', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation update test update');
        $this->assertNull($custom_value->getValue('date'));
        $this->assertNull($custom_value->getValue('email'));

        $this->visit(admin_url("data/$target_table_name/" . $custom_value->id . '/edit'))
                ->type('operation update multiples_of_3 turn off', 'value[text]')
                ->type('0', 'value[multiples_of_3]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('updated_at', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation update multiples_of_3 turn off');
        $this->assertEquals($custom_value->getValue('multiples_of_3'), '0');
        $this->assertEquals($custom_value->getValue('date'), \Carbon\Carbon::today()->format("Y-m-d"));
        $this->assertEquals($custom_value->getValue('email'), 'test123@mail.co.jp');
    }

    /**
     * operation type: create, update.
     * filter: yes
     */
    public function testOperationMultiType()
    {
        $target_table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $target_table = CustomTable::getEloquent($target_table_name);

        $column_1 = CustomColumn::getEloquent('user', $target_table_name);
        $filter_1 = CustomColumn::getEloquent('date', $target_table_name);

        $operation = $this->_addOperationData($target_table, [
            'operation_type' => [CustomOperationType::CREATE, CustomOperationType::UPDATE],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::SYSTEM,
                'view_column_target' => $column_1->id . '?table_id=' . $target_table->id,
                'update_value_text' => OperationValueType::LOGIN_USER,
                '_remove_' => 0,
            ]],
            'custom_operation_conditions' => [[
                'condition_target' => $filter_1->id,
                'condition_key' => FilterOption::DAY_LAST_YEAR,
                '_remove_' => 0,
            ]]
        ]);

        foreach ($operation->custom_operation_columns as $index => $custom_operation_column) {
            $row_id = $custom_operation_column->id;
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][view_column_target]",
                $custom_operation_column->view_column_target_id . '?table_id=' . $target_table->id
            );
            $this->seeIsSelected("custom_operation_columns[$row_id][operation_update_type]", OperationUpdateType::SYSTEM);
            $this->seeIsSelected(
                "custom_operation_columns[$row_id][update_value_text]",
                OperationValueType::LOGIN_USER
            );
        }

        foreach ($operation->custom_operation_conditions as $index => $custom_operation_condition) {
            $row_id = $custom_operation_condition->id;
            $this->seeIsSelected(
                "custom_operation_conditions[$row_id][condition_key]",
                $custom_operation_condition->condition_key
            );
            $this->seeIsSelected("custom_operation_conditions[$row_id][condition_target]", $filter_1->id);
            $this->exactSelectOptions("select[name='custom_operation_conditions[$row_id][condition_key]']", $this->getFilterSelectOptions(FilterType::DAY));
        }

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);

        // get last year's date
        $today = Carbon::today();
        $lastYearDate = Carbon::createFromDate($today->year, 1, 1)->addDays(-1)->format('Y-m-d');
        $this->visit(admin_url("data/$target_table_name/create"))
                ->type('operation multiple type', 'value[text]')
                ->type($lastYearDate, 'value[date]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('id', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation multiple type');
        $this->assertEquals($custom_value->getValue('date'), $lastYearDate);
        $this->assertEquals($custom_value->getValue('user')->id, \Exment::user()->base_user->id);

        $this->visit(admin_url("data/$target_table_name/" . $custom_value->id . '/edit'))
                ->type('operation multiple type update', 'value[text]')
                ->type(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC, 'value[user]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get updated data row
        $thisYearDate = Carbon::createFromDate($today->year, 1, 31)->addDays(-1)->format('Y-m-d');
        $custom_value = $target_table->getValueModel()->orderBy('updated_at', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation multiple type update');
        $this->assertEquals($custom_value->getValue('user')->id, \Exment::user()->base_user->id);

        $this->visit(admin_url("data/$target_table_name/" . $custom_value->id . '/edit'))
                ->type('operation multiple type change date', 'value[text]')
                ->type($thisYearDate, 'value[date]')
                ->type(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC, 'value[user]')
                ->press('admin-submit')
                ->seePageIs(admin_url("/data/$target_table_name"));

        // Get new data row
        $custom_value = $target_table->getValueModel()->orderBy('updated_at', 'desc')->first();
        $this->assertEquals($custom_value->getValue('text'), 'operation multiple type change date');
        $this->assertEquals($custom_value->getValue('date'), $thisYearDate);
        $this->assertEquals($custom_value->getValue('user')->id, TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC);
    }

    /**
     * Create custom value operation setting.
     */
    protected function _addOperationData($target_table, $params = [])
    {
        $pre_cnt = CustomOperation::count();
        $pre_child_cnt = CustomOperationColumn::count();

        $target_table_name = $target_table->table_name;

        $data = array_merge([
            'custom_table_id' => $target_table->id,
            'operation_name' => 'operation unit test',
            'options' => [
                'button_label' => 'operation button',
                'condition_join' => 'and',
            ],
            'custom_operation_columns' => [],
            'custom_operation_conditions' => [],
        ], $params);

        $this->post(admin_url("operation/$target_table_name"), $data);
        $this->assertPostResponse($this->response, admin_url("operation/$target_table_name"));

        $this->visit(admin_url("operation/$target_table_name"))
            ->seePageIs(admin_url("operation/$target_table_name"))
            ->seeInElement('td', $data['operation_name'])
            ->seeInElement('td', collect($data['operation_type'])->map(function ($val) {
                return exmtrans("custom_operation.operation_type_options_short.$val");
            })->implode('、'))
            ->assertEquals($pre_cnt + 1, CustomOperation::count());

        $this->assertEquals(
            $pre_child_cnt + count($data['custom_operation_columns']),
            CustomOperationColumn::count()
        )
        ;

        $raw = CustomOperation::orderBy('id', 'desc')->first();
        $id = array_get($raw, 'id');

        $this->visit(admin_url("operation/$target_table_name/$id/edit"))
            ->seePageIs(admin_url("operation/$target_table_name/$id/edit"))
            ->seeOuterElement('input[id=operation_name]', 'operation unit test')
            ->seeOuterElement('input[id=button_label]', 'operation button')
            ->seeOuterElement('span.custom_table_table_name_', $target_table_name)
            ->seeOuterElement('span.custom_table_table_view_name_', $target_table_name);

        return $raw;
    }

    /**
     * Get filter condition options
     *
     * @return array
     */
    protected function getFilterSelectOptions($filter_type): array
    {
        $options = FilterOption::FILTER_OPTIONS()[$filter_type];
        return collect($options)->mapWithKeys(function ($option) {
            return [$option['id'] => exmtrans("custom_view.filter_condition_options.{$option['name']}")];
        })->toArray();
    }

    /**
     * Get user column's options
     *
     * @return array
     */
    protected function getUserSelectOptions(): array
    {
        $custom_table = CustomTable::getEloquent(SystemTableName::USER);
        return $custom_table->getSelectOptions([
            'display_table' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
        ])->toArray();
    }

    /**
     * Get role group column's options
     *
     * @return array
     */
    protected function getRoleSelectOptions(): array
    {
        return Model\RoleGroup::all()
            ->mapWithKeys(function ($value) {
                return [$value->id => $value->role_group_view_name];
            })->toArray();
    }
}
