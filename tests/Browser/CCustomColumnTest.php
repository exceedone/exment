<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\ExmentDuskTestCase;
use Laravel\Dusk\Browser;

class CCustomColumnTest extends ExmentDuskTestCase
{
    // precondition : login success
    public function testLoginSuccessWithTrueUsername()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'testuser')
                ->type('password', 'test123456')
                ->press('Login')
                ->waitForText('Login successful')
                ->assertPathIs('/admin')
                ->assertTitle('Dashboard')
                ->assertSee('Dashboard');
        });
    }

//     AutoTest_Column_01
    public function testDisplayColummSetting()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->assertSee('Custom Column Detail Setting')
                ->assertSee('Setting details with customer list. these define required fields, searchable fields, etc.')
                ->assertSee('Showing to of 0 entries');
        });
    }

//     AutoTest_Column_02
    public function testDisplayCreateScreen()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->waitForText('New')
                ->clickLink('New')
                ->pause(5000)
                ->assertSeeIn('.box-title', 'Create')
                ->assertSee('Column Name')
                ->assertSee('Column View Name')
                ->assertSee('Column Type')
                ->assertSee('Required')
                ->assertSee('Search Index')
                ->assertSee('PlaceHolder')
                ->assertSee('Use Label');
        });
    }

    // AutoTest_Column_03
    public function testAddOneLineTextColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'onelinetext' )
                ->type('column_view_name', 'One Line Text')
                ->assertDontSee('Max Length')
                ->assertDontSee('Available Characters')
                ->select('column_type', 'text')
                ->assertSee('Max Length')
                ->assertSee('Available Characters')
                ->type('options[string_length]', '256')
                ->click('#available_characters  label.checkbox-inline:nth-child(1) div.icheckbox_minimal-blue')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('onelinetext');
        });

    }

//     AutoTest_Column_04
    public function testVerifyOneLineTextColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('onelinetext')
                ->assertValue('[name=column_view_name]', 'One Line Text')
                ->assertSelected('column_type', 'text')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=string_length]', 256)
                ->assertValue('#available_characters label.checkbox-inline:nth-child(1) div.checked [name*=available_characters]', 'lower');
        });

    }

//     AutoTest_Column_05
    public function testAddMultiLineTextColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'multilinetext' )
                ->type('column_view_name', 'Multi Line Text')
                ->assertDontSee('Max Length')
                ->select('column_type', 'textarea')
                ->assertSee('Max Length')
                ->type('options[string_length]', '256')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('multilinetext');
        });
    }

//     AutoTest_Column_06
    public function testVerifyMultiLineTextColumn()
    {

        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('multilinetext')
                ->assertValue('[name=column_view_name]', 'Multi Line Text')
                ->assertSelected('column_type', 'textarea')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=string_length]', 256);
        });
    }

//     AutoTest_Column_07
    public function testAddDecimalColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'decimal' )
                ->type('column_view_name', 'Decimal')
                ->assertDontSee('Min Number')
                ->assertDontSee('Max Number')
                ->assertDontSee('Use Number Comma String')
                ->select('column_type', 'decimal')
                ->assertSee('Min Number')
                ->assertSee('Max Number')
                ->assertSee('Use Number Comma String')
                ->type('options[number_min]', '10')
                ->type('options[number_max]', '100');
            $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('decimal');
        });
    }

//     AutoTest_Column_08
    public function testVerifyDecimalColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('decimal')
                ->assertValue('[name=column_view_name]', 'Decimal')
                ->assertSelected('column_type', 'decimal')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=string_length]', 256)
                ->assertValue('[name*=number_min]', 10)
                ->assertValue('[name*=number_max]', 100)
                ->assertValue('[name*=number_format]', 1);
        });
    }

//     AutoTest_Column_09
    public function testAddURLColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'url' )
                ->type('column_view_name', 'URL')
                ->select('column_type', 'url')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('url');
        });
    }

//     AutoTest_Column_10
    public function testVerifyURLColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('url')
                ->assertValue('[name=column_view_name]', 'URL')
                ->assertSelected('column_type', 'url')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

//     AutoTest_Column_11
    public function testAddEmailColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'email' )
                ->type('column_view_name', 'Email')
                ->select('column_type', 'email')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('email');
        });
    }

//     AutoTest_Column_12
    public function testVerifyEmailColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('email')
                ->assertValue('[name=column_view_name]', 'Email')
                ->assertSelected('column_type', 'email')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

//     AutoTest_Column_13
    public function testAddIntegerColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'integer' )
                ->type('column_view_name', 'Integer')
                ->assertDontSee('Min Number')
                ->assertDontSee('Max Number')
                ->assertDontSee('Use Number Comma String')
                ->select('column_type', 'integer')
                ->assertSee('Min Number')
                ->assertSee('Max Number')
                ->assertSee('Use Number Comma String')
                ->type('options[number_min]', '10')
                ->type('options[number_max]', '100');
            $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('integer');
        });
    }

//     AutoTest_Column_14
    public function testVerifyIntegerColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('integer')
                ->assertValue('[name=column_view_name]', 'Integer')
                ->assertSelected('column_type', 'integer')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=string_length]', 256)
                ->assertValue('[name*=number_min]', 10)
                ->assertValue('[name*=number_max]', 100)
                ->assertValue('[name*=number_format]', 1);
        });
    }

// AutoTest_Column_15
    public function testAddCalcResultColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'calcresult' )
                ->type('column_view_name', 'Calc Result')
                ->assertDontSee('Calc Formula')
                ->assertDontSee('Use Number Comma String')
                ->select('column_type', 'calc')
                ->assertSee('Calc Formula')
                ->assertSee('Use Number Comma String')
                ->press('変更')
                ->pause(2000)
                ->assertSee('Calc Formula');
//                ->click('button[data-val="66"]')
//                ->click('button[data-val="69"]');
            $browser->script('jQuery(\'.col-target-fixedval\').val(100)');
            $browser
                ->pause(2000)
                ->click('button[data-type="fixed"]')
                ->click('button[data-val="plus"]')
                ->click('button[data-val="minus"]')
                ->click('button[data-val="times"]')
                ->click('button[data-val="div"]')
                ->press('Setting')
                ->pause(2000);
            $browser->script('document.querySelector(".options_number_format.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('calcresult');
        });
    }

    // AutoTest_Column_16
    public function testVerifyCalcResultColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('calcresult')
                ->assertValue('[name=column_view_name]', 'Calc Result')
                ->assertSelected('column_type', 'calc')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertSee( "100 ＋ － × ÷")
                ->assertValue('[name*=number_format]', 1)
            ;
        });
    }

    // AutoTest_Column_17
    public function testAddDateColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'date' )
                ->type('column_view_name', 'Date')
                ->select('column_type', 'date')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('date');
        });
    }

    // AutoTest_Column_18
    public function testVerifyDateColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('date')
                ->assertValue('[name=column_view_name]', 'Date')
                ->assertSelected('column_type', 'date')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

    // AutoTest_Column_19
    public function testAddTimeColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'time' )
                ->type('column_view_name', 'Time')
                ->select('column_type', 'time')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('time');
        });
    }

    // AutoTest_Column_20
    public function testVerifyTimeColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('time')
                ->assertValue('[name=column_view_name]', 'Time')
                ->assertSelected('column_type', 'time')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

//     AutoTest_Column_21
    public function testAddDateAndTimeColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'dateandtime' )
                ->type('column_view_name', 'Date and Time')
                ->select('column_type', 'datetime')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('dateandtime');
        });
    }

    // AutoTest_Column_22
    public function testVerifyDateAndTimeColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('dateandtime')
                ->assertValue('[name=column_view_name]', 'Date and Time')
                ->assertSelected('column_type', 'datetime')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

//     AutoTest_Column_23
    public function testAddSelectFromStaticValueColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'selectfromstaticvalue' )
                ->type('column_view_name', "Select Froom Static Value")
                ->assertDontSee('Select Choice')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'select')
                ->assertSee('Select Choice')
                ->assertSee('Approval Multiple Select')
                ->type('options[select_item]', 'Adult \n Underage');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('selectfromstaticvalue');
        });
    }

    // AutoTest_Column_24
    public function testVerifySelectFromStaticValueColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('selectfromstaticvalue')
                ->assertValue('[name=column_view_name]', 'Select Froom Static Value')
                ->assertSelected('column_type', 'select')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=select_item]', 'Adult \n Underage')
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_25
    public function testAddSelectSaveValueAndLabelColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'selectsavevalueandlabel' )
                ->type('column_view_name', "Select Save Value and Lable")
                ->assertDontSee('Select Choice')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'select_valtext')
                ->assertSee('Select Choice')
                ->assertSee('Approval Multiple Select')
                ->type('options[select_item_valtext]', '0,Adult \n 1,Underage');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('selectsavevalueandlabel');
        });
    }

    // AutoTest_Column_26
    public function testVerifySelectSaveValueAndLabelColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('selectsavevalueandlabel')
                ->assertValue('[name=column_view_name]', 'Select Save Value and Lable')
                ->assertSelected('column_type', 'select_valtext')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=select_item_valtext]', '0,Adult \n 1,Underage')
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_27
    public function testAddSelectFromTableColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'selectfromtable' )
                ->type('column_view_name', "Select Froom Table")
                ->assertDontSee('Select Target Table')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'select_table')
                ->assertSee('Select Target Table')
                ->assertSee('Approval Multiple Select')
                ->select('options[select_target_table]', 1);
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('selectfromtable');
        });
    }

    // AutoTest_Column_28
    public function testVerifySelectFromTableColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('selectfromtable')
                ->assertValue('[name=column_view_name]', 'Select Froom Table')
                ->assertSelected('column_type', 'select_table')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertSelected('options[select_target_table]', 1)
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_29
    public function testAddYesNoColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'yesno' )
                ->type('column_view_name', 'Yes No')
                ->select('column_type', 'yesno')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('yesno');
        });
    }

    // AutoTest_Column_30
    public function testVerifyYesNoColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('yesno')
                ->assertValue('[name=column_view_name]', 'Yes No')
                ->assertSelected('column_type', 'yesno')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

    // AutoTest_Column_31
    public function testAddSelect2ValueColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'select2value' )
                ->type('column_view_name', "Select 2 value")
                ->assertDontSee('Select1 Value')
                ->assertDontSee('Select2 Value')
                ->assertDontSee('Select2 Label')
                ->select('column_type', 'boolean')
                ->assertSee('Select1 Value')
                ->assertSee('Select2 Value')
                ->assertSee('Select2 Label')
                ->type('options[true_value]', "value1")
                ->type('options[true_label]', "label1")
                ->type('options[false_value]', "value2")
                ->type('options[false_label]', "label2");
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('select2value');
        });
    }

    // AutoTest_Column_32
    public function testVerifySelect2ValueColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('select2value')
                ->assertValue('[name=column_view_name]', 'Select 2 value')
                ->assertSelected('column_type', 'boolean')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=true_value]', "value1")
                ->assertValue('[name*=true_label]', "label1")
                ->assertValue('[name*=false_value]', "value2")
                ->assertValue('[name*=false_label]', "label2");
        });
    }

    // AutoTest_Column_33
    public function testAddAutoNumberColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'autonumber' )
                ->type('column_view_name', 'Auto Number')
                ->assertDontSee('Auto Number Type')
                ->select('column_type', 'auto_number')
                ->assertSee('Auto Number Type')
                ->select('options[auto_number_type]', 'random25')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('autonumber');
        });
    }

    // AutoTest_Column_34
    public function testVerifyAutoNumberColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('autonumber')
                ->assertValue('[name=column_view_name]', 'Auto Number')
                ->assertSelected('column_type', 'auto_number')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertSelected('options[auto_number_type]', 'random25');
        });
    }

    // AutoTest_Column_35
    public function testAddImageColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'image' )
                ->type('column_view_name', 'Image')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'image')
                ->assertSee('Approval Multiple Select');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('image');
        });
    }

    // AutoTest_Column_36
    public function testVerifyImageColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('image')
                ->assertValue('[name=column_view_name]', 'Image')
                ->assertSelected('column_type', 'image')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_37
    public function testAddFileColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'file' )
                ->type('column_view_name', 'File')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'file')
                ->assertSee('Approval Multiple Select');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('file');
        });
    }

    // AutoTest_Column_38
    public function testVerifyFileColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('file')
                ->assertValue('[name=column_view_name]', 'File')
                ->assertSelected('column_type', 'file')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_39
    public function testAddUserColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'user' )
                ->type('column_view_name', 'User')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'user')
                ->assertSee('Approval Multiple Select');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('user');
        });
    }

    // AutoTest_Column_40
    public function testVerifyUserColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('user')
                ->assertValue('[name=column_view_name]', 'User')
                ->assertSelected('column_type', 'user')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_41
    public function testAddOrganizationColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'organization' )
                ->type('column_view_name', 'Organization')
                ->assertDontSee('Approval Multiple Select')
                ->select('column_type', 'organization')
                ->assertSee('Approval Multiple Select');
            $browser->script('document.querySelector(".options_multiple_enabled.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('organization');
        });
    }

    // AutoTest_Column_42
    public function testVerifyOrganizationColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('organization')
                ->assertValue('[name=column_view_name]', 'Organization')
                ->assertSelected('column_type', 'organization')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0)
                ->assertValue('[name*=multiple_enabled]', 1);
        });
    }

    // AutoTest_Column_43
    public function testAddDocumentColumnSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->type('column_name', 'document' )
                ->type('column_view_name', 'Document')
                ->select('column_type', 'document')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin/column/test')
                ->assertSee('document');
        });
    }

    // AutoTest_Column_44
    public function testVerifyDocumentColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->click('table tr:last-child .fa.fa-edit')
                ->pause(5000)
                ->assertSee('document')
                ->assertValue('[name=column_view_name]', 'Document')
                ->assertSelected('column_type', 'document')
                ->assertValue('[name*=search_enabled]', 0)
                ->assertValue('[name*=required]', 0)
                ->assertValue('[name*=use_label_flg]', 0);
        });
    }

    // AutoTest_Column_45
    public function testAddFailWithMissingInfo()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->assertSeeIn('.box-title', 'Create')
                ->press('Submit')
                ->pause(5000)
                ->assertVisible('.has-error')
                ->assertSee('The column name field is required.')
                ->assertSee('The column view name field is required.')
                ->assertSee('The column type field is required.');
        });
    }

    // AutoTest_Column_46
    public function testAddFailWithExistedColumnName()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test/create')
                ->assertSeeIn('.box-title', 'Create')
                ->type('column_name', 'onelinetext')
                ->press('Submit')
                ->pause(5000)
                ->assertVisible('.has-error')
                ->assertSee('validation.unique_in_table');
        });
    }

    // AutoTest_Column_47
    public function testEditOneLineTextColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')
                ->assertSee('onelinetext');
            $browser->script('$(".table-hover td").filter(function(){return $.trim($(this).text()) == "onelinetext"}).closest("tr").click();');
            $browser->pause(5000)
                ->type('column_view_name', 'One Line Text Edited')
                ->select('column_type', 'text')
                ->press('Submit')
                ->waitForText('Save Successful')
                ->assertSee('One Line Text Edited')
                ->assertPathIs('/admin/column/test');
        });
    }

    // AutoTest_Column_48
    public function testDropOneLineTextColumn()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/column/test')->assertSee('onelinetext');
            $browser->script('$(".table-hover td").filter(function(){return $.trim($(this).text()) == "onelinetext"}).closest("tr").find("a.grid-row-delete").click();');
            $browser->pause(5000)
                ->press('Confirm')
				->waitForText('Delete succeeded !')
                ->press('Ok')
                ->assertDontSee('onelinetext')
                ->assertPathIs('/admin/column/test');
        });
    }
}

