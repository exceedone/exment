<?php

namespace Exceedone\Exment\Tests\Browser;

class A01InstallScreenTest extends ExmentKitTestCase
{
    //AutoTest_Install_01 : only setting
    //AutoTest_Install_02
    public function testRedirect()
    {
        // $this->browse(function ($browser) {
        //     $browser->visit('/admin')
        //         ->assertPathIs('/admin/initialize');
        // });

        $this->visit('/admin')
            ->seePageIs('/admin/initialize');
    }

    //AutoTest_Install_03
    public function testDisplayInstallScreen()
    {
        // $this->browse(function ($browser) {
        //     $browser->visit('/admin/initialize')
        //         ->assertPathIs('/admin/initialize');
        // });
    
        $this->visit('/admin/initialize')
            ->seePageIs('/admin/initialize');
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
        // Initialize exment
        $this->visit('/admin/initialize')
                ->submitForm('送信', $data)
                ->seePageIs('/admin')
                ;
        
        $this->visit('/admin/auth/logout')
                ->visit('/admin/auth/login')
                ->type('testuser', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs('/admin')
                ->see('Auto Test')
                ->see('AT')
                ->see('Auto Test  | ダッシュボード')
                ->see('ダッシュボード')
                ->see('メニュー')
                ->see('HOME')
                ->see('マスター管理')
                ->see('管理者設定')
                ->see('testuser')
        ;
        
    }

}
