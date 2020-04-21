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
        $this->visit('/admin/column/test')
                ->seePageIs('/admin/column/test')
                ->see('カスタム列設定')
                ->seeInElement('th', 'テーブル')
                ->seeInElement('th', '列名(英数字)')
                ->seeInElement('th', '列表示名')
                ->seeInElement('th', '列種類')
                ->visit('/admin/column/test/create')
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
        $this->visit('/admin/column/test/create')
                ->type('onelinetext', 'column_name')
                ->type('One Line Text', 'column_view_name')
                ->select('text', 'column_type')
                ->seeInElement('label', '最大文字数')
                ->seeInElement('label', '使用可能文字')
                ->type('256', 'options[string_length]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'onelinetext')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --one line--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('onelinetext')
            ->seeInField('column_view_name', 'One Line Text')
            ->seeIsSelected('column_type', 'text')
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
            'options[available_characters]' => ['lower','upper','number','hyphen_underscore','symbol'],
        ];
        // Update custom column --one line--
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'One Line Text Update');

        // Check custom column --one line--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('multilinetext', 'column_name')
                ->type('Multi Line Text', 'column_view_name')
                ->select('textarea', 'column_type')
                ->seeInElement('label', '最大文字数')
                ->type('512', 'options[string_length]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'multilinetext')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Multi line--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('multilinetext')
            ->seeInField('column_view_name', 'Multi Line Text')
            ->seeIsSelected('column_type', 'textarea')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Multi Line Text Update');

        // Check custom column --Multi line--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('editor_col', 'column_name')
                ->type('Editor Column', 'column_view_name')
                ->select('editor', 'column_type')
                ->type('テキストエディタのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'editor_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Editor--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('editor_col')
            ->seeInField('column_view_name', 'Editor Column')
            ->seeIsSelected('column_type', 'editor')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Editor Column Update');

        // Check custom column --Editor--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('url_col', 'column_name')
                ->type('URL Column', 'column_view_name')
                ->select('url', 'column_type')
                ->type('URLのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'url_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --URL--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('url_col')
            ->seeInField('column_view_name', 'URL Column')
            ->seeIsSelected('column_type', 'url')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'URL Column Update');

        // Check custom column --URL--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('email_col', 'column_name')
                ->type('Email Column', 'column_view_name')
                ->select('email', 'column_type')
                ->type('Emailのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'email_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Email--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('email_col')
            ->seeInField('column_view_name', 'Email Column')
            ->seeIsSelected('column_type', 'email')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Email Column Update');

        // Check custom column --Email--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
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
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'integer_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Integer--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('integer_col')
            ->seeInField('column_view_name', 'Integer Column')
            ->seeIsSelected('column_type', 'textarea')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Integer Column Update');

        // Check custom column --Integer--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
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
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'decimal_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Decimal--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('decimal_col')
            ->seeInField('column_view_name', 'Decimal Column')
            ->seeIsSelected('column_type', 'textarea')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Decimal Column Update');

        // Check custom column --decimal line--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
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
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'currency_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Currency--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('currency_col')
            ->seeInField('column_view_name', 'Currency Column')
            ->seeIsSelected('column_type', 'currency')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Currency Column Update');

        // Check custom column --Currency--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('date_col', 'column_name')
                ->type('Date Column', 'column_view_name')
                ->select('date', 'column_type')
                ->type('日付のヘルプ', 'options[help]')
                ->type('2019/02/19', 'options[default]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'date_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Date--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('date_col')
            ->seeInField('column_view_name', 'Date Column')
            ->seeIsSelected('column_type', 'date')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Date Column Update');

        // Check custom column --Date--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('time_col', 'column_name')
                ->type('Time Column', 'column_view_name')
                ->select('time', 'column_type')
                ->type('時間のヘルプ', 'options[help]')
                ->type('12:34:56', 'options[default]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'time_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Time--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('time_col')
            ->seeInField('column_view_name', 'Time Column')
            ->seeIsSelected('column_type', 'time')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Time Column Uptime');

        // Check custom column --Time--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('datetime_col', 'column_name')
                ->type('DateTime Column', 'column_view_name')
                ->select('datetime', 'column_type')
                ->type('日付と時間のヘルプ', 'options[help]')
                ->type('2019/02/19 11:22:33', 'options[default]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'datetime_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --DateTime--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('datetime_col')
            ->seeInField('column_view_name', 'DateTime Column')
            ->seeIsSelected('column_type', 'datetime')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'DateTime Column Updatetime');

        // Check custom column --DateTime--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('select_col', 'column_name')
                ->type('Select Column', 'column_view_name')
                ->select('select', 'column_type')
                ->seeInElement('label', '選択肢')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->type('選択1'."\n".'選択2'."\n".'選択3', 'options[select_item]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'select_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select line--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('select_col')
            ->seeInField('column_view_name', 'Select Column')
            ->seeIsSelected('column_type', 'select')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Select Column Update');

        // Check custom column --Select line--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('select_valtext_col', 'column_name')
                ->type('Select Value Text Column', 'column_view_name')
                ->select('select_valtext', 'column_type')
                ->seeInElement('label', '選択肢')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢（値と見出し）のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->type('0,低い'."\n".'1,通常'."\n".'2,高い', 'options[select_item_valtext]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'select_valtext_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select Value Text--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('select_valtext_col')
            ->seeInField('column_view_name', 'Select Value Text Column')
            ->seeIsSelected('column_type', 'select_valtext')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Select Value Text Column Update');

        // Check custom column --Select Value Text--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('select_table_col', 'column_name')
                ->type('Select Table Column', 'column_view_name')
                ->select('select_table', 'column_type')
                ->seeInElement('label', '対象テーブル')
                ->seeInElement('label', '複数選択を許可する')
                ->type('選択肢（テーブル）のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->select($table_id, 'options[select_target_table]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'select_table_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Select Table--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('select_table_col')
            ->seeInField('column_view_name', 'Select Table Column')
            ->seeIsSelected('column_type', 'select_table')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Select Table Column Update');

        // Check custom column --Select Table--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('yesno_col', 'column_name')
                ->type('YesNo Column', 'column_view_name')
                ->select('yesno', 'column_type')
                ->type('YES・Noのヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'yesno_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --YesNo--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('yesno_col')
            ->seeInField('column_view_name', 'YesNo Column')
            ->seeIsSelected('column_type', 'yesno')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'YesNo Column Update');

        // Check custom column --YesNo--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
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
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'boolean_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Boolean--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('boolean_col')
            ->seeInField('column_view_name', 'Boolean Column')
            ->seeIsSelected('column_type', 'boolean')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Boolean Column Update');

        // Check custom column --Boolean--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->seeInElement('label', '採番種類')
                ->type('auto_number_col', 'column_name')
                ->type('AutoNumber Column', 'column_view_name')
                ->select('auto_number', 'column_type')
                ->type('採番種類のヘルプ', 'options[help]')
                ->type(0, 'options[default]')
                ->select('random25', 'options[auto_number_type]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'auto_number_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --AutoNumber--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('auto_number_col')
            ->seeInField('column_view_name', 'AutoNumber Column')
            ->seeIsSelected('column_type', 'auto_number')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'AutoNumber Column Update');

        // Check custom column --AutoNumber--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('image_col', 'column_name')
                ->type('Image Column', 'column_view_name')
                ->select('image', 'column_type')
                ->type('画像のヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'image_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Image--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('image_col')
            ->seeInField('column_view_name', 'Image Column')
            ->seeIsSelected('column_type', 'image')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'Image Column Update');

        // Check custom column --Image--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('file_col', 'column_name')
                ->type('File Column', 'column_view_name')
                ->select('file', 'column_type')
                ->type('ファイルのヘルプ', 'options[help]')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'file_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --File--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('file_col')
            ->seeInField('column_view_name', 'File Column')
            ->seeIsSelected('column_type', 'file')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'File Column Update');

        // Check custom column --File--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('user_col', 'column_name')
                ->type('User Column', 'column_view_name')
                ->select('user', 'column_type')
                ->type('ユーザーのヘルプ', 'options[help]')
                ->seeInElement('label', '複数選択を許可する')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'user_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --User--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('user_col')
            ->seeInField('column_view_name', 'User Column')
            ->seeIsSelected('column_type', 'user')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ->seeInElement('td', 'User Column Update');

        // Check custom column --User--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->type('organization_col', 'column_name')
                ->type('Organization Column', 'column_view_name')
                ->select('organization', 'column_type')
                ->type('組織のヘルプ', 'options[help]')
                ->seeInElement('label', '複数選択を許可する')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test')
                ->visit('/admin/column/test?page=1&per_page=50')
                ->seeInElement('td', 'organization_col')
                ->assertEquals($pre_cnt + 1, CustomColumn::count())
;
        $row = CustomColumn::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Check custom column --Organization--
        $this->visit('/admin/column/test/'. $id . '/edit')
            ->see('organization_col')
            ->seeInField('column_view_name', 'Organization Column')
            ->seeIsSelected('column_type', 'organization')
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
        $this->visit('/admin/column/test/'. $id . '/edit')
                ->submitForm('admin-submit', $form)
                ->seePageIs('/admin/column/test')
                ;

        // Check custom column --Organization--
        $this->visit('/admin/column/test/'. $id . '/edit')
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
        $this->visit('/admin/column/test/create')
                ->seeInElement('h3[class=box-title]', '作成')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test/create')
        ;
    }

    // Create Custom Column Fail --Duplicate Column Name--
    public function testAddFailWithExistedColumnName()
    {
        $this->visit('/admin/column/test/create')
                ->seeInElement('h3[class=box-title]', '作成')
                ->type('onelinetext', 'column_name')
                ->type('One Line Text Duplicate', 'column_view_name')
                ->select('text', 'column_type')
                ->press('admin-submit')
                ->seePageIs('/admin/column/test/create')
        ;
    }


    // precondition : login success
//     public function testLoginSuccessWithTrueUsername()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/auth/login')
//                 ->type('username', 'testuser')
//                 ->type('password', 'test123456')
//                 ->press('Login')
//                 ->waitForText('Login successful')
//                 ->assertPathIs('/admin')
//                 ->assertTitle('Dashboard')
//                 ->assertSee('Dashboard');
//         });
//     }

// //     AutoTest_Column_01
//     public function testDisplayColummSetting()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->assertSee('Custom Column Detail Setting')
//                 ->assertSee('Setting details with customer list. these define required fields, searchable fields, etc.')
//                 ->assertSee('Showing to of 0 entries');
//         });
//     }

// //     AutoTest_Column_02
//     public function testDisplayCreateScreen()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->waitForText('New')
//                 ->clickLink('New')
//                 ->pause(5000)
//                 ->assertSeeIn('.box-title', 'Create')
//                 ->assertSee('Column Name')
//                 ->assertSee('Column View Name')
//                 ->assertSee('Column Type')
//                 ->assertSee('Required')
//                 ->assertSee('Search Index')
//                 ->assertSee('PlaceHolder')
//                 ->assertSee('Use Label');
//         });
//     }

//     // AutoTest_Column_03
//     public function testAddOneLineTextColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'onelinetext' )
//                 ->type('column_view_name', 'One Line Text')
//                 ->assertDontSee('Max Length')
//                 ->assertDontSee('Available Characters')
//                 ->select('column_type', 'text')
//                 ->assertSee('Max Length')
//                 ->assertSee('Available Characters')
//                 ->type('options[string_length]', '256')
//                 ->click('#available_characters  label.checkbox-inline:nth-child(1) div.icheckbox_minimal-blue')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('onelinetext');
//         });

//     }

// //     AutoTest_Column_04
//     public function testVerifyOneLineTextColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('onelinetext')
//                 ->assertValue('[name=column_view_name]', 'One Line Text')
//                 ->assertSelected('column_type', 'text')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=string_length]', 256)
//                 ->assertValue('#available_characters label.checkbox-inline:nth-child(1) div.checked [name*=available_characters]', 'lower');
//         });

//     }

// //     AutoTest_Column_05
//     public function testAddMultiLineTextColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'multilinetext' )
//                 ->type('column_view_name', 'Multi Line Text')
//                 ->assertDontSee('Max Length')
//                 ->select('column_type', 'textarea')
//                 ->assertSee('Max Length')
//                 ->type('options[string_length]', '256')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('multilinetext');
//         });
//     }

// //     AutoTest_Column_06
//     public function testVerifyMultiLineTextColumn()
//     {

//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('multilinetext')
//                 ->assertValue('[name=column_view_name]', 'Multi Line Text')
//                 ->assertSelected('column_type', 'textarea')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=string_length]', 256);
//         });
//     }

// //     AutoTest_Column_07
//     public function testAddDecimalColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'decimal' )
//                 ->type('column_view_name', 'Decimal')
//                 ->assertDontSee('Min Number')
//                 ->assertDontSee('Max Number')
//                 ->assertDontSee('Use Number Comma String')
//                 ->select('column_type', 'decimal')
//                 ->assertSee('Min Number')
//                 ->assertSee('Max Number')
//                 ->assertSee('Use Number Comma String')
//                 ->type('options[number_min]', '10')
//                 ->type('options[number_max]', '100');
//             $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('decimal');
//         });
//     }

// //     AutoTest_Column_08
//     public function testVerifyDecimalColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('decimal')
//                 ->assertValue('[name=column_view_name]', 'Decimal')
//                 ->assertSelected('column_type', 'decimal')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=string_length]', 256)
//                 ->assertValue('[name*=number_min]', 10)
//                 ->assertValue('[name*=number_max]', 100)
//                 ->assertValue('[name*=number_format]', 1);
//         });
//     }

// //     AutoTest_Column_09
//     public function testAddURLColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'url' )
//                 ->type('column_view_name', 'URL')
//                 ->select('column_type', 'url')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('url');
//         });
//     }

// //     AutoTest_Column_10
//     public function testVerifyURLColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('url')
//                 ->assertValue('[name=column_view_name]', 'URL')
//                 ->assertSelected('column_type', 'url')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

// //     AutoTest_Column_11
//     public function testAddEmailColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'email' )
//                 ->type('column_view_name', 'Email')
//                 ->select('column_type', 'email')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('email');
//         });
//     }

// //     AutoTest_Column_12
//     public function testVerifyEmailColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('email')
//                 ->assertValue('[name=column_view_name]', 'Email')
//                 ->assertSelected('column_type', 'email')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

// //     AutoTest_Column_13
//     public function testAddIntegerColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'integer' )
//                 ->type('column_view_name', 'Integer')
//                 ->assertDontSee('Min Number')
//                 ->assertDontSee('Max Number')
//                 ->assertDontSee('Use Number Comma String')
//                 ->select('column_type', 'integer')
//                 ->assertSee('Min Number')
//                 ->assertSee('Max Number')
//                 ->assertSee('Use Number Comma String')
//                 ->type('options[number_min]', '10')
//                 ->type('options[number_max]', '100');
//             $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('integer');
//         });
//     }

// //     AutoTest_Column_14
//     public function testVerifyIntegerColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('integer')
//                 ->assertValue('[name=column_view_name]', 'Integer')
//                 ->assertSelected('column_type', 'integer')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=string_length]', 256)
//                 ->assertValue('[name*=number_min]', 10)
//                 ->assertValue('[name*=number_max]', 100)
//                 ->assertValue('[name*=number_format]', 1);
//         });
//     }

// // AutoTest_Column_15
//     public function testAddCalcResultColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'calcresult' )
//                 ->type('column_view_name', 'Calc Result')
//                 ->assertDontSee('Calc Formula')
//                 ->assertDontSee('Use Number Comma String')
//                 ->select('column_type', 'calc')
//                 ->assertSee('Calc Formula')
//                 ->assertSee('Use Number Comma String')
//                 ->press('変更')
//                 ->pause(2000)
//                 ->assertSee('Calc Formula');
// //                ->click('button[data-val="66"]')
// //                ->click('button[data-val="69"]');
//             $browser->script('jQuery(\'.col-target-fixedval\').val(100)');
//             $browser
//                 ->pause(2000)
//                 ->click('button[data-type="fixed"]')
//                 ->click('button[data-val="plus"]')
//                 ->click('button[data-val="minus"]')
//                 ->click('button[data-val="times"]')
//                 ->click('button[data-val="div"]')
//                 ->press('Setting')
//                 ->pause(2000);
//             $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('calcresult');
//         });
//     }

//     // AutoTest_Column_16
//     public function testVerifyCalcResultColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('calcresult')
//                 ->assertValue('[name=column_view_name]', 'Calc Result')
//                 ->assertSelected('column_type', 'calc')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertSee( "100 ＋ － × ÷")
//                 ->assertValue('[name*=number_format]', 1)
//             ;
//         });
//     }

//     // AutoTest_Column_17
//     public function testAddDateColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'date' )
//                 ->type('column_view_name', 'Date')
//                 ->select('column_type', 'date')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('date');
//         });
//     }

//     // AutoTest_Column_18
//     public function testVerifyDateColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('date')
//                 ->assertValue('[name=column_view_name]', 'Date')
//                 ->assertSelected('column_type', 'date')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

//     // AutoTest_Column_19
//     public function testAddTimeColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'time' )
//                 ->type('column_view_name', 'Time')
//                 ->select('column_type', 'time')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('time');
//         });
//     }

//     // AutoTest_Column_20
//     public function testVerifyTimeColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('time')
//                 ->assertValue('[name=column_view_name]', 'Time')
//                 ->assertSelected('column_type', 'time')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

// //     AutoTest_Column_21
//     public function testAddDateAndTimeColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'dateandtime' )
//                 ->type('column_view_name', 'Date and Time')
//                 ->select('column_type', 'datetime')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('dateandtime');
//         });
//     }

//     // AutoTest_Column_22
//     public function testVerifyDateAndTimeColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('dateandtime')
//                 ->assertValue('[name=column_view_name]', 'Date and Time')
//                 ->assertSelected('column_type', 'datetime')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

// //     AutoTest_Column_23
//     public function testAddSelectFromStaticValueColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'selectfromstaticvalue' )
//                 ->type('column_view_name', "Select Froom Static Value")
//                 ->assertDontSee('Select Choice')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'select')
//                 ->assertSee('Select Choice')
//                 ->assertSee('Approval Multiple Select')
//                 ->type('options[select_item]', 'Adult \n Underage');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('selectfromstaticvalue');
//         });
//     }

//     // AutoTest_Column_24
//     public function testVerifySelectFromStaticValueColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('selectfromstaticvalue')
//                 ->assertValue('[name=column_view_name]', 'Select Froom Static Value')
//                 ->assertSelected('column_type', 'select')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=select_item]', 'Adult \n Underage')
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_25
//     public function testAddSelectSaveValueAndLabelColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'selectsavevalueandlabel' )
//                 ->type('column_view_name', "Select Save Value and Lable")
//                 ->assertDontSee('Select Choice')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'select_valtext')
//                 ->assertSee('Select Choice')
//                 ->assertSee('Approval Multiple Select')
//                 ->type('options[select_item_valtext]', '0,Adult \n 1,Underage');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('selectsavevalueandlabel');
//         });
//     }

//     // AutoTest_Column_26
//     public function testVerifySelectSaveValueAndLabelColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('selectsavevalueandlabel')
//                 ->assertValue('[name=column_view_name]', 'Select Save Value and Lable')
//                 ->assertSelected('column_type', 'select_valtext')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=select_item_valtext]', '0,Adult \n 1,Underage')
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_27
//     public function testAddSelectFromTableColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'selectfromtable' )
//                 ->type('column_view_name', "Select Froom Table")
//                 ->assertDontSee('Select Target Table')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'select_table')
//                 ->assertSee('Select Target Table')
//                 ->assertSee('Approval Multiple Select')
//                 ->select('options[select_target_table]', 1);
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('selectfromtable');
//         });
//     }

//     // AutoTest_Column_28
//     public function testVerifySelectFromTableColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('selectfromtable')
//                 ->assertValue('[name=column_view_name]', 'Select Froom Table')
//                 ->assertSelected('column_type', 'select_table')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertSelected('options[select_target_table]', 1)
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_29
//     public function testAddYesNoColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'yesno' )
//                 ->type('column_view_name', 'Yes No')
//                 ->select('column_type', 'yesno')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('yesno');
//         });
//     }

//     // AutoTest_Column_30
//     public function testVerifyYesNoColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('yesno')
//                 ->assertValue('[name=column_view_name]', 'Yes No')
//                 ->assertSelected('column_type', 'yesno')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

//     // AutoTest_Column_31
//     public function testAddSelect2ValueColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'select2value' )
//                 ->type('column_view_name', "Select 2 value")
//                 ->assertDontSee('Select1 Value')
//                 ->assertDontSee('Select2 Value')
//                 ->assertDontSee('Select2 Label')
//                 ->select('column_type', 'boolean')
//                 ->assertSee('Select1 Value')
//                 ->assertSee('Select2 Value')
//                 ->assertSee('Select2 Label')
//                 ->type('options[true_value]', "value1")
//                 ->type('options[true_label]', "label1")
//                 ->type('options[false_value]', "value2")
//                 ->type('options[false_label]', "label2");
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('select2value');
//         });
//     }

//     // AutoTest_Column_32
//     public function testVerifySelect2ValueColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('select2value')
//                 ->assertValue('[name=column_view_name]', 'Select 2 value')
//                 ->assertSelected('column_type', 'boolean')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=true_value]', "value1")
//                 ->assertValue('[name*=true_label]', "label1")
//                 ->assertValue('[name*=false_value]', "value2")
//                 ->assertValue('[name*=false_label]', "label2");
//         });
//     }

//     // AutoTest_Column_33
//     public function testAddAutoNumberColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'autonumber' )
//                 ->type('column_view_name', 'Auto Number')
//                 ->assertDontSee('Auto Number Type')
//                 ->select('column_type', 'auto_number')
//                 ->assertSee('Auto Number Type')
//                 ->select('options[auto_number_type]', 'random25')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('autonumber');
//         });
//     }

//     // AutoTest_Column_34
//     public function testVerifyAutoNumberColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('autonumber')
//                 ->assertValue('[name=column_view_name]', 'Auto Number')
//                 ->assertSelected('column_type', 'auto_number')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertSelected('options[auto_number_type]', 'random25');
//         });
//     }

//     // AutoTest_Column_35
//     public function testAddImageColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'image' )
//                 ->type('column_view_name', 'Image')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'image')
//                 ->assertSee('Approval Multiple Select');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('image');
//         });
//     }

//     // AutoTest_Column_36
//     public function testVerifyImageColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('image')
//                 ->assertValue('[name=column_view_name]', 'Image')
//                 ->assertSelected('column_type', 'image')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_37
//     public function testAddFileColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'file' )
//                 ->type('column_view_name', 'File')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'file')
//                 ->assertSee('Approval Multiple Select');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('file');
//         });
//     }

//     // AutoTest_Column_38
//     public function testVerifyFileColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('file')
//                 ->assertValue('[name=column_view_name]', 'File')
//                 ->assertSelected('column_type', 'file')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_39
//     public function testAddUserColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'user' )
//                 ->type('column_view_name', 'User')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'user')
//                 ->assertSee('Approval Multiple Select');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('user');
//         });
//     }

//     // AutoTest_Column_40
//     public function testVerifyUserColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('user')
//                 ->assertValue('[name=column_view_name]', 'User')
//                 ->assertSelected('column_type', 'user')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_41
//     public function testAddOrganizationColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'organization' )
//                 ->type('column_view_name', 'Organization')
//                 ->assertDontSee('Approval Multiple Select')
//                 ->select('column_type', 'organization')
//                 ->assertSee('Approval Multiple Select');
//             $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
//             $browser->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('organization');
//         });
//     }

//     // AutoTest_Column_42
//     public function testVerifyOrganizationColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('organization')
//                 ->assertValue('[name=column_view_name]', 'Organization')
//                 ->assertSelected('column_type', 'organization')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0)
//                 ->assertValue('[name*=multiple_enabled]', 1);
//         });
//     }

//     // AutoTest_Column_43
//     public function testAddDocumentColumnSuccess()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->type('column_name', 'document' )
//                 ->type('column_view_name', 'Document')
//                 ->select('column_type', 'document')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertPathIs('/admin/column/test')
//                 ->assertSee('document');
//         });
//     }

//     // AutoTest_Column_44
//     public function testVerifyDocumentColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->click('table tr:last-child .fa.fa-edit')
//                 ->pause(5000)
//                 ->assertSee('document')
//                 ->assertValue('[name=column_view_name]', 'Document')
//                 ->assertSelected('column_type', 'document')
//                 ->assertValue('[name*=search_enabled]', 0)
//                 ->assertValue('[name*=required]', 0)
//                 ->assertValue('[name*=use_label_flg]', 0);
//         });
//     }

//     // AutoTest_Column_45
//     public function testAddFailWithMissingInfo()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->assertSeeIn('.box-title', 'Create')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertVisible('.has-error')
//                 ->assertSee('The column name field is required.')
//                 ->assertSee('The column view name field is required.')
//                 ->assertSee('The column type field is required.');
//         });
//     }

//     // AutoTest_Column_46
//     public function testAddFailWithExistedColumnName()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test/create')
//                 ->assertSeeIn('.box-title', 'Create')
//                 ->type('column_name', 'onelinetext')
//                 ->press('Submit')
//                 ->pause(5000)
//                 ->assertVisible('.has-error')
//                 ->assertSee('validation.unique_in_table');
//         });
//     }

//     // AutoTest_Column_47
//     public function testEditOneLineTextColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')
//                 ->assertSee('onelinetext');
//             $browser->script('$(".table-hover td").filter(function(){return $.trim($(this).text()) == "onelinetext"}).closest("tr").click();');
//             $browser->pause(5000)
//                 ->type('column_view_name', 'One Line Text Edited')
//                 ->select('column_type', 'text')
//                 ->press('Submit')
//                 ->waitForText('Save Successful')
//                 ->assertSee('One Line Text Edited')
//                 ->assertPathIs('/admin/column/test');
//         });
//     }

//     // AutoTest_Column_48
//     public function testDropOneLineTextColumn()
//     {
//         $this->browse(function (Browser $browser) {
//             $browser->visit('/admin/column/test')->assertSee('onelinetext');
//             $browser->script('$(".table-hover td").filter(function(){return $.trim($(this).text()) == "onelinetext"}).closest("tr").find("a.grid-row-delete").click();');
//             $browser->pause(5000)
//                 ->press('Confirm')
// 				->waitForText('Delete succeeded !')
//                 ->press('Ok')
//                 ->assertDontSee('onelinetext')
//                 ->assertPathIs('/admin/column/test');
//         });
//     }
}

