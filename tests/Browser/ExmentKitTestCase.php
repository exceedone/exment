<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\BrowserKitTesting\TestCase as BaseTestCase;
//use Tests\CreatesApplication;

abstract class ExmentKitTestCase extends BaseTestCase
{
    use \Tests\CreatesApplication;
//    use DatabaseMigrations;

    public $baseUrl = 'http://localhost';

    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        System::clearCache();
    }

    // ...
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id?? 1));
        // precondition : login success
        // $this->visit('/admin/auth/logout')
        //         ->visit('/admin/auth/login')
        //         ->type('admin', 'username')
        //         ->type('adminadmin', 'password')
        //         ->press('ログイン')
        //         ->seePageIs('/admin');
    }
}