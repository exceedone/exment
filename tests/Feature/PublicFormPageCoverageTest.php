<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The public form page (publicform/{form_key}) as a guest sees it: the "back" button of the
 * confirm page (POST to the form url) and the confirm/create urls opened by GET (a reload or a
 * bookmark after the session expired), which send the visitor back to the form. The seeder
 * gives the default form of every table two active public forms run as a proxy user.
 */
class PublicFormPageCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testBackFromTheConfirmPageRestoresTheInput(): void
    {
        $publicForm = $this->publicForm();

        // the confirm page keeps the posted values in the session for the way back
        $this->withSession([Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT => ['value' => ['text' => 'claude backed text']]])
            ->post($publicForm->getUrl())
            ->assertRedirect($publicForm->getUrl())
            ->assertSessionHasInput('value.text', 'claude backed text')
            ->assertSessionMissing(Define::SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT);
    }

    public function testConfirmUrlOpenedByGetGoesBackToTheForm(): void
    {
        $this->assertGoesBackToTheFormThatSaysThePageExpired('confirm');
    }

    public function testCreateUrlOpenedByGetGoesBackToTheForm(): void
    {
        $this->assertGoesBackToTheFormThatSaysThePageExpired('create');
    }

    /**
     * The confirm or create URL opened by GET (an expired page, a bookmark) leads back to the form,
     * and the form says why, once. admin_error() keeps its message for the current request only
     * (session()->now()); KeepAdminMessageOnRedirect keeps it for the page the redirect leads to.
     */
    protected function assertGoesBackToTheFormThatSaysThePageExpired(string $action): void
    {
        $publicForm = $this->publicForm();

        $this->get($publicForm->getUrl($action))
            ->assertRedirect($publicForm->getUrl());

        $message = exmtrans('error.expired_error_reinput');
        $this->get($publicForm->getUrl())
            ->assertStatus(200)
            ->assertSee($message, false);

        $this->get($publicForm->getUrl())
            ->assertStatus(200)
            ->assertDontSee($message, false);
    }

    protected function publicForm(): PublicForm
    {
        $form = CustomForm::getDefault(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT));

        return PublicForm::query()
            ->where('custom_form_id', $form->id)
            ->where('active_flg', 1)
            ->orderBy('id')
            ->firstOrFail();
    }
}
