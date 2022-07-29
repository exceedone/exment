<?php

namespace Exceedone\Exment\Tests\Browser;

class ALoginOutTest extends ExmentKitTestCase
{
    /**
     *
     * @return void
     */
    public function testNotAuthLoginPage()
    {
        $this->visit(admin_url('auth/logout'))
            ->visit(admin_url(''))
            ->seePageIs(admin_url('auth/login'))
            ->seeOuterElement('p[class="login-box-msg"]', 'ログイン');

        $this->visit(admin_url('auth/logout'))
            ->visit(admin_url('data/information'))
            ->seePageIs(admin_url('auth/login'))
            ->seeOuterElement('p[class="login-box-msg"]', 'ログイン');

        $this->visit(admin_url('auth/logout'))
            ->visit(admin_url('system'))
            ->seePageIs(admin_url('auth/login'))
            ->seeOuterElement('p[class="login-box-msg"]', 'ログイン');

        $this->visit(admin_url('auth/logout'))
            ->visit(admin_url('data/information/1'))
            ->seePageIs(admin_url('auth/login'))
            ->seeOuterElement('p[class="login-box-msg"]', 'ログイン');
    }


    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongUsernameAndPass()
    {
        $this->visit(admin_url('auth/logout'))
                ->visit(admin_url('auth/login'))
                ->type('abcde', 'username')
                ->type('abcde', 'password')
                ->press('ログイン')
                ->seePageIs(admin_url('auth/login'))
                ->seeOuterElement('p[class="login-box-msg"]', 'ログイン');
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongPass()
    {
        $this->visit(admin_url('auth/login'))
                ->type('testuser', 'username')
                ->type('abcde', 'password')
                ->press('ログイン')
                ->seePageIs(admin_url('auth/login'));
    }

    /**
     *
     * @return void
     */
    public function testLoginFailWithWrongId()
    {
        $this->visit(admin_url('auth/login'))
                ->type('abcde', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs(admin_url('auth/login'));
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessUserCode()
    {
        $this->visit(admin_url('auth/login'))
                ->type('user1', 'username')
                ->type('user1user1', 'password')
                ->press('ログイン')
                ->seePageIs(admin_url(''));
    }

    /**
     *
     * @return void
     */
    public function testLoginSuccessEmail()
    {
        $this->visit(admin_url('auth/logout'))
                ->visit(admin_url('auth/login'))
                ->type('user2@user.foobar.test', 'username')
                ->type('user2user2', 'password')
                ->press('ログイン')
                ->seePageIs(admin_url(''));
    }
}
