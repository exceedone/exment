<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * The first screen of a new system (admin/initialize), which asks for the first administrator.
 * The system is put back to "installed, not initialized" inside the test transaction.
 */
class InitializeScreenCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::initialized(0);
        System::clearCache();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testTheInitializePageAsksForTheFirstAdministrator(): void
    {
        $html = (string) $this->get(admin_url('initialize'))->assertOk()->getContent();

        foreach (['user_code', 'user_name', 'email', 'password', 'password_confirmation'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
        $this->assertStringContainsString('action="' . admin_url('initialize') . '"', $html);
    }

    public function testTheInitializeFormRefusesAMissingAdministrator(): void
    {
        // A success also writes EXMENT_INITIALIZE into .env (EnvService::setEnv), which would change
        // the CI app for every later test, so only the validation of the form is checked here.
        $users = LoginUser::count();

        $this->post(admin_url('initialize'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['user_code', 'user_name', 'email', 'password']);

        $this->assertSame($users, LoginUser::count());
        System::clearCache();
        $this->assertFalse(boolval(System::initialized()));
    }
}
