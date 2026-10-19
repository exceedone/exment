<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiClientType;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * store/update/destroy of settings screens that had no coverage at all: the cell-style-preset
 * library (admin/cell_style_preset), the API clients (admin/api_setting), the per-table
 * cell-style-preset webapi (admin/webapi/{tableKey}/cellstylepreset), the table notifies
 * (admin/notify/{tableKey}) and the custom-form-priority list (admin/formpriority/{tableKey}). Each answers a redirect or plain json, never a view/export
 * handler that could exit() mid-test, so this is safe to call for real.
 */
class SettingsCrudCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // CellStylePresetSettingController (admin/cell_style_preset) -----------------

    public function testCellStylePresetSettingCrud(): void
    {
        // store/update end in admin-core Form::ajaxResponse(), which reads Request::capture()
        // (the PHP globals, not the test request), so a test header never makes them answer
        // json: a successful save is always the redirect back to the list.
        $this->post(admin_url('cell_style_preset'), [
            'preset_name' => 'Claude coverage preset',
            'order' => 5,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cell_style_presets', ['preset_name' => 'Claude coverage preset']);

        $preset = CellStylePreset::where('preset_name', 'Claude coverage preset')->first();
        $this->assertNotNull($preset);

        $this->put(admin_url("cell_style_preset/{$preset->id}"), [
            'preset_name' => 'Claude coverage preset (put)',
            'order' => 5,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cell_style_presets', ['id' => $preset->id, 'preset_name' => 'Claude coverage preset (put)']);

        $this->patch(admin_url("cell_style_preset/{$preset->id}"), [
            'preset_name' => 'Claude coverage preset (patch)',
            'order' => 5,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cell_style_presets', ['id' => $preset->id, 'preset_name' => 'Claude coverage preset (patch)']);

        $this->delete(admin_url("cell_style_preset/{$preset->id}"))
            ->assertStatus(200)
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('cell_style_presets', ['id' => $preset->id]);
    }

    // ApiSettingController (admin/api_setting) -----------------------------------

    public function testApiSettingCrud(): void
    {
        // saveData() validates, writes the oauth client through ApiClientRepository and
        // always redirects to the client's edit page; destroy() answers json.
        $clients = (new ApiClient())->getTable();

        $response = $this->post(admin_url('api_setting'), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Claude coverage api client',
            'redirect' => 'http://localhost/claude-coverage',
        ]);
        $client = ApiClient::where('name', 'Claude coverage api client')->first();
        $this->assertNotNull($client);
        $response->assertRedirect(admin_urls('api_setting', $client->id, 'edit'));
        $this->assertSame('http://localhost/claude-coverage', $client->redirect);
        $this->assertSame(ApiClientType::CLIENT_CREDENTIALS, $client->client_type);

        $this->put(admin_url("api_setting/{$client->id}"), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Claude coverage api client (put)',
            'redirect' => 'http://localhost/claude-coverage-put',
        ])->assertRedirect(admin_urls('api_setting', $client->id, 'edit'));
        $this->assertDatabaseHas($clients, [
            'id' => $client->id,
            'name' => 'Claude coverage api client (put)',
            'redirect' => 'http://localhost/claude-coverage-put',
        ]);

        $this->patch(admin_url("api_setting/{$client->id}"), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Claude coverage api client (patch)',
            'redirect' => 'http://localhost/claude-coverage-patch',
        ])->assertRedirect(admin_urls('api_setting', $client->id, 'edit'));
        $this->assertDatabaseHas($clients, [
            'id' => $client->id,
            'name' => 'Claude coverage api client (patch)',
            'redirect' => 'http://localhost/claude-coverage-patch',
        ]);

        $this->delete(admin_url("api_setting/{$client->id}"))
            ->assertStatus(200)
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing($clients, ['id' => $client->id]);
    }

    public function testApiSettingStoreWithoutARedirectUrlIsRejected(): void
    {
        $this->post(admin_url('api_setting'), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Claude coverage api client (no redirect)',
        ])->assertRedirect()->assertSessionHasErrors('redirect');

        $this->assertNull(ApiClient::where('name', 'Claude coverage api client (no redirect)')->first());
    }

    // CellStylePresetController (admin/webapi/{tableKey}/cellstylepreset) -------

    public function testCellStylePresetWebapiCrud(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

        $response = $this->post(admin_url("webapi/{$tableKey}/cellstylepreset"), [
            'preset_name' => 'Claude webapi preset',
            'grid_style' => 'text',
        ]);
        $response->assertStatus(200)->assertJson(['result' => true]);
        $this->assertDatabaseHas('cell_style_presets', ['preset_name' => 'Claude webapi preset']);

        $preset = CellStylePreset::where('preset_name', 'Claude webapi preset')->first();
        $this->assertNotNull($preset);

        $this->put(admin_url("webapi/{$tableKey}/cellstylepreset/{$preset->suuid}"), [
            'preset_name' => 'Claude webapi preset (updated)',
            'grid_style' => 'text',
        ])->assertStatus(200)->assertJson(['result' => true]);
        $this->assertDatabaseHas('cell_style_presets', ['id' => $preset->id, 'preset_name' => 'Claude webapi preset (updated)']);

        $this->delete(admin_url("webapi/{$tableKey}/cellstylepreset/{$preset->suuid}"))
            ->assertStatus(200)
            ->assertJson(['result' => true]);
        $this->assertDatabaseMissing('cell_style_presets', ['id' => $preset->id]);
    }

    // CustomNotifyController (admin/notify/{tableKey}) ---------------------------

    /**
     * @return array<string, mixed>
     */
    private function notifyPayload(string $name): array
    {
        // Only requiredRule() fields are validated server-side; required() alone is the HTML
        // attribute. Every hasManyJson item needs its _remove_ key (NestedForm reads it unguarded).
        return [
            'notify_view_name' => $name,
            'active_flg' => 1,
            'notify_trigger' => NotifyTrigger::BUTTON,
            'trigger_settings' => ['notify_button_name' => 'Claude coverage button'],
            'action_settings' => ['new_1' => [
                'notify_action' => NotifyAction::SHOW_PAGE,
                'notify_action_target' => [NotifyActionTarget::ADMINISTRATOR],
                '_remove_' => 0,
            ]],
        ];
    }

    public function testCustomNotifyCrud(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $table = CustomTable::getEloquent($tableKey);
        $this->assertNotNull($table);
        $redirectTo = admin_url("notify/{$tableKey}");

        $this->post(admin_url("notify/{$tableKey}"), $this->notifyPayload('Claude coverage notify'))
            ->assertRedirect($redirectTo)
            ->assertSessionHasNoErrors();
        $notify = Notify::where('notify_view_name', 'Claude coverage notify')->first();
        $this->assertNotNull($notify);
        $this->assertDatabaseHas('notifies', [
            'id' => $notify->id,
            'target_id' => $table->id,
            'notify_trigger' => (int) NotifyTrigger::BUTTON,
        ]);

        $this->put(admin_url("notify/{$tableKey}/{$notify->id}"), $this->notifyPayload('Claude coverage notify (put)'))
            ->assertRedirect($redirectTo)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifies', ['id' => $notify->id, 'notify_view_name' => 'Claude coverage notify (put)']);

        $this->patch(admin_url("notify/{$tableKey}/{$notify->id}"), $this->notifyPayload('Claude coverage notify (patch)'))
            ->assertRedirect($redirectTo)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifies', ['id' => $notify->id, 'notify_view_name' => 'Claude coverage notify (patch)']);

        $this->delete(admin_url("notify/{$tableKey}/{$notify->id}"))
            ->assertStatus(200)
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('notifies', ['id' => $notify->id]);
    }

    // CustomFormPriorityController (admin/formpriority/{tableKey}) --------------

    public function testCustomFormPriorityCrud(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $table = CustomTable::getEloquent($tableKey);
        $this->assertNotNull($table);

        $form = $table->custom_forms->first();
        if (!isset($form)) {
            $form = CustomForm::create([
                'custom_table_id' => $table->id,
                'form_view_name' => 'Claude coverage form',
                'default_flg' => 1,
            ]);
        }

        // The form's saved() hook (CustomFormPriorityController::form()) always
        // returns its own redirect, on both store and update, before the form
        // ever reaches ajaxResponse() - so a successful save is a 302 to the
        // form-priority list, never json, whatever headers the request sends.
        $redirectTo = admin_url("form/{$tableKey}");

        // custom_form_priorities has no custom_table_id column, and the seed may already hold
        // priorities for this form: find the new row by the ids that existed before.
        $beforeIds = CustomFormPriority::pluck('id')->all();

        $this->post(admin_url("formpriority/{$tableKey}"), [
            'custom_form_id' => $form->id,
            'order' => 1,
            'condition_join' => 'and',
        ])->assertRedirect($redirectTo);

        $priority = CustomFormPriority::where('custom_form_id', $form->id)
            ->whereNotIn('id', $beforeIds)
            ->first();
        $this->assertNotNull($priority);

        $this->put(admin_url("formpriority/{$tableKey}/{$priority->id}"), [
            'custom_form_id' => $form->id,
            'order' => 2,
            'condition_join' => 'and',
        ])->assertRedirect($redirectTo);
        $this->assertDatabaseHas('custom_form_priorities', ['id' => $priority->id, 'order' => 2]);

        $this->patch(admin_url("formpriority/{$tableKey}/{$priority->id}"), [
            'custom_form_id' => $form->id,
            'order' => 3,
            'condition_join' => 'and',
        ])->assertRedirect($redirectTo);
        $this->assertDatabaseHas('custom_form_priorities', ['id' => $priority->id, 'order' => 3]);

        $this->delete(admin_url("formpriority/{$tableKey}/{$priority->id}"))
            ->assertStatus(200)
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('custom_form_priorities', ['id' => $priority->id]);
    }
}
