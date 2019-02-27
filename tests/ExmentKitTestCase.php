<?php

namespace Exceedone\Exment\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\BrowserKitTesting\TestCase as BaseTestCase;
//use Tests\CreatesApplication;

abstract class ExmentKitTestCase extends BaseTestCase
{
    use \Tests\CreatesApplication;
    use DatabaseMigrations;
    use ExmentKitTestTrait {
        ExmentKitTestTrait::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public $baseUrl = 'http://localhost';

    // ...
    protected function login()
    {
        // precondition : login success
        $this->visit('/admin/auth/logout')
                ->visit('/admin/auth/login')
                ->type('testuser', 'username')
                ->type('test123456', 'password')
                ->press('ログイン')
                ->seePageIs('/admin');
    }
}