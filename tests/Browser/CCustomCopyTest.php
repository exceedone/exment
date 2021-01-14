<?php

namespace Exceedone\Exment\Tests\Browser;

use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;
use Exceedone\Exment\Tests\TestDefine;

class CCustomCopyTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait, ColumnOptionQueryTrait;

    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->login();
    }

    /**
     * Check custom value copy display.
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
     * Create custom value copy contains field.
     */
    public function testAddCopySetting()
    {
        DB::beginTransaction();
        try {
            $this->_addCopyData();
        } finally {
            DB::rollback();
        }
    }

    /**
     * Update custom value copy contains field.
     */
    public function testUpdateCopySetting()
    {
        DB::beginTransaction();
        try {
            $custom_copy = $this->_addCopyData();
            $this->_updateCopyData($custom_copy);

        } finally {
            DB::rollback();
        }
    }

    /**
     * Create custom value copy contains field.
     */
    public function testAddCopySettingWithInput()
    {
        DB::beginTransaction();
        try {
            $this->_addCopyData(true);
        } finally {
            DB::rollback();
        }
    }

    /**
     * Update custom value copy contains field.
     */
    protected function _updateCopyData($copy, bool $with_input = false)
    {
        $id = $copy->id;

        $from_table = CustomTable::getEloquent('custom_value_edit_all');
        $from_column_0 = CustomColumn::getEloquent('date', $from_table);
        $from_column_1 = CustomColumn::getEloquent('odd_even', $from_table);
        $to_table = CustomTable::getEloquent('custom_value_view_all');
        $to_column_0 = CustomColumn::getEloquent('date', $to_table);
        $to_column_1 = CustomColumn::getEloquent('odd_even', $to_table);

        $data = [
            'options' => [
                'label' => 'copy unit test update',
            ],
            'custom_copy_columns' => [],
        ];

        foreach ($copy->custom_copy_columns as $index => $custom_copy_column) {
            $data['custom_copy_columns'][$custom_copy_column->id] = [
                'from_column_target' => ${'from_column_'.$index}->id ."?table_id={$from_table->id}",
                'to_column_target' => ${'to_column_'.$index}->id ."?table_id={$to_table->id}",
                'copy_column_type' => CopyColumnType::DEFAULT,
                '_remove_' => 0,
            ];
        }
        $this->put(admin_url("copy/custom_value_edit_all/$id"), $data);
        $this->assertPostResponse($this->response, admin_url("copy/custom_value_edit_all/$id/edit"));
    
        $this->visit(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seePageIs(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->see('copy unit test update')
            ->see('odd_even')
            ->see('date')
            ;
    }

    /**
     * Create custom value copy contains field.
     */
    protected function _addCopyData(bool $with_input = false)
    {
        $pre_cnt = CustomCopy::count();
        $from_table = CustomTable::getEloquent('custom_value_edit_all');
        $from_column_text = CustomColumn::getEloquent('text', $from_table);
        $from_column_user = CustomColumn::getEloquent('user', $from_table);
        $to_table = CustomTable::getEloquent('custom_value_view_all');
        $to_column_text = CustomColumn::getEloquent('text', $to_table);
        $to_column_user = CustomColumn::getEloquent('user', $to_table);

        $data = [
            'from_custom_table_id' => $from_table->id,
            'to_custom_table_id' => $to_table->id,
            'options' => [
                'label' => 'copy unit test',
                'icon' => 'fa-android',
                'button_class' => 'btn-info',
            ],
            'custom_copy_columns' => [
                'new_1' => [
                    'from_column_target' => "{$from_column_text->id}?table_id={$from_table->id}",
                    'to_column_target' => "{$to_column_text->id}?table_id={$to_table->id}",
                    'copy_column_type' => CopyColumnType::DEFAULT,
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'from_column_target' => "{$from_column_user->id}?table_id={$from_table->id}",
                    'to_column_target' => "{$to_column_user->id}?table_id={$to_table->id}",
                    'copy_column_type' => CopyColumnType::DEFAULT,
                    '_remove_' => 0,
                ],
            ],
        ];

        if ($with_input) {
            $to_column_integer= CustomColumn::getEloquent('integer', $to_table);
            $to_column_date= CustomColumn::getEloquent('date', $to_table);

            $data['custom_copy_input_columns'] = [
                'new_1' => [
                    'to_column_target' => "{$to_column_integer->id}?table_id={$to_table->id}",
                    'copy_column_type' => CopyColumnType::INPUT,
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'to_column_target' => "{$to_column_date->id}?table_id={$to_table->id}",
                    'copy_column_type' => CopyColumnType::INPUT,
                    '_remove_' => 0,
                ],
            ];
        }

        $this->post(admin_url('copy/custom_value_edit_all'), $data);
        $this->assertPostResponse($this->response, admin_url('copy/custom_value_edit_all'));

        $this->visit(admin_url('copy/custom_value_edit_all'))
            ->seePageIs(admin_url('copy/custom_value_edit_all'))
            ->seeInElement('td', 'custom_value_edit_all')
            ->seeInElement('td', 'custom_value_view_all')
            ->seeInElement('td', 'copy unit test')
            ->assertEquals($pre_cnt + 1, CustomCopy::count())
            ;
            
        $raw = CustomCopy::orderBy('created_at', 'desc')->first();
        $id = array_get($raw, 'id');

        $this->visit(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->seePageIs(admin_url("copy/custom_value_edit_all/$id/edit"))
            ->see('copy unit test')
            ->see('fa-android')
            ->see('btn-info')
            ->see('text')
            ->see('user')
            ;

        if ($with_input) {
            $this->see('date')
                ->see('integer');
        }

        return $raw;
    }
}
