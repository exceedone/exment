<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\Support\HandleFormNotAForm;
use Exceedone\Exment\Tests\Feature\Support\HandleFormProbe;
use Exceedone\Exment\Tests\TestDefine;

/**
 * POST _handle_form_ (exment-admin-core HandleController::handleForm()) goes through the
 * permission check, and only resolves a widget form.
 *
 * The permission middleware skipped the _handle_* routes, so any logged-in user could run
 * the handle() of any widget form, and the request could make the controller instantiate any
 * class it named.
 *
 * Every request is sent as AJAX: a deny would otherwise take the pjax branch, which sends a
 * whole page and exit()s - taking the test runner with it.
 */
class HandleFormPermissionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        HandleFormProbe::$handled = false;
        HandleFormNotAForm::$constructed = false;
    }

    /**
     * A user without the system permission is refused, and the form's handle() does not run.
     *
     * @return void
     */
    public function testUserWithoutPermissionCannotRunAForm()
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));

        // the same user is refused a system page: the user has no permission for it
        $this->get(admin_url('system'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(403);

        $this->post(admin_url('_handle_form_'), ['_form_' => HandleFormProbe::class], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(403);

        $this->assertFalse(HandleFormProbe::$handled);
    }

    /**
     * The administrator still runs a form.
     *
     * @return void
     */
    public function testAdministratorRunsAForm()
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->post(admin_url('_handle_form_'), ['_form_' => HandleFormProbe::class], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(200);

        $this->assertTrue(HandleFormProbe::$handled);
    }

    /**
     * A class that is not a widget form is not instantiated, even for the administrator.
     *
     * @return void
     */
    public function testClassThatIsNotAFormIsNotInstantiated()
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->post(admin_url('_handle_form_'), ['_form_' => HandleFormNotAForm::class], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(500);

        $this->assertFalse(HandleFormNotAForm::$constructed);
    }
}
