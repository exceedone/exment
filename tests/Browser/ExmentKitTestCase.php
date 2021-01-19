<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\Constraints\HasOuterElement;
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
    protected function setUp()
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


    /**
     * Assert that a given string is seen inside an element.
     *
     * @param  string  $element
     * @param  string  $text
     * @param  bool  $negate
     * @return $this
     */
    public function seeOuterElement($element, $text, $negate = false)
    {
        return $this->assertInPage(new HasOuterElement($element, $text), $negate);
    }
}