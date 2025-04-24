<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;

class CCustomFormTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
        $this->prepareTestTables();
     }

    /**
     * prepare test table and columns.
     */
    protected function prepareTestTables()
    {
        $this->createCustomTable('exmenttest_form');
        $this->createCustomColumns('exmenttest_form', ['integer', 'text', 'datetime', 'select', 'boolean', 'yesno', 'image']);
    }

    protected function tearDown(): void
    {
        if (($custom_table = CustomTable::getEloquent('exmenttest_form')) != null) {
            $custom_table->delete();
        }
    }
    
    /**
     * Check custom form display.
     */
    public function testDisplayFormSetting()
    {
        // Check custom column form
        $this->visit(admin_url('form/exmenttest_form'))
                ->seePageIs(admin_url('form/exmenttest_form'))
                ->see('カスタムフォーム設定')
                ->seeInElement('th', 'テーブル名(英数字)')
                ->seeInElement('th', 'テーブル表示名')
                ->seeInElement('th', 'フォーム表示名')
                ->seeInElement('th', '操作')
                ->visit(admin_url('form/exmenttest_form/create'))
                ->seeInElement('h1', 'カスタムフォーム設定')
                ->seeInElement('label', 'フォーム表示名')
                ->seeInElement('h3[class=box-title]', exmtrans('custom_form.header_basic_setting'))
                ->seeInElement('h3[class=box-title]', 'テーブル - Exmenttest Form')
                ->seeInElement('label', 'フォームブロック名')
                ->seeInElement('h4', 'フォーム項目')
                ->seeInElement('h5', 'フォーム項目 候補一覧')
                ->seeInElement('h5', 'フォーム項目 候補一覧')
                ->seeInElement('span', 'Integer')
                ->seeInElement('span', 'One Line Text')
                ->seeInElement('span', 'Date and Time')
                ->seeInElement('span', 'Select From Static Value')
                ->seeInElement('span', 'Select 2 value')
                ->seeInElement('span', 'Yes No')
                ->seeInElement('span', 'Image')
                ->seeInElement('span', '見出し')
                ->seeInElement('span', '説明文')
                ->seeInElement('span', 'HTML')
        ;
    }

    /**
     * Create custom form.
     */
    public function testAddFormSuccess()
    {
        $custom_table = CustomTable::where('table_name', 'exmenttest_form')->first();
        $custom_table_id = array_get($custom_table, 'id');

        $pre_cnt = CustomForm::count();

        // Create custom form
        $this->visit(admin_url('form/exmenttest_form/create'))
                ->type('新しいフォーム', 'form_view_name')
                ->press('admin-submit')
                ->seePageIs(admin_url('form/exmenttest_form'))
                ->seeInElement('td', '新しいフォーム')
                ->assertEquals($pre_cnt + 1, CustomForm::count())
        ;

        $raw = CustomForm::where('custom_table_id', $custom_table_id)->orderBy('id', 'desc')->first();
        $id = array_get($raw, 'id');

        // Update custom form
        $this->visit(admin_url('form/exmenttest_form/'. $id . '/edit'))
                ->seeInField('form_view_name', '新しいフォーム')
                ->type('更新したフォーム', 'form_view_name')
                ->press('admin-submit')
                ->seePageIs(admin_url('form/exmenttest_form'))
                ->seeInElement('td', '更新したフォーム');

        $block = CustomFormBlock::where('custom_form_id', $id)->where('form_block_type', '0')->first();
        $block_id = array_get($block, 'id');

        $columns = CustomColumn::where('custom_table_id', $custom_table_id)->get();

        // add field column
        foreach($columns as $idx => $column) {
            $form_column = new CustomFormColumn();
            $form_column->custom_form_block_id = $block_id;
            $form_column->form_column_type = 0;
            $form_column->form_column_target_id = $column->id;
            $form_column->row_no = 1;
            $form_column->column_no = 1;
            $form_column->width = 2;
            $form_column->order = $idx + 1;
            $form_column->save();
        }

        $this->visit(admin_url('data/exmenttest_form/create'))
            ->seeInElement('label', 'Integer')
            ->seeInElement('label', 'One Line Text')
            ->seeInElement('label', 'Date and Time')
            ->seeInElement('label', 'Select From Static Value')
            ->seeInElement('label', 'Select 2 value')
            ->seeInElement('label', 'Yes No')
            ->seeInElement('label', 'Image')
        ;
    }

    /**
     * avairable relation blocks.
     */
    public function testRelationFormSuccess()
    {
        $custom_table = CustomTable::where('table_name', 'parent_table')->first();
        $custom_table_id = array_get($custom_table, 'id');

        $raw = CustomForm::where('custom_table_id', $custom_table_id)->where('default_flg', 1)->first();
        $id = array_get($raw, 'id');

        $blocks = CustomFormBlock::where('custom_form_id', $id)->get();

        $relations = CustomRelation::getRelationsByParent($custom_table);
        foreach ($relations as $idx => $relation) {
            $block = $blocks->first(function ($val) use($relation) {
                return $val->form_block_target_table_id == $relation->child_custom_table_id;
            });
            if (!$block) {
                $block = new CustomFormBlock();
                $block->custom_form_id = $id;
                $block->form_block_type = 1;
                $block->form_block_target_table_id = $relation->child_custom_table_id;
            } 
            $block->setOption('form_block_order', $idx);
            $block->available = 1;
            $block->save();
        }

        $block_ids = CustomFormBlock::where('custom_form_id', $id)->pluck('id');

        $this->visit(admin_url('form/parent_table/'. $id . '/edit'))
            ->seeInElement('h3[class=box-title]', 'テーブル - parent_table')
            ->seeInElement('h3[class=box-title]', '子テーブル - child_table')
            ->seeInElement('h3[class=box-title]', '子テーブル - child_table_2')
            ->type('親テーブルのブロック', 'custom_form_blocks[' . $block_ids[0] .'][form_block_view_name]')
            ->type('子テーブル１のブロック', 'custom_form_blocks[' . $block_ids[1] .'][form_block_view_name]')
            ->type('子テーブル２のブロック', 'custom_form_blocks[' . $block_ids[2] .'][form_block_view_name]')
            ->press('admin-submit')
            ->seePageIs(admin_url('form/parent_table'))
        ;

        $this->visit(admin_url('form/parent_table/'. $id . '/edit'))
            ->seeInField('custom_form_blocks[' . $block_ids[0] .'][form_block_view_name]', '親テーブルのブロック')
            ->seeInField('custom_form_blocks[' . $block_ids[1] .'][form_block_view_name]', '子テーブル１のブロック')
            ->seeInField('custom_form_blocks[' . $block_ids[2] .'][form_block_view_name]', '子テーブル２のブロック')
        ;

        // check before update
        $this->visit(admin_url('data/parent_table/create'));
        $crawler = $this->crawler()->filter('h4[class=field-header]');
        $element = $crawler->eq(0);
        $this->assertEquals('子テーブル１のブロック', $element->text());
        $element = $crawler->eq(1);
        $this->assertEquals('子テーブル２のブロック', $element->text());

        $this->visit(admin_url('form/parent_table/'. $id . '/edit'))
                ->type('2', 'custom_form_blocks[' . $block_ids[1] .'][options][form_block_order]')
                ->type('1', 'custom_form_blocks[' . $block_ids[2] .'][options][form_block_order]')
                ->press('admin-submit')
                ->seePageIs(admin_url('form/parent_table'))
        ;

        // check after update
        $this->visit(admin_url('data/parent_table/create'));
        $crawler = $this->crawler()->filter('h4[class=field-header]');
        $element = $crawler->eq(0);
        $this->assertEquals('子テーブル２のブロック', $element->text());
        $element = $crawler->eq(1);
        $this->assertEquals('子テーブル１のブロック', $element->text());
    }
}
