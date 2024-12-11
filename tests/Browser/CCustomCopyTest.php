<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomCopyColumn;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;
use Exceedone\Exment\Tests\TestDefine;

class CCustomCopyTest extends ExmentKitTestCase
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
     * Check custom value copy setting display.
     */
    public function testDisplayCopySetting()
    {
        $suuid = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->suuid;

        // Check custom column view
        $this->visit(admin_url('copy/custom_value_edit_all'))
            ->seePageIs(admin_url('copy/custom_value_edit_all'))
            ->see('データコピー設定 : custom_value_edit_all')
            ->seeInElement('th', 'コピー元テーブル')
            ->seeInElement('th', 'コピー先テーブル')
            ->seeInElement('th', 'ボタンのラベル')
            ->seeInElement('th', '操作')
            ->visit(admin_url("copy/custom_value_edit_all/create?to_custom_table=$suuid"))
            ->seeInElement('h1', 'データコピー設定 : custom_value_edit_all')
            ->seeInElement('label', 'コピー元テーブル')
            ->seeInElement('label', 'コピー先テーブル')
            ->seeInElement('label', 'ボタンのラベル')
            ->seeInElement('label', 'ボタンのアイコン')
            ->seeInElement('label', 'ボタンのHTML class')
            ->seeInElement('h4[class=field-header]', 'コピー列設定')
            ->seeInElement('th', 'コピー元テーブル列')
            ->seeInElement('th', 'コピー先テーブル列')
            ->seeInElement('th', '操作')
            ->seeInElement('th', '対象テーブル列')
            ->seeInElement('button[id=admin-submit]', '保存')
        ;
    }

    /**
     * Create custom value copy all field.
     */
    public function testAddCopySetting()
    {
        $custom_copy = $this->_addCopyData();

        $id = array_get($custom_copy, 'id');

        $this->visit(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seePageIs(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seeOuterElement('input[id=label]', 'copy unit test')
            ->seeOuterElement('input[id=icon]', 'fa-android')
            ->seeOuterElement('input[id=button_class]', 'btn-info')
        ;

        foreach ($custom_copy->custom_copy_columns as $custom_copy_column) {
            $row_id = $custom_copy_column->id;
            $this->seeIsSelected(
                "custom_copy_columns[$row_id][from_column_target]",
                $custom_copy_column->from_column_target_id . '?table_id=' . $custom_copy_column->from_column_table_id
            );
            $this->seeIsSelected(
                "custom_copy_columns[$row_id][to_column_target]",
                $custom_copy_column->to_column_target_id . '?table_id=' . $custom_copy_column->to_column_table_id
            );
        }

        $options = ['ignore_attachment' => true];
        $this->exactSelectOptions('select.from_column_target', $this->getColumnSelectOptions(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $options));
        $this->exactSelectOptions('select.to_column_target', $this->getColumnSelectOptions(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $options));
    }

    /**
     * Update custom value copy input field.
     */
    public function testUpdateCopySetting()
    {
        $custom_copy = $this->_addCopyData();
        $this->_updateCopyData($custom_copy);

        $id = array_get($custom_copy, 'id');

        $this->visit(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seePageIs(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seeOuterElement('input[id=label]', 'copy unit test update')
        ;

        $custom_copy = CustomCopy::find($id);

        foreach ($custom_copy->custom_copy_columns as $custom_copy_column) {
            $row_id = $custom_copy_column->id;
            $this->seeIsSelected(
                "custom_copy_columns[$row_id][from_column_target]",
                $custom_copy_column->from_column_target_id . '?table_id=' . $custom_copy_column->from_column_table_id
            );
            $this->seeIsSelected(
                "custom_copy_columns[$row_id][to_column_target]",
                $custom_copy_column->to_column_target_id . '?table_id=' . $custom_copy_column->to_column_table_id
            );
        }

        foreach ($custom_copy->custom_copy_input_columns as $custom_copy_column) {
            $row_id = $custom_copy_column->id;
            $this->seeIsSelected(
                "custom_copy_input_columns[$row_id][to_column_target]",
                $custom_copy_column->to_column_target_id . '?table_id=' . $custom_copy_column->to_column_table_id
            );
        }

        $options = ['ignore_attachment' => true];
        $this->exactSelectOptions('select.from_column_target', $this->getColumnSelectOptions(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $options));
        $this->exactSelectOptions('select.to_column_target', $this->getColumnSelectOptions(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $options));
        $this->exactSelectOptions('select.custom_copy_input_columns', $this->getColumnSelectOptions(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $options));
    }

    /**
     * Copy custom value in same table.
     */
    public function testCustomValueCopySameTable()
    {
        $custom_copy = $this->_addCopyData(true);

        $this->_executeCopy($custom_copy);
    }

    /**
     * Copy custom value all field.
     */
    public function testCustomValueCopyAll()
    {
        $custom_copy = $this->_addCopyData();

        $this->_executeCopy($custom_copy);
    }

    /**
     * Copy custom value all field.
     */
    public function testCustomValueCopyInput()
    {
        $custom_copy = $this->_addCopyData();
        $this->_updateCopyData($custom_copy);

        $this->_executeCopy($custom_copy, [
            'user' => TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
            'odd_even' => 'even',
            'integer' => '8976',
            'currency' => '12345.6',
            'email' => 'test@mail.com',
        ]);
    }

    /**
     * Copy custom value.
     */
    public function _executeCopy($custom_copy, $data = [])
    {
        $from_table_name = $custom_copy->from_custom_table->table_name;
        $to_table_name = $custom_copy->to_custom_table->table_name;

        $to_table = $custom_copy->to_custom_table->getValueModel();

        $pre_cnt = $to_table->count();

        $data['uuid'] = array_get($custom_copy, 'suuid');

        $custom_value = $custom_copy->from_custom_table->getValueModel()->first();
        $id = $custom_value->id;

        $this->post(admin_url("data/$from_table_name/$id/copyClick"), $data);
        $this->assertPostResponse($this->response, admin_url("data/$from_table_name/$id/copyClick"));

        $this->assertEquals($pre_cnt + 1, $to_table->count());

        $new_value = $to_table->orderBy('id', 'desc')->first();
        $this->visit(admin_url("data/$to_table_name/" . $new_value->id));

        $custom_copy_columns = CustomCopyColumn::where('custom_copy_id', $custom_copy->id)->get();

        foreach ($custom_copy_columns as $custom_copy_column) {
            if ($custom_copy_column->copy_column_type == CopyColumnType::DEFAULT) {
                $this->seeInElement(
                    'div.box-body',
                    $custom_value->getValue($custom_copy_column->to_custom_column->column_name, true)
                );
            } else {
                $input = array_get($data, $custom_copy_column->to_custom_column->column_name);
                if (isset($input)) {
                    $this->seeInElement('div.box-body', $input);
                }
            }
        }
    }

    /**
     * Update custom value copy contains field.
     */
    protected function _updateCopyData($copy, bool $with_input = false)
    {
        $id = $copy->id;

        $from_table = $copy->from_custom_table;
        $to_table = $copy->to_custom_table;

        $pre_default_cnt = CustomCopyColumn::where('copy_column_type', CopyColumnType::DEFAULT)->count();
        $pre_input_cnt = CustomCopyColumn::where('copy_column_type', CopyColumnType::INPUT)->count();

        $data = [
            'options' => [
                'label' => 'copy unit test update',
            ],
            'custom_copy_columns' => [],
            'custom_copy_input_columns' => [],
        ];

        $new_idx = 0;
        foreach ($copy->custom_copy_columns as $index => $custom_copy_column) {
            if ($index % 2 == 1) {
                $data['custom_copy_columns'][$custom_copy_column->id] = [
                    'id' => $custom_copy_column->id,
                    '_remove_' => 1,
                ];
                $new_idx++;
                $data['custom_copy_input_columns']["new_$new_idx"] = [
                    'id' => $custom_copy_column->id,
                    'to_column_target' => $custom_copy_column->to_column_target,
                    'copy_column_type' => CopyColumnType::INPUT,
                    '_remove_' => 0,
                ];
            } else {
                $data['custom_copy_columns'][$custom_copy_column->id] = [
                    'id' => $custom_copy_column->id,
                    'from_column_target' => $custom_copy_column->from_column_target,
                    'to_column_target' => $custom_copy_column->to_column_target,
                    'copy_column_type' => $custom_copy_column->copy_column_type,
                    '_remove_' => 0,
                ];
            }
        }
        $this->put(admin_url("copy/custom_value_edit_all/$id"), $data);
        $this->assertPostResponse($this->response, admin_url("copy/custom_value_edit_all"));

        $this->assertEquals(
            $pre_default_cnt - $new_idx,
            CustomCopyColumn::where('copy_column_type', CopyColumnType::DEFAULT)->count()
        );
        $this->assertEquals(
            $pre_input_cnt + $new_idx,
            CustomCopyColumn::where('copy_column_type', CopyColumnType::INPUT)->count()
        );
    }

    /**
     * Create custom value copy contains field.
     */
    protected function _addCopyData($is_same = false)
    {
        $from_table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $to_table_name = $is_same ? TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL : TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;

        $pre_cnt = CustomCopy::count();
        $pre_child_cnt = CustomCopyColumn::count();
        $from_table = CustomTable::getEloquent($from_table_name);
        $to_table = CustomTable::getEloquent($to_table_name);

        $data = [
            'from_custom_table_id' => $from_table->id,
            'to_custom_table_id' => $to_table->id,
            'options' => [
                'label' => 'copy unit test',
                'icon' => 'fa-android',
                'button_class' => 'btn-info',
            ],
            'custom_copy_columns' => [],
            'custom_copy_input_columns' => [],
        ];

        $column_count = 0;

        foreach ($from_table->custom_columns as $index => $from_column) {
            if ($from_column->column_type == ColumnType::AUTO_NUMBER || ColumnType::isAttachment($from_column->column_type)) {
                continue;
            }
            $row_id = 'new_' . ($index + 1);
            if ($is_same) {
                $to_column = $from_column;
            } else {
                $to_column = CustomColumn::getEloquent($from_column->column_name, $to_table);
            }
            $data['custom_copy_columns'][$row_id] = [
                'from_column_target' => "{$from_column->id}?table_id={$from_table->id}",
                'to_column_target' => "{$to_column->id}?table_id={$to_table->id}",
                'copy_column_type' => CopyColumnType::DEFAULT,
                '_remove_' => 0,
            ];
            $column_count++;
        }

        $this->post(admin_url('copy/custom_value_edit_all'), $data);
        $this->assertPostResponse($this->response, admin_url('copy/custom_value_edit_all'));

        $this->visit(admin_url('copy/custom_value_edit_all'))
            ->seePageIs(admin_url('copy/custom_value_edit_all'))
            ->seeInElement('td', $from_table_name)
            ->seeInElement('td', $to_table_name)
            ->seeInElement('td', 'copy unit test')
            ->assertEquals($pre_cnt + 1, CustomCopy::count());

        $this->assertEquals($pre_child_cnt + $column_count, CustomCopyColumn::count())
        ;

        $raw = CustomCopy::orderBy('id', 'desc')->first();

        return $raw;
    }
}
