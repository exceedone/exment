<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\Database\ExmentMigrations;
use Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class AInstallScreenTest extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    use ExmentMigrations;

    //AutoTest_Install_01 : only setting
    //AutoTest_Install_02
    public function testRedirect()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->assertPathIs('/admin/initialize');
        });
    }

    //AutoTest_Install_03
    public function testDisplayInstallScreen()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/initialize')
                ->assertPathIs('/admin/initialize')
                ->assertSee('Exment Install ')
                ->assertSee('Register the initial setting of Exment from the display and install it');
        });
    }

    //AutoTest_Install_04
    public function testInitUser()
    {

        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/initialize')
                ->resize(1920, 1080)
                ->type('site_name', 'Auto Test')
                ->type('site_name_short', 'AT')
                ->select('site_skin', 'skin-red-light')
                ->type('user_code', 'testuser')
                ->type('user_name', 'testuser')
                ->type('email', 'aaa@exceedone.co.jp.test')
                ->type('password', 'test123456')
                ->type('password_confirmation', 'test123456')
                ->press('Submit')
                ->pause(5000)
                ->assertPathIs('/admin');
                $this->assertEquals('skin-red-light sidebar-mini',$browser->attribute('', 'class'));
                $browser->assertSee('Auto Test')
                ->assertDontSee('AT')
                ->click('.sidebar-toggle')
                ->pause(5000)
                ->assertSee('AT')
                ->assertTitle('Dashboard')
                ->assertSee('Dashboard')
            ;
        });

    }

}
