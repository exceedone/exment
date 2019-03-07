<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\ExmentKitTestCase;

class A02LoginOutTest extends ExmentKitTestCase
{
    
    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongUsernameAndPass()
    {
        $this->visit('/admin/auth/logout')
                ->visit('/admin/auth/login')
                ->type('abcde', 'username')
                ->type('abcde', 'password')
                ->press('ログイン')
                ->seePageIs('/admin/auth/login');
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongPass()
    {
        $this->visit('/admin/auth/login')
                ->type('testuser', 'username')
                ->type('abcde', 'password')
                ->press('ログイン')
                ->seePageIs('/admin/auth/login');
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongId()
    {
        $this->visit('/admin/auth/login')
                ->type('abcde', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs('/admin/auth/login');
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessUserCode()
    {
        $this->visit('/admin/auth/login')
                ->type('testuser', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs('/admin');
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessEmail()
    {
        $this->visit('/admin/auth/logout')
                ->visit('/admin/auth/login')
                ->type('aaa@exceedone.co.jp.test', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs('/admin');
    }

    // /**
    //  *
    //  * @return void
    //  */
    // public function testLoginFailWithWrongUsernameAndPass()
    // {
    //     $this->browse(function ($browser) {
    //         $browser->visit('/admin/auth/login')
    //             ->type('username', 'abcde')
    //             ->type('password', 'abcde')
    //             ->press('Login')
    //             ->assertPathIs('/admin/auth/login');
    //     });
    // }

    // /**
    //  *
    //  * @return void
    //  */
    // public function testLoginFailWithWrongPass()
    // {
    //     $this->browse(function ($browser) {
    //         $browser->visit('/admin/auth/login')
    //             ->type('username', 'testuser')
    //             ->type('password', 'abcde')
    //             ->press('Login')
    //             ->assertPathIs('/admin/auth/login');
    //     });
    // }

    // /**
    //  *
    //  * @return void
    //  */
    // public function testLoginFailWithWrongId()
    // {
    //     $this->browse(function ($browser) {
    //         $browser->visit('/admin/auth/login')
    //             ->type('username', 'abcde')
    //             ->type('password', 'test123456')
    //             ->press('Login')
    //             ->assertPathIs('/admin/auth/login');
    //     });
    // }

    // /**
    //  *
    //  * @return void
    //  */
    // public function testLoginSuccessUserCode()
    // {
    //     $this->browse(function ($browser) {
    //         $browser->visit('/admin/auth/login')
    //             ->type('username', 'testuser')
    //             ->type('password', 'test123456')
    //             ->press('Login')
    //             ->assertPathIs('/admin');
    //     });
    // }

    // /**
    //  *
    //  * @return void
    //  */
    // public function testLoginSuccessEmail()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->visit('/admin/auth/logout')
    //             ->visit('/admin/auth/login')
    //             ->type('username', 'aaa@exceedone.co.jp.test')
    //             ->type('password', 'test123456')
    //             ->press('Login')
    //             ->assertPathIs('/admin');
    //     });
    // }

}
