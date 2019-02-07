<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\ExmentDuskTestCase;

class CustomTableTest extends ExmentDuskTestCase
{
    // precondition : login success
    public function testLoginSuccessWithTrueUsername()
    {
        $this->browse(function ($browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'testuser')
                ->type('password', 'test123456')
                ->press('Login')
                ->waitForText('Login successful')
                ->assertPathIs('/admin')
                ;
        });
    }

    // AutoTest_Table_01
    public function testDisplaySettingCustomTable()
    {
        $this->browse(function ($browser) {
            $browser
                ->visit('/admin/table')
                ->assertTitle('Auto Test | Custom Table Setting')
                ->assertPathIs('/admin/table')
                ;
        });

    }

    // AutoTest_Table_02
    public function testDisplayInstalledTable()
    {
        $this->browse(function ($browser) {
            $browser->assertSee('document')
                ->assertSee('Document')
                ->assertSee('base_info')
                ->assertSee('Base Info')
                ->assertSee('user')
                ->assertSee('User')
                ->assertSee('organization')
                ->assertSee('Organization');
        });

    }

    // AutoTest_Table_04
    public function testDisplayCreateScreen()
    {
        $this->browse(function ($browser) {
            $browser
                ->waitForText('New')
                ->clickLink('New')
                ->pause(3000)
                ->assertPathIs('/admin/table/create')
                ;
        });
    }

    // AutoTest_Table_05
    public function testCreateCustomTableSuccess()
    {
        $this->browse(function ($browser) {
            $browser
                ->clickLink('New')
                ->pause(3000)
                ->type('table_name', 'test')
                ->type('table_view_name', 'test table')
                ->type('description', 'test table')
                ->type('options[color]', '#ff0000')
                ->type('options[icon]', 'fa-automobile')
                ->click('.fa.fa-automobile');
            $browser->script('document.querySelector(".search_enabled.la_checkbox").click();');
            $browser->script('document.querySelector(".one_record_flg.la_checkbox").click();');
            $browser->press('Submit')
                ->pause(5000)
                ->assertMissing('.has-error')
                ->assertPathIs('/admin/table')
                ->assertSee('test')
                ->assertSee('test table');
        });
    }

    // AutoTest_Table_06
    public function testDisplayColummSetting()
    {
        $this->browse(function ($browser) {
            $browser
                ->click('table tr:last-child .iCheck-helper')
                ->press('Change Page')
                ->clickLink('Column Detail Setting')
                ->pause(5000);
            $browser->assertPathIs('/admin/column/test')
                ->assertSee('Custom Column Detail Setting')
                ->assertSee('Setting details with customer list. these define required fields, searchable fields, etc.')
                ->assertSee('Showing to of 0 entries');
        });
    }

}
