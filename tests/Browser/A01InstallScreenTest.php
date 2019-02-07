<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\ExmentDuskTestCase;

class A01InstallScreenTest extends ExmentDuskTestCase
{
    //AutoTest_Install_01 : only setting
    //AutoTest_Install_02
    public function testRedirect()
    {
        // $this->browse(function ($browser) {
        //     $browser->visit('/admin')
        //         ->assertPathIs('/admin/initialize');
        // });

        $this->get('/admin')
            ->assertRedirect('/admin/initialize');
    }

    //AutoTest_Install_03
    public function testDisplayInstallScreen()
    {
        // $this->browse(function ($browser) {
        //     $browser->visit('/admin/initialize')
        //         ->assertPathIs('/admin/initialize');
        // });
    
        $this->get('/admin/initialize')
            ->assertSuccessful();
    }

    //AutoTest_Install_04
    public function testInitUser()
    {
        // $this->browse(function ($browser) {
        //     $browser->resize(1920, 1080)
        //         ->type('site_name', 'Auto Test')
        //         ->type('site_name_short', 'AT')
        //         ->select('site_skin', 'skin-red-light')
        //         ->type('user_code', 'testuser')
        //         ->type('user_name', 'testuser')
        //         ->type('email', 'aaa@exceedone.co.jp.test')
        //         ->type('password', 'test123456')
        //         ->type('password_confirmation', 'test123456')
        //         ->press('Submit')
        //         ->pause(3000)
        //         ->assertPathIs('/admin')
        //         ->assertSee('Auto Test')
        //         ->assertDontSee('AT')
        //         ->click('.sidebar-toggle')
        //         ->pause(2000)
        //         ->assertSee('AT')
        //         ->assertTitle('Auto Test | Dashboard')
        //         ->assertSee('Dashboard');
        // });
        
        // post data
        $data = [
            'site_name' => 'Auto Test',
            'site_name_short' => 'AT',
            'site_skin' => 'skin-red-light',
            'user_code' => 'testuser',
            'user_name' => 'testuser',
            'site_layout' => 'layout_default',
            'email' => 'aaa@exceedone.co.jp.test',
            'password' => 'test123456',
            'password_confirmation' => 'test123456',
        ];
        
        $response = $this->post('/admin/initialize', $data)
            ->assertRedirect('/admin')
            ;

        $this->browse(function ($browser) {
            $browser
                ->visit('/admin/auth/login')
                ->resize(1200, 800)
                ->type('username', 'testuser')
                ->type('password', 'test123456')
                ->press('Login')
                ->pause(2000)
                ->assertSee('Auto Test')
                ->assertDontSee('AT')
                ->click('.sidebar-toggle')
                ->pause(2000)
                ->assertSee('AT')
                ->assertTitle('Auto Test | Dashboard')
                ->assertSee('Dashboard');
        });
        
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongUsernameAndPass()
    {
        $this->browse(function ($browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'abcde')
                ->type('password', 'abcde')
                ->press('Login')
                ->assertPathIs('/admin/auth/login');
        });
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongPass()
    {
        $this->browse(function ($browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'testuser')
                ->type('password', 'abcde')
                ->press('Login')
                ->assertPathIs('/admin/auth/login');
        });
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongId()
    {
        $this->browse(function ($browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'abcde')
                ->type('password', 'test123456')
                ->press('Login')
                ->assertPathIs('/admin/auth/login');
        });
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessUserCode()
    {
        $this->browse(function ($browser) {
            $browser->visit('/admin/auth/login')
                ->type('username', 'testuser')
                ->type('password', 'test123456')
                ->press('Login')
                ->assertPathIs('/admin');
        });
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessEmail()
    {
        $this->browse(function ($browser) {
            $browser
                ->visit('/admin/auth/logout')
                ->visit('/admin/auth/login')
                ->type('username', 'aaa@exceedone.co.jp.test')
                ->type('password', 'test123456')
                ->press('Login')
                ->assertPathIs('/admin');
        });
    }

}
