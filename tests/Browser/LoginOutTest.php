<?php

namespace Tests\Browser;

use Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class LoginTest extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */


    //AutoTest_Loginout_01
    public function testLoginFailWithWrongUsername()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'abcde')
                ->type('password', 'abcde')
                ->press('Login')
                ->assertTitle('Admin | Login')
                ->assertPathIs('/admin/auth/login')
                ->assertSee('These credentials do not match our records.');
        });
    }

    //AutoTest_Loginout_02
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

    //AutoTest_Loginout_03
    public function testLogoutUseUsernameLogin()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->clickLink('testuser')
                ->clickLink('Logout')
                ->waitForText('Auto Test')
                ->assertTitle('Admin | Login')
                ->assertPathIs('/admin/auth/login');
        });
    }

    //AutoTest_Loginout_04
    public function testLoginSuccessWithTrueEmail()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', ' aaa@exceedone.co.jp.test')
                ->type('password', 'test123456')
                ->press('Login')
                ->waitForText('Login successful')
                ->assertPathIs('/admin')
                ->assertTitle('Dashboard')
                ->assertSee('Dashboard');
        });
    }

    //AutoTest_Loginout_05
    public function testLogoutUseEmailLogin()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->clickLink('testuser')
                ->clickLink('Logout')
                ->waitForText('Auto Test')
                ->assertTitle('Admin | Login')
                ->assertPathIs('/admin/auth/login');
        });
    }

    //AutoTest_Loginout_06
    public function testRedirectLogin()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->assertPathIs('/admin/auth/login')
                ->assertTitle('Admin | Login')
                ->assertSee('Login');
        });
    }
}
