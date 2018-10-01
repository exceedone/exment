<?php

namespace Tests\Browser;

use Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class CustomTableTest extends DuskTestCase
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

    // AutoTest_Table_01
    public function testDisplaySettingCustomTable()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
                ->assertTitle('Custom Table Setting')
                ->assertSee('Custom Table Setting')
                ->assertSee('Define custom table settings that can be changed independently.')
                ->assertSee('Table Name')
                ->assertSee('Table View Name');
        });

    }

    // AutoTest_Table_02
    public function testDisplayInstalledTable()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
                ->assertSee('document')
                ->assertSee('Document')
                ->assertSee('base_info')
                ->assertSee('Base Info')
                ->assertSee('user')
                ->assertSee('User')
                ->assertSee('organization')
                ->assertSee('Organization');
        });

    }

    // AutoTest_Table_03
    public function testDisplayMissingDeleteIcon()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
                ->assertMissing('.grid-row-delete');
        });

    }

    // AutoTest_Table_04
    public function testDisplayCreateScreen()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
                ->waitForText('New')
                ->clickLink('New')
                ->pause(5000)
                ->assertSeeIn('.box-title', 'Create')
                ->assertSee('Table Name')
                ->assertSee('Table View Name')
                ->assertSee('Description')
                ->assertSee('Color')
                ->assertSee('Icon')
                ->assertSee('Search Enabled')
                ->assertSee('Save Only One Record')
                ->assertSee('Authority Setting');
        });
    }

    // AutoTest_Table_05
    public function testCreateCustomTableSuccess()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
                ->waitForText('New')
                ->clickLink('New')
                ->pause(5000)
                ->type('table_name', 'test')
                ->type('table_view_name', 'test table')
                ->type('description', 'test table')
                ->type('color', '#ff0000')
                ->type('icon', 'fa-automobile')
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
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/table')
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
