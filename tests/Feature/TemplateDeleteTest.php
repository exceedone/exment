<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for user-template delete feature on the initial setup screen.
 *
 * Bug context:
 *   After install, clicking the trash icon on a user-uploaded template shows
 *   the SweetAlert confirmation dialog. The user confirms, the POST returns
 *   200 OK, yet the dialog stays in "processing" state and never closes.
 *
 * Root cause:
 *   CallbackExmentAjax calls redirectCallback(res) which calls
 *   $.pjax.reload('#pjax-container'). This synchronously fires the "pjax:send"
 *   jQuery event. The laravel-admin.js pjax:send handler calls NProgress.start()
 *   which calls NProgress.render(). NProgress is configured with parent: '#app'
 *   (set globally in laravel-admin.js). On the initialize page there is NO
 *   element with id="app", so document.querySelector('#app') returns null, and
 *   null.appendChild(...) throws a TypeError. This error propagates back through
 *   CallbackExmentAjax, preventing resolve(res) from ever being called, so the
 *   SweetAlert preConfirm promise never resolves.
 */
class TemplateDeleteTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    // -----------------------------------------------------------------------
    // Backend / API tests
    // -----------------------------------------------------------------------

    /**
     * TC-1: DELETE /webapi/template/delete must return HTTP 200 with
     *       result === true and a message when a valid template name is given.
     *
     * This verifies the server side is NOT the problem.
     */
    public function testDeleteTemplateApiReturns200WithResultTrue(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // Create a dummy user template so there is something to delete.
        $templateName = 'test_template_delete_' . time();
        Storage::disk('template')->makeDirectory($templateName);

        $response = $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept'           => 'application/json',
        ])->delete(admin_url("webapi/template/delete"), [
            '_token'   => csrf_token(),
            'template' => $templateName,
        ]);

        $response->assertStatus(200);

        $json = $response->json();

        // JS expects result === true (strict boolean) to enter the success branch
        // of CallbackExmentAjax. Any other value keeps the dialog stuck.
        $this->assertTrue($json['result'] === true, 'result must be strict boolean true');

        // A non-null message causes ShowSwal .then() to display a success swal.
        $this->assertNotEmpty($json['message'] ?? '', 'response must contain a message');
    }

    /**
     * TC-2: The response must NOT contain a truthy "swal" field.
     *
     * If res.swal is truthy, CallbackExmentAjax skips the resolve(res) call
     * (see: if (hasValue(resolve) && !hasValue(res.swal))), which means the
     * preConfirm promise never resolves and the dialog stays stuck.
     */
    public function testDeleteResponseHasNullSwalField(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $templateName = 'test_template_swal_' . time();
        Storage::disk('template')->makeDirectory($templateName);

        $response = $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept'           => 'application/json',
        ])->delete(admin_url("webapi/template/delete"), [
            '_token'   => csrf_token(),
            'template' => $templateName,
        ]);

        $response->assertStatus(200);

        $json = $response->json();

        // hasValue(null) === false in JS, so !hasValue(res.swal) === true
        // => resolve(res) IS called => dialog closes.
        // hasValue("...") === true  => !hasValue(res.swal) === false
        // => resolve(res) is skipped => dialog stays stuck.
        $this->assertNull(
            $json['swal'] ?? null,
            'swal must be null so the JS callback reaches resolve(res)'
        );
    }

    /**
     * TC-3: POST /api/template/search (the search used by InitializeFormTrait)
     *       must return HTML that contains data-exment-delete only on user
     *       templates, NOT on system templates.
     *
     * This confirms the delete URL is generated and the button exists in the DOM.
     */
    public function testTemplateSearchReturnsDeleteButtonForUserTemplates(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // Create a user template.
        $userTemplateName = 'test_user_tmpl_' . time();
        Storage::disk('template')->makeDirectory($userTemplateName);

        // Inject a minimal config.json so the importer recognises it.
        Storage::disk('template')->put(
            $userTemplateName . '/config.json',
            json_encode([
                'template_name'      => $userTemplateName,
                'template_view_name' => 'Unit Test Template',
                'template_type'      => 'user',
            ], JSON_THROW_ON_ERROR)
        );

        $response = $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(admin_url("api/template/search"), [
            '_token' => csrf_token(),
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
        $html = (string) $response->getContent();

        // User templates should have a trash button with data-exment-delete.
        $this->assertStringContainsString(
            'data-exment-delete',
            $html,
            'User template tile must contain a data-exment-delete button'
        );

        // The delete URL must contain the template name.
        $this->assertStringContainsString(
            'template=' . $userTemplateName,
            $html,
            'The data-exment-delete URL must reference the correct template name'
        );

        // Clean up.
        Storage::disk('template')->deleteDirectory($userTemplateName);
    }

    /**
     * TC-4: System templates must NOT have a data-exment-delete button.
     *       Deleting system templates is not allowed.
     */
    public function testTemplateSearchSystemTemplateHasNoDeleteButton(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // Count pre-existing user templates (storage directories that contain a
        // config.json) BEFORE the request so the assertion is environment-agnostic.
        $disk = Storage::disk('template');
        $preExistingUserTemplateCount = count(array_filter(
            $disk->directories(),
            function ($dir) use ($disk) {
                return $disk->exists($dir . '/config.json');
            }
        ));

        $response = $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(admin_url("api/template/search"), [
            '_token' => csrf_token(),
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
        $html = (string) $response->getContent();

        // The number of data-exment-delete attributes in the rendered HTML must
        // equal the number of pre-existing user templates exactly.  System
        // templates have delete_url === null so they never emit the attribute.
        // Any surplus would mean a system template tile was incorrectly given a
        // delete button.
        $this->assertSame(
            $preExistingUserTemplateCount,
            substr_count($html, 'data-exment-delete'),
            'System templates must NOT have a data-exment-delete button; ' .
            'only user templates should contribute delete buttons'
        );
    }

    /**
     * TC-5: Verify the response JSON structure matches exactly what
     *       CallbackExmentAjax requires to call resolve().
     *
     * CallbackExmentAjax logic (simplified):
     *   if (res.result === true || res.status === true) {
     *       ...
     *       redirectCallback(res);        // may throw on initialize page!
     *       ...
     *       if (hasValue(resolve) && !hasValue(res.swal)) {
     *           resolve(res);             // dialog closes here
     *       }
     *   }
     *
     * Three conditions must hold for the dialog to close:
     *   A) res.result === true  (boolean strict equality)
     *   B) res.swal is falsy    (null/undefined/"")
     *   C) redirectCallback does NOT throw before resolve is reached
     *
     * This test covers A and B. TC-6 (JS) covers C.
     */
    public function testDeleteResponseStructureSatisfiesJsConditionsForResolve(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $templateName = 'test_tmpl_structure_' . time();
        Storage::disk('template')->makeDirectory($templateName);

        $response = $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept'           => 'application/json',
        ])->delete(admin_url("webapi/template/delete"), [
            '_token'   => csrf_token(),
            'template' => $templateName,
        ]);

        $response->assertStatus(200);
        $json = $response->json();

        // Condition A: result must be strictly boolean true
        $this->assertSame(true, $json['result'], 'Condition A: result must be boolean true');

        // Condition B: swal must be null / falsy
        $this->assertFalse(
            !empty($json['swal']),
            'Condition B: swal must be falsy so resolve(res) is called'
        );

        // Confirm there is a localised message for the UI .then() success swal
        $this->assertNotEmpty($json['message'], 'A success message must be present for UI feedback');
    }
}
