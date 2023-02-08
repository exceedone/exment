<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\Constraints;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Laravel\BrowserKitTesting\TestCase as BaseTestCase;

/**
 * @method \Exceedone\Exment\Tests\DatabaseTransactions beginDatabaseTransaction()
 */
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

    /**
     * Boot the testing helper traits.
     *
     * @return array
     */
    protected function setUpTraits()
    {
        $uses = parent::setUpTraits();

        if (isset($uses[DatabaseTransactions::class])) {
            $this->beginDatabaseTransaction();
        }

        return $uses;
    }

    // ...
    protected function login($id = null)
    {
        $this->be(LoginUser::find($id?? 1));
    }


    protected function matchStatusCode($code)
    {
        $this->assertTrue($code == $this->response->getStatusCode(), "Expects {$code}, but result is " . $this->response->getStatusCode());

        return $this;
    }


    /**
     * Assert that a given string is seen outside an element.
     *
     * @param  string  $element
     * @param  string  $text
     * @param  bool  $negate
     * @return $this
     */
    public function seeOuterElement($element, $text, $negate = false)
    {
        return $this->assertInPage(new Constraints\HasOuterElement($element, $text), $negate);
    }


    /**
     * Assert that a select cptions  an element.
     *
     * @param  string  $element
     * @param  array  $options key: option's value, value: text
     * @param  bool  $negate
     * @return $this
     */
    public function exactSelectOptions($element, array $options, $negate = false)
    {
        return $this->assertInPage(new Constraints\ExactSelectOption($element, $options), $negate);
    }

    /**
     * Assert that a select options  an element.
     *
     * @param  string  $element
     * @param  array  $options key: option's value, value: text
     * @param  bool  $negate
     * @return $this
     */
    public function containsSelectOptions($element, array $options, $negate = false)
    {
        return $this->assertInPage(new Constraints\ContainsSelectOption($element, $options), $negate);
    }
}
