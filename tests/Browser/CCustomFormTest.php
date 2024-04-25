<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomForm;

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
    }

    /**
     * prepare test table.
     */
    public function testPrepareTestTable()
    {
        $this->createCustomTable('exmenttest_form');
        $this->createCustomTable('exmenttest_form_relation');
    }

    /**
     * prepare test columns.
     */
    public function testPrepareTestColumn()
    {
        $targets = ['integer', 'text', 'datetime', 'select', 'boolean', 'yesno', 'image'];
        $this->createCustomColumns('exmenttest_form', $targets);
    }

    /**
     * prepare test columns and relation.
     */
    public function testPrepareTestColumnAndRelation()
    {
        $targets = ['integer', 'text', 'datetime', 'select', 'boolean', 'yesno', 'image'];
        $this->createCustomColumns('exmenttest_form_relation', $targets);
        $this->createCustomRelation('exmenttest_form', 'exmenttest_form_relation');
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
                ->seeInElement('h3[class=box-title]', '子テーブル - Exmenttest Form Relation')
                ->seeInElement('label', 'フォームブロック名')
                ->seeInElement('h4', 'フォーム項目')
                ->seeInElement('h5', 'フォーム項目 候補一覧')
                ->seeInElement('h5', 'フォーム項目 候補一覧')
                // ->seeInElement('span[class=item-label]', 'ID')
                // ->seeInElement('span[class=item-label]', '内部ID(20桁)')
                ->seeInElement('span', 'Integer')
                ->seeInElement('span', 'One Line Text')
                ->seeInElement('span', 'Date and Time')
                ->seeInElement('span', 'Select From Static Value')
                ->seeInElement('span', 'Select 2 value')
                ->seeInElement('span', 'Yes No')
                ->seeInElement('span', 'Image')
                // ->seeInElement('span[class=item-label]', '作成日時')
                // ->seeInElement('span[class=item-label]', '更新日時')
                // ->seeInElement('span[class=item-label]', '作成ユーザー')
                // ->seeInElement('span[class=item-label]', '更新ユーザー')
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
        $pre_cnt = CustomForm::count();

        // Create custom form
        $this->visit(admin_url('form/exmenttest_form/create'))
                ->type('新しいフォーム', 'form_view_name')
                ->press('admin-submit')
                ->seePageIs(admin_url('form/exmenttest_form'))
                ->seeInElement('td', '新しいフォーム')
                ->assertEquals($pre_cnt + 1, CustomForm::count())
        ;

        $raw = CustomForm::orderBy('id', 'desc')->first();
        $id = array_get($raw, 'id');

        // Update custom form
        $this->visit(admin_url('form/exmenttest_form/'. $id . '/edit'))
                ->seeInField('form_view_name', '新しいフォーム')
                ->type('更新したフォーム', 'form_view_name')
                ->press('admin-submit')
                ->seePageIs(admin_url('form/exmenttest_form'))
                ->seeInElement('td', '更新したフォーム');
    }
}
