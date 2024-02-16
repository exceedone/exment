<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnDefaultType;

/**
 * custom column test.
 * v4.1.0, custom column option dynamic change. So cannot test seein.
 */
class CCustomColumnTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
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
                ->matchStatusCode(200)
                ->seeInElement('h3[class=box-title]', '作成')
                ->seeInElement('label', '列名(英数字)')
                ->seeInElement('label', '列表示名')
                ->seeInElement('label', '列種類')
                ->seeInElement('label', '必須')
                ->seeInElement('label', '検索インデックス')
                ->seeInElement('label', 'ユニーク(一意)')
                //->seeInElement('label', '初期値')
                ->seeInElement('label', 'プレースホルダー')
                ->seeInElement('label', 'ヘルプ')
                ->seeInElement('label', '既定のフォームに追加する')
                ->seeInElement('label', '既定のビューに追加する');
    }
    // Create custom column --one line--
    public function testAddOneLineTextColumnSuccess()
    {
        $pre_cnt = CustomColumn::count();

        // Create custom column --one line--;
        $this->post(admin_url('column/test'), [
            'column_name' => 'onelinetext',
            'column_view_name' => 'One Line Text',
            'column_type' => 'text',
            'options' => [
                'string_length' => 256,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'onelinetext')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --one line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('onelinetext')
            ->seeInField('column_view_name', 'One Line Text')
            ->see(exmtrans('custom_column.column_type_options.text'))
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
            ->matchStatusCode(200)
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'multilinetext',
            'column_view_name' => 'Multi Line Text',
            'column_type' => 'textarea',
            'options' => [
                'string_length' => 512,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'multilinetext')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Multi line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('multilinetext')
            ->seeInField('column_view_name', 'Multi Line Text')
            ->see(exmtrans('custom_column.column_type_options.textarea'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'editor_col',
            'column_view_name' => 'Editor Column',
            'column_type' => 'editor',
            'options' => [
                'help' => 'テキストエディタのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'editor')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Editor--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('editor_col')
            ->seeInField('column_view_name', 'Editor Column')
            ->see(exmtrans('custom_column.column_type_options.editor'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'url_col',
            'column_view_name' => 'URL Column',
            'column_type' => 'url',
            'options' => [
                'help' => 'URLのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'url')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --URL--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('url_col')
            ->seeInField('column_view_name', 'URL Column')
            ->see(exmtrans('custom_column.column_type_options.url'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'email_col',
            'column_view_name' => 'Email Column',
            'column_type' => 'email',
            'options' => [
                'help' => 'Emailのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'email')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Email--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('email_col')
            ->seeInField('column_view_name', 'Email Column')
            ->see(exmtrans('custom_column.column_type_options.email'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'integer_col',
            'column_view_name' => 'Integer Column',
            'column_type' => 'integer',
            'options' => [
                'default' => 1,
                'help' => '整数のヘルプ',
                'number_min' => -12345,
                'number_max' => 12345,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'integer')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Integer--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('integer_col')
            ->seeInField('column_view_name', 'Integer Column')
            ->see(exmtrans('custom_column.column_type_options.integer'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'decimal_col',
            'column_view_name' => 'Decimal Column',
            'column_type' => 'decimal',
            'options' => [
                'default' => 1,
                'help' => '小数のヘルプ',
                'number_min' => -12345.67,
                'number_max' => 12345.67,
                'decimal_digit' => 3,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'decimal')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Decimal--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('decimal_col')
            ->seeInField('column_view_name', 'Decimal Column')
            ->see(exmtrans('custom_column.column_type_options.decimal'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'currency_col',
            'column_view_name' => 'Currency Column',
            'column_type' => 'currency',
            'options' => [
                'default' => 1,
                'help' => '通貨のヘルプ',
                'number_min' => -12345.67,
                'number_max' => 12345.67,
                'decimal_digit' => 3,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'currency')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Currency--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('currency_col')
            ->seeInField('column_view_name', 'Currency Column')
            ->see(exmtrans('custom_column.column_type_options.currency'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'date_col',
            'column_view_name' => 'Date Column',
            'column_type' => 'date',
            'options' => [
                'help' => '日付のヘルプ',
                'default_type' => ColumnDefaultType::SELECT_DATE,
                'default' => '2019/02/19',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'date')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Date--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('date_col')
            ->seeInField('column_view_name', 'Date Column')
            ->see(exmtrans('custom_column.column_type_options.date'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'time_col',
            'column_view_name' => 'Time Column',
            'column_type' => 'time',
            'options' => [
                'help' => '時間のヘルプ',
                'default_type' => ColumnDefaultType::SELECT_TIME,
                'default' => '12:34:56',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'time_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Time--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('time_col')
            ->seeInField('column_view_name', 'Time Column')
            ->see(exmtrans('custom_column.column_type_options.time'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'datetime_col',
            'column_view_name' => 'DateTime Column',
            'column_type' => 'datetime',
            'options' => [
                'help' => '日付と時間のヘルプ',
                'default_type' => ColumnDefaultType::SELECT_DATETIME,
                'default' => '2019/02/19 11:22:33',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'datetime_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --DateTime--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('datetime_col')
            ->seeInField('column_view_name', 'DateTime Column')
            ->see(exmtrans('custom_column.column_type_options.datetime'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'select_col',
            'column_view_name' => 'Select Column',
            'column_type' => 'select',
            'options' => [
                'default' => 0,
                'help' => '選択肢のヘルプ',
                'select_item' => '選択1'."\n".'選択2'."\n".'選択3',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'select_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Select line--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_col')
            ->seeInField('column_view_name', 'Select Column')
            ->see(exmtrans('custom_column.column_type_options.select'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[select_item]', '選択1 選択2 選択3')
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'select_valtext_col',
            'column_view_name' => 'Select Value Text Column',
            'column_type' => 'select_valtext',
            'options' => [
                'default' => 0,
                'help' => '選択肢（値と見出し）のヘルプ',
                'select_item_valtext' => '0,低い'."\n".'1,通常'."\n".'2,高い',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'select_valtext_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Select Value Text--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_valtext_col')
            ->seeInField('column_view_name', 'Select Value Text Column')
            ->see(exmtrans('custom_column.column_type_options.select_valtext'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢（値と見出し）のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[multiple_enabled]', 0)
            ->seeInField('options[select_item_valtext]', '0,低い 1,通常 2,高い')
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
        /** @var CustomTable $table */
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        $table_id = array_get($table, 'id');

        $this->post(admin_url('column/test'), [
            'column_name' => 'select_table_col',
            'column_view_name' => 'Select Table Column',
            'column_type' => 'select_table',
            'options' => [
                'default' => 0,
                'help' => '選択肢（テーブル）のヘルプ',
                'select_target_table' => $table_id,
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'select_table_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Select Table--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('select_table_col')
            ->seeInField('column_view_name', 'Select Table Column')
            ->see(exmtrans('custom_column.column_type_options.select_table'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '選択肢（テーブル）のヘルプ')
            ->seeInField('options[default]', 0)
            ->seeInField('options[multiple_enabled]', 0)
            ->seeInElement('span', $table->table_view_name)
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'yesno_col',
            'column_view_name' => 'YesNo Column',
            'column_type' => 'yesno',
            'options' => [
                'default' => 0,
                'help' => 'YES・Noのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'yesno_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --YesNo--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('yesno_col')
            ->seeInField('column_view_name', 'YesNo Column')
            ->see(exmtrans('custom_column.column_type_options.yesno'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'boolean_col',
            'column_view_name' => 'Boolean Column',
            'column_type' => 'boolean',
            'options' => [
                'default' => 0,
                'true_value' => 1,
                'true_label' => '１番',
                'false_value' => 2,
                'false_label' => '２番',
                'help' => '2値の選択のヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'boolean_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Boolean--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('boolean_col')
            ->seeInField('column_view_name', 'Boolean Column')
            ->see(exmtrans('custom_column.column_type_options.boolean'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'auto_number_col',
            'column_view_name' => 'AutoNumber Column',
            'column_type' => 'auto_number',
            'options' => [
                'auto_number_type' => 'random25',
                'help' => '採番種類のヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'auto_number_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --AutoNumber--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('auto_number_col')
            ->seeInField('column_view_name', 'AutoNumber Column')
            ->see(exmtrans('custom_column.column_type_options.auto_number'))
            ->seeInField('options[index_enabled]', 0)
            ->seeInField('options[required]', 0)
            ->seeInField('options[help]', '採番種類のヘルプ')
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'image_col',
            'column_view_name' => 'Image Column',
            'column_type' => 'image',
            'options' => [
                'help' => '画像のヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test'))
            ->seePageIs(admin_url('column/test'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'image_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Image--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('image_col')
            ->seeInField('column_view_name', 'Image Column')
            ->see(exmtrans('custom_column.column_type_options.image'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'file_col',
            'column_view_name' => 'File Column',
            'column_type' => 'file',
            'options' => [
                'help' => 'ファイルのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test?per_page=100'))
            ->seePageIs(admin_url('column/test?per_page=100'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'file_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --File--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('file_col')
            ->seeInField('column_view_name', 'File Column')
            ->see(exmtrans('custom_column.column_type_options.file'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'user_col',
            'column_view_name' => 'User Column',
            'column_type' => 'user',
            'options' => [
                'help' => 'ユーザーのヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test?per_page=100'))
            ->seePageIs(admin_url('column/test?per_page=100'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'user_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --User--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('user_col')
            ->seeInField('column_view_name', 'User Column')
            ->see(exmtrans('custom_column.column_type_options.user'))
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

        $this->post(admin_url('column/test'), [
            'column_name' => 'organization_col',
            'column_view_name' => 'Organization Column',
            'column_type' => 'organization',
            'options' => [
                'help' => '組織のヘルプ',
            ],
        ]);

        $this->visit(admin_url('column/test?per_page=100'))
            ->seePageIs(admin_url('column/test?per_page=100'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'organization_col')
            ->assertEquals($pre_cnt + 1, CustomColumn::count());

        $row = $this->getNewestColumn();
        $id = array_get($row, 'id');

        // Check custom column --Organization--
        $this->visit(admin_url('column/test/'. $id . '/edit'))
            ->see('organization_col')
            ->seeInField('column_view_name', 'Organization Column')
            ->see(exmtrans('custom_column.column_type_options.organization'))
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


    protected function getNewestColumn()
    {
        return CustomColumn::orderBy('id', 'desc')->first();
    }
}
