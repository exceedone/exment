<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;

class CCustomColumnTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->login();
    }

    /**
     * Check custom column display.
     */
    public function testDisplayColummSetting()
    {
        // Check custom column form
        $this->visit(admin_url('column/test'))
                ->seePageIs(admin_url('column/test'))
                ->see('カスタム列設定')
                ->seeInElement('th', '列名(英数字)')
                ->seeInElement('th', '列表示名')
                ->seeInElement('th', '列種類')
                ->visit(admin_url('column/test/create'))
                ->seeInElement('h3[class=box-title]', '作成')
                ->seeInElement('label', '列名(英数字)')
                ->seeInElement('label', '列表示名')
                ->seeInElement('label', '列種類')
                ->seeInElement('label', '必須')
                ->seeInElement('label', '検索インデックス')
                ->seeInElement('label', 'ユニーク(一意)')
                ->seeInElement('label', '初期値')
                ->seeInElement('label', 'プレースホルダー')
                ->seeInElement('label', 'ヘルプ')
                ->seeInElement('label', '既定のフォームに追加する')
                ->seeInElement('label', '既定のビューに追加する');
    }
    // Create custom column --one line--
    public function testAddOneLineTextColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --one line--
        $response = $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('onelinetext', 'column_name')
                ->type('One Line Text', 'column_view_name')
                ->select('text', 'column_type')
                ->seeInElement('label', '最大文字数')
                ->seeInElement('label', '使用可能文字')
                ->type('256', 'options[string_length]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'onelinetext')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --one line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('onelinetext')
            ->seeInField('column_view_name', 'One Line Text')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.text'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[string_length]', 256)
;
        $form = [
            'column_view_name' => 'One Line Text Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[default]' => 'あああ',
            'options[string_length]' => 128,
            'options[available_characters]' => ['lower','upper','number','hyphen_underscore','dot','symbol'],
        ];
        // Update custom column --one line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'One Line Text Update');

        // Check custom column --one line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('onelinetext')
            ->seeInField('column_view_name', 'One Line Text Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[default]', 'あああ')
            ->seeInField('options[string_length]', 128)
            ->seeInField('options[available_characters][]', 'lower')
;
    }

    // Create custom column --Multi line--
    public function testAddMultiLineTextColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Multi line--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('multilinetext', 'column_name')
                ->type('Multi Line Text', 'column_view_name')
                ->select('textarea', 'column_type')
                ->seeInElement('label', '最大文字数')
                ->type('512', 'options[string_length]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'multilinetext')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Multi line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('multilinetext')
            ->seeInField('column_view_name', 'Multi Line Text')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.textarea'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[string_length]', 512)
;
        $form = [
            'column_view_name' => 'Multi Line Text Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[default]' => 'あああ',
            'options[string_length]' => 256,
        ];
        // Update custom column --Multi line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Multi Line Text Update');

        // Check custom column --Multi line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('multilinetext')
            ->seeInField('column_view_name', 'Multi Line Text Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[default]', 'あああ')
            ->seeInField('options[string_length]', 256)
;
    }

    // Create custom column --Editor--
    public function testAddEditorColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Editor--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('editor_col', 'column_name')
                ->type('Editor Column', 'column_view_name')
                ->select('editor', 'column_type')
                ->type('テキストエディタのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'editor_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Editor--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('editor_col')
            ->seeInField('column_view_name', 'Editor Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.editor'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'テキストエディタのヘルプ')
;
        $form = [
            'column_view_name' => 'Editor Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Editor--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Editor Column Update');

        // Check custom column --Editor--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('editor_col')
            ->seeInField('column_view_name', 'Editor Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --URL--
    public function testAddURLColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --URL--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('url_col', 'column_name')
                ->type('URL Column', 'column_view_name')
                ->select('url', 'column_type')
                ->type('URLのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'url_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --URL--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('url_col')
            ->seeInField('column_view_name', 'URL Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.url'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'URLのヘルプ')
;
        $form = [
            'column_view_name' => 'URL Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --URL--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'URL Column Update');

        // Check custom column --URL--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('url_col')
            ->seeInField('column_view_name', 'URL Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Email--
    public function testAddEmailColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Email--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('email_col', 'column_name')
                ->type('Email Column', 'column_view_name')
                ->select('email', 'column_type')
                ->type('Emailのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'email_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Email--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('email_col')
            ->seeInField('column_view_name', 'Email Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.email'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'Emailのヘルプ')
;
        $form = [
            'column_view_name' => 'Email Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Email--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Email Column Update');

        // Check custom column --Email--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('email_col')
            ->seeInField('column_view_name', 'Email Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Integer--
    public function testAddIntegerColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Integer--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('integer_col', 'column_name')
                ->type('Integer Column', 'column_view_name')
                ->select('textarea', 'column_type')
                ->seeInElement('label', '最小値')
                ->seeInElement('label', '最大値')
                ->seeInElement('label', '数値 カンマ文字列')
                ->seeInElement('label', '+-ボタン表示')
                ->seeInElement('label', '計算式')
                ->type(1, 'options[default]')
                ->type('整数のヘルプ', 'options[help]')
                ->type(-12345, 'options[number_min]')
                ->type(12345, 'options[number_max]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'integer_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Integer--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('integer_col')
            ->seeInField('column_view_name', 'Integer Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.textarea'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[default]', 1)
            ->seeInField('options[help]', '整数のヘルプ')
            ->seeInField('options[number_min]', -12345)
            ->seeInField('options[number_max]', 12345)
            ->seeInField('options[number_format]', 0)
            ->seeInField('options[updown_button]', 0)
            ->seeInField('options[calc_formula]', '')
;
        $form = [
            'column_view_name' => 'Integer Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[default]' => 123,
            'options[number_min]' => 0,
            'options[number_max]' => 999999,
            'options[number_format]' => 1,
            'options[updown_button]' => 1,
            'options[calc_formula]' => '[{"type":"symbol","val":"times"},{"type":"fixed","val":100}]',
        ];
        // Update custom column --Integer--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Integer Column Update');

        // Check custom column --Integer--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('integer_col')
            ->seeInField('column_view_name', 'Integer Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[default]', 123)
            ->seeInField('options[number_min]', 0)
            ->seeInField('options[number_max]', 999999)
            ->seeInField('options[number_format]', 1)
            ->seeInField('options[updown_button]', 1)
            ->seeInField('options[calc_formula]', '[{"type":"symbol","val":"times"},{"type":"fixed","val":100}]')
;
    }

    // Create custom column --Decimal--
    public function testAddDecimalColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Decimal--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('decimal_col', 'column_name')
                ->type('Decimal Column', 'column_view_name')
                ->select('textarea', 'column_type')
                ->seeInElement('label', '最小値')
                ->seeInElement('label', '最大値')
                ->seeInElement('label', '数値 カンマ文字列')
                ->seeInElement('label', '小数点以下の桁数')
                ->type(1, 'options[default]')
                ->type('小数のヘルプ', 'options[help]')
                ->type(-12345.67, 'options[number_min]')
                ->type(12345.67, 'options[number_max]')
                ->type(3, 'options[decimal_digit]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'decimal_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Decimal--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('decimal_col')
            ->seeInField('column_view_name', 'Decimal Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.textarea'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[default]', 1)
            ->seeInField('options[help]', '小数のヘルプ')
            ->seeInField('options[number_min]', -12345.67)
            ->seeInField('options[number_max]', 12345.67)
            ->seeInField('options[decimal_digit]', 3)
;
        $form = [
            'column_view_name' => 'Decimal Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[default]' => 123,
            'options[number_min]' => 0,
            'options[number_max]' => 999999,
            'options[decimal_digit]' => 0,
        ];
        // Update custom column --Decimal--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Decimal Column Update');

        // Check custom column --decimal line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('decimal_col')
            ->seeInField('column_view_name', 'Decimal Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[default]', 123)
            ->seeInField('options[number_min]', 0)
            ->seeInField('options[number_max]', 999999)
            ->seeInField('options[decimal_digit]', 0)
;
}

    // Create custom column --Currency--
    public function testAddCurrencyColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Currency--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('currency_col', 'column_name')
                ->type('Currency Column', 'column_view_name')
                ->select('currency', 'column_type')
                ->seeInElement('label', '最小値')
                ->seeInElement('label', '最大値')
                ->seeInElement('label', '数値 カンマ文字列')
                ->seeInElement('label', '小数点以下の桁数')
                ->seeInElement('label', '通貨の表示形式')
                ->seeInElement('label', '計算式')
                ->type(1, 'options[default]')
                ->type('通貨のヘルプ', 'options[help]')
                ->type(-12345.67, 'options[number_min]')
                ->type(12345.67, 'options[number_max]')
                ->type(3, 'options[decimal_digit]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'currency_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Currency--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('currency_col')
            ->seeInField('column_view_name', 'Currency Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.currency'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[default]', 1)
            ->seeInField('options[help]', '通貨のヘルプ')
            ->seeInField('options[number_min]', -12345.67)
            ->seeInField('options[number_max]', 12345.67)
            ->seeInField('options[decimal_digit]', 3)
            ->seeInField('options[currency_symbol]', '')
            ->seeInField('options[calc_formula]', '')
;
        $form = [
            'column_view_name' => 'Currency Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[default]' => 123,
            'options[number_min]' => 0,
            'options[number_max]' => 999999,
            'options[decimal_digit]' => 0,
            'options[currency_symbol]' => 'USD',
            'options[calc_formula]' => '[{"type":"symbol","val":"times"},{"type":"fixed","val":100}]',
        ];
        // Update custom column --Currency--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Currency Column Update');

        // Check custom column --Currency--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('currency_col')
            ->seeInField('column_view_name', 'Currency Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[default]', 123)
            ->seeInField('options[number_min]', 0)
            ->seeInField('options[number_max]', 999999)
            ->seeInField('options[decimal_digit]', 0)
            ->seeIsSelected('options[currency_symbol]', 'USD')
            ->seeInField('options[calc_formula]', '[{"type":"symbol","val":"times"},{"type":"fixed","val":100}]')
;
    }

    // Create custom column --Date--
    public function testAddDateColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Date--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('date_col', 'column_name')
                ->type('Date Column', 'column_view_name')
                ->select('date', 'column_type')
                ->type('日付のヘルプ', 'options[help]')
                ->type('2019/02/19', 'options[default]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'date_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Date--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('date_col')
            ->seeInField('column_view_name', 'Date Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.date'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '日付のヘルプ')
            ->seeInField('options[default]', '2019/02/19')
;
        $form = [
            'column_view_name' => 'Date Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Date--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Date Column Update');

        // Check custom column --Date--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('date_col')
            ->seeInField('column_view_name', 'Date Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Time--
    public function testAddTimeColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Time--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('time_col', 'column_name')
                ->type('Time Column', 'column_view_name')
                ->select('time', 'column_type')
                ->type('時間のヘルプ', 'options[help]')
                ->type('12:34:56', 'options[default]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'time_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Time--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('time_col')
            ->seeInField('column_view_name', 'Time Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.time'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '時間のヘルプ')
            ->seeInField('options[default]', '12:34:56')
;
        $form = [
            'column_view_name' => 'Time Column Uptime',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Uptime custom column --Time--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Time Column Uptime');

        // Check custom column --Time--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('time_col')
            ->seeInField('column_view_name', 'Time Column Uptime')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --DateTime--
    public function testAddDateTimeColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --DateTime--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('datetime_col', 'column_name')
                ->type('DateTime Column', 'column_view_name')
                ->select('datetime', 'column_type')
                ->type('日付と時間のヘルプ', 'options[help]')
                ->type('2019/02/19 11:22:33', 'options[default]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'datetime_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --DateTime--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('datetime_col')
            ->seeInField('column_view_name', 'DateTime Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.datetime'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '日付と時間のヘルプ')
            ->seeInField('options[default]', '2019/02/19 11:22:33')
;
        $form = [
            'column_view_name' => 'DateTime Column Updatetime',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Updatetime custom column --DateTime--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'DateTime Column Updatetime');

        // Check custom column --DateTime--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('datetime_col')
            ->seeInField('column_view_name', 'DateTime Column Updatetime')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Select--
    public function testAddSelectColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Select line--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('select_col', 'column_name')
                ->type('Select Column', 'column_view_name')
                ->select('select', 'column_type')
                ->seeInElement('label', '選択肢')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->type('選択1'."\n".'選択2'."\n".'選択3', 'options[select_item]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'select_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_col')
            ->seeInField('column_view_name', 'Select Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.select'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[select_item]', '選択1'."\n".'選択2'."\n".'選択3')
;
        $form = [
            'column_view_name' => 'Select Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Select line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Select Column Update');

        // Check custom column --Select line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_col')
            ->seeInField('column_view_name', 'Select Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Select Value Text--
    public function testAddSelectValueTextColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Select Value Text--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('select_valtext_col', 'column_name')
                ->type('Select Value Text Column', 'column_view_name')
                ->select('select_valtext', 'column_type')
                ->seeInElement('label', '選択肢')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢（値と見出し）のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->type('0,低い'."\n".'1,通常'."\n".'2,高い', 'options[select_item_valtext]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'select_valtext_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select Value Text--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_valtext_col')
            ->seeInField('column_view_name', 'Select Value Text Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.select_valtext'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢（値と見出し）のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[multiple_enabled]', 0)
            ->seeInField('options[select_item_valtext]', '0,低い'."\n".'1,通常'."\n".'2,高い')
;
        $form = [
            'column_view_name' => 'Select Value Text Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[multiple_enabled]' => 1,
        ];
        // Update custom column --Select Value Text--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Select Value Text Column Update');

        // Check custom column --Select Value Text--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_valtext_col')
            ->seeInField('column_view_name', 'Select Value Text Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[multiple_enabled]', 1)
;
    }

    // Create custom column --Select Table--
    public function testAddSelectTableColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        $table_id = array_get($table, 'id');

        // Create custom column --Select Table--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('select_table_col', 'column_name')
                ->type('Select Table Column', 'column_view_name')
                ->select('select_table', 'column_type')
                ->seeInElement('label', '対象テーブル')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢（テーブル）のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->select($table_id, 'options[select_target_table]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'select_table_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select Table--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_table_col')
            ->seeInField('column_view_name', 'Select Table Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.select_table'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢（テーブル）のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[multiple_enabled]', 0)
            ->seeIsSelected('options[select_target_table]', $table_id)
;
        $form = [
            'column_view_name' => 'Select Table Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[multiple_enabled]' => 1,
        ];
        // Update custom column --Select Table--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Select Table Column Update');

        // Check custom column --Select Table--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_table_col')
            ->seeInField('column_view_name', 'Select Table Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[multiple_enabled]', 1)
;
    }

    // Create custom column --YesNo--
    public function testAddYesNoColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --YesNo--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('yesno_col', 'column_name')
                ->type('YesNo Column', 'column_view_name')
                ->select('yesno', 'column_type')
                ->type('YES・Noのヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'yesno_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --YesNo--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('yesno_col')
            ->seeInField('column_view_name', 'YesNo Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.yesno'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'YES・Noのヘルプ')
            ->seeInField('options[default]', 0)
;
        $form = [
            'column_view_name' => 'YesNo Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --YesNo--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'YesNo Column Update');

        // Check custom column --YesNo--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('yesno_col')
            ->seeInField('column_view_name', 'YesNo Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Boolean--
    public function testAddBooleanColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Boolean--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->seeInElement('label', '選択肢1のときの値')
                ->seeInElement('label', '選択肢1のときの表示')
                ->seeInElement('label', '選択肢2のときの値')
                ->seeInElement('label', '選択肢2のときの表示')
                ->type('boolean_col', 'column_name')
                ->type('Boolean Column', 'column_view_name')
                ->select('boolean', 'column_type')
                ->type('2値の選択のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->type(1, 'options[true_value]')
                ->type('１番', 'options[true_label]')
                ->type(2, 'options[false_value]')
                ->type('２番', 'options[false_label]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'boolean_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Boolean--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('boolean_col')
            ->seeInField('column_view_name', 'Boolean Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.boolean'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '2値の選択のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[true_value]', 1)
            ->seeInField('options[true_label]', '１番')
            ->seeInField('options[false_value]', 2)
            ->seeInField('options[false_label]', '２番')
;
        $form = [
            'column_view_name' => 'Boolean Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Boolean--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Boolean Column Update');

        // Check custom column --Boolean--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('boolean_col')
            ->seeInField('column_view_name', 'Boolean Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --AutoNumber--
    public function testAddAutoNumberColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --AutoNumber--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->seeInElement('label', '採番種類')
                ->type('auto_number_col', 'column_name')
                ->type('AutoNumber Column', 'column_view_name')
                ->select('auto_number', 'column_type')
                ->type('採番種類のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->select('random25', 'options[auto_number_type]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'auto_number_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --AutoNumber--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('auto_number_col')
            ->seeInField('column_view_name', 'AutoNumber Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.auto_number'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '採番種類のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeIsSelected('options[auto_number_type]', 'random25')
;
        $form = [
            'column_view_name' => 'AutoNumber Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --AutoNumber--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'AutoNumber Column Update');

        // Check custom column --AutoNumber--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('auto_number_col')
            ->seeInField('column_view_name', 'AutoNumber Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --Image--
    public function testAddImageColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Image--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('image_col', 'column_name')
                ->type('Image Column', 'column_view_name')
                ->select('image', 'column_type')
                ->type('画像のヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'image_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Image--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('image_col')
            ->seeInField('column_view_name', 'Image Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.image'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '画像のヘルプ')
;
        $form = [
            'column_view_name' => 'Image Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --Image--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'Image Column Update');

        // Check custom column --Image--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('image_col')
            ->seeInField('column_view_name', 'Image Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --File--
    public function testAddFileColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --File--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('file_col', 'column_name')
                ->type('File Column', 'column_view_name')
                ->select('file', 'column_type')
                ->type('ファイルのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'file_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --File--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('file_col')
            ->seeInField('column_view_name', 'File Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.file'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'ファイルのヘルプ')
;
        $form = [
            'column_view_name' => 'File Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
        ];
        // Update custom column --File--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'File Column Update');

        // Check custom column --File--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('file_col')
            ->seeInField('column_view_name', 'File Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
;
    }

    // Create custom column --User--
    public function testAddUserColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --User--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('user_col', 'column_name')
                ->type('User Column', 'column_view_name')
                ->select('user', 'column_type')
                ->type('ユーザーのヘルプ', 'options[help]')
                ->seeInElement('label', '複数選択を許可する')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'user_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --User--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('user_col')
            ->seeInField('column_view_name', 'User Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.user'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', 'ユーザーのヘルプ')
            ->seeInField('options[multiple_enabled]', 0)
;
        $form = [
            'column_view_name' => 'User Column Update',
            'options[required]' => 1,
            'options[index_enabled]' => 1,
            'options[unique]' => 1,
            'options[multiple_enabled]' => 1,
        ];
        // Update custom column --User--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ->seeInElement('td', 'User Column Update');

        // Check custom column --User--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('user_col')
            ->seeInField('column_view_name', 'User Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[index_enabled]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[multiple_enabled]', 1)
;
    }

    // Create custom column --Organization--
    public function testAddOrganizationColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --Organization--
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->type('organization_col', 'column_name')
                ->type('Organization Column', 'column_view_name')
                ->select('organization', 'column_type')
                ->type('組織のヘルプ', 'options[help]')
                ->seeInElement('label', '複数選択を許可する')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->visit(admin_url('column/test?page=1&per_page=50'))
                ->seeInElement('td', 'organization_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Organization--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('organization_col')
            ->seeInField('column_view_name', 'Organization Column')
            ->seeInElement('span', exmtrans('custom_column.column_type_options.organization'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '組織のヘルプ')
            ->seeInField('options[multiple_enabled]', 0)
;
        $form = [
            'column_view_name' => 'Organization Column Update',
            'options[required]' => 1,
            'options[select_load_ajax]' => 1,
            'options[unique]' => 1,
            'options[multiple_enabled]' => 1,
        ];
        // Update custom column --Organization--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('column/test'))
                ;

        // Check custom column --Organization--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('organization_col')
            ->seeInField('column_view_name', 'Organization Column Update')
            ->seeInField('options[required]', 1)
            ->seeInField('options[select_load_ajax]', 1)
            ->seeInField('options[unique]', 1)
            ->seeInField('options[multiple_enabled]', 1)
        ;
    }

    // Create Custom Column Fail --Nothing Input--
    public function testAddFailWithMissingInfo()
    {
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->seeInElement('h3[class=box-title]', '作成')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test/create'))
        ;
    }

    // Create Custom Column Fail --Duplicate Column Name--
    public function testAddFailWithExistedColumnName()
    {
        $this->visit(admin_url('column/test/create'))
                ->seePageIs(admin_url('column/test/create'))
                ->seeInElement('h3[class=box-title]', '作成')
                ->type('onelinetext', 'column_name')
                ->type('One Line Text Duplicate', 'column_view_name')
                ->select('text', 'column_type')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test/create'))
        ;
    }
}

