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

    /**
     * @var string
     */
    protected $baseUrl;


    /**
     * pre-excecute process before test.
     * @return void
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
     * @return array<mixed>
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

    /**
     * @param mixed|null $id
     * @return void
     */
    protected function login($id = null)
    {
        $targetId = $id ?? 1;
        $user = LoginUser::find($targetId);

        if (!$user) {
            // Try to create a minimal test user if it doesn't exist
            try {
                $this->createTestUserIfNeeded($targetId);
                $user = LoginUser::find($targetId);
            } catch (\Exception $e) {
                // If we still can't find or create the user, throw an informative error
                throw new \RuntimeException(
                    "Test user with ID " . $targetId . " not found and could not be created. " .
                    "Please ensure test data is properly seeded. Error: " . $e->getMessage()
                );
            }
        }

        if (!$user) {
            throw new \RuntimeException(
                "Test user with ID " . $targetId . " not found. Please ensure test data is properly seeded."
            );
        }

        $this->be($user);
    }


    /**
     * @param string|int $code
     * @return $this
     */
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
     * @param  array<mixed>  $options key: option's value, value: text
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
     * @param  array<mixed>  $options key: option's value, value: text
     * @param  bool  $negate
     * @return $this
     */
    public function containsSelectOptions($element, array $options, $negate = false)
    {
        return $this->assertInPage(new Constraints\ContainsSelectOption($element, $options), $negate);
    }
    /**
     * Create a minimal test user if needed
     * @param int $id
     * @return void
     */
    private function createTestUserIfNeeded($id)
    {
        // Check if we have basic custom tables
        $userTable = \Exceedone\Exment\Model\CustomTable::getEloquent('user');
        if (!$userTable) {
            // Try to seed again
            \Artisan::call('db:seed', [
                '--class' => 'Exceedone\\Exment\\Database\\Seeder\\InstallSeeder',
                '--force' => true
            ]);
            \Artisan::call('db:seed', [
                '--class' => 'Exceedone\\Exment\\Database\\Seeder\\TestDataSeeder',
                '--force' => true
            ]);
        }
    }

}
