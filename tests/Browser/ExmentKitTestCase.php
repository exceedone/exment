<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Laravel\BrowserKitTesting\TestCase as BaseTestCase;

abstract class ExmentKitTestCase extends BaseTestCase
{
    use \Tests\CreatesApplication;
    use TestTrait;

    protected $baseUrl;

    
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        // cannot call method "config", so call env function
        $this->baseUrl = env('APP_URL');
        parent::setUp();
        System::clearCache();
    }

    // ...
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id?? 1));
    }
}