<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The screens and ajax endpoints that only AdminScreensSmokeTest reached. The smoke test asserts
 * status < 500; here each one is opened the same way (as the admin, as XMLHttpRequest) and what it
 * answers is checked: the redirect target, the screen header with its form or grid and the stored
 * data, the modal's form, or the json the screen script reads. The ajax endpoints get the
 * parameters their screens send. The expectations follow what CI answered (a probe of 2026-10-19).
 */
class AdminScreensContentTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be($this->admin());
    }

    /**
     * The show (and some list) routes of the setting screens only redirect.
     */
    #[DataProvider('redirectProvider')]
    public function testTheRouteRedirectsToItsScreen(string $path, string $target): void
    {
        $this->open($path)->assertRedirect(admin_url($target));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function redirectProvider(): array
    {
        return [
            'dashboard show' => ['dashboard/1', 'dashboard/1/edit'],
            'dashboard box list' => ['dashboardbox', ''],
            'dashboard box show' => ['dashboardbox/1', 'dashboardbox/1/edit'],
            'cell style preset show' => ['cell_style_preset/1', 'cell_style_preset/1/edit'],
            'template import page' => ['template/import', 'template'],
            'login setting show' => ['login_setting/1', 'login_setting/1/edit'],
            'api setting show' => ['api_setting/1', 'api_setting/1/edit'],
            'plugin show' => ['plugin/1', 'plugin/1/edit'],
            'table show' => ['table/1', 'table/1/edit'],
            'workflow show' => ['workflow/1', 'workflow/1/edit'],
            'login user show' => ['loginuser/1', 'loginuser/1/edit'],
            'role group show' => ['role_group/1', 'role_group/1/edit'],
            'role' => ['role', 'role_group'],
            'meili filter show' => ['meili-filter/1', 'meili-filter/1/edit'],
            'meili dictionary show' => ['meili-dictionary/1', 'meili-dictionary/1/edit'],
            'column show' => ['column/custom_value_edit_all/1', 'column/custom_value_edit_all/1/edit'],
            'form show' => ['form/custom_value_edit_all/1', 'form/custom_value_edit_all/1/edit'],
            'form priority list' => ['formpriority/custom_value_edit_all', 'form/custom_value_edit_all'],
            'form priority show' => ['formpriority/custom_value_edit_all/1', 'formpriority/custom_value_edit_all/1/edit'],
            'public form list' => ['formpublic/custom_value_edit_all', 'form/custom_value_edit_all'],
            'public form show' => ['formpublic/custom_value_edit_all/1', 'formpublic/custom_value_edit_all/1/edit'],
            'view show' => ['view/custom_value_edit_all/1', 'view/custom_value_edit_all/1/edit'],
            'relation show' => ['relation/custom_value_edit_all/1', 'relation/custom_value_edit_all/1/edit'],
            'copy show' => ['copy/custom_value_edit_all/1', 'copy/custom_value_edit_all/1/edit'],
            'operation show' => ['operation/custom_value_edit_all/1', 'operation/custom_value_edit_all/1/edit'],
            'notify show' => ['notify/custom_value_edit_all/1', 'notify/custom_value_edit_all/1/edit'],
            'install reset' => ['install/reset', 'install'],
        ];
    }

    public function testTwoFactorLogoutLogsTheUserOut(): void
    {
        $this->open('auth-2factor/logout')->assertRedirect(admin_url());
        $this->assertGuest('admin');
    }

    public function testAJanCodeOfARecordOpensTheRecord(): void
    {
        $table = $this->table('custom_value_edit_all');
        $record = $this->firstRecord($table);
        DB::table('jan_codes')->insert([
            'table_id' => $table->id,
            'target_id' => $record->id,
            'jan_code' => '4912345678904',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $form = CustomForm::getDefault($table);
        $this->open('jan-code/4912345678904')->assertRedirect(
            admin_urls('data', $table->table_name, $record->id, 'edit?formid=' . $form->suuid . '&redirect-dashboard=1')
        );
    }

    public function testANewJanCodeOpensTheCreateFormOfTheOnlyJanTable(): void
    {
        $table = $this->table('custom_value_edit_all');
        $this->activateJanCode($table);

        $form = CustomForm::getDefault($table);
        $create = admin_urls('data', $table->table_name, 'create?formid=' . $form->suuid . '&redirect-dashboard=1&jan_code=4912345678911');
        $this->open('jan-code/4912345678911')->assertRedirect($create);

        // the table chosen on the list of JAN tables leads to the same form
        $this->open('assign-jan-code?' . http_build_query(['table_id' => $table->id, 'jan_code_id' => '4912345678911']))
            ->assertRedirect($create);
        // without a table there is nothing to assign to
        $this->open('assign-jan-code')->assertRedirect(admin_url());
    }

    public function testANewJanCodeListsTheJanTablesWhenThereAreSeveral(): void
    {
        $tables = [$this->table('custom_value_edit_all'), $this->table('custom_value_view_all')];
        foreach ($tables as $table) {
            $this->activateJanCode($table);
        }

        $this->open('jan-code/4912345678928')->assertRedirect(admin_urls('jan-code', 'table', '4912345678928'));

        $list = $this->open('jan-code/table/4912345678928');
        $this->assertScreen($list, exmtrans('custom_table.jan_code.header'));
        foreach ($tables as $table) {
            $list->assertSee($table->table_name);
        }
        // each row carries the code to the create form it opens
        $this->assertSame(count($tables), substr_count((string) $list->getContent(), 'jan-code-id="4912345678928"'));
    }

    public function testANotificationOpensItsRecordAndIsMarkedRead(): void
    {
        $navbar = NotifyNavbar::findOrFail(1);
        $navbar->forceFill(['read_flg' => false])->save();
        $record = $this->table(strval($navbar->parent_type))->getValueModel($navbar->parent_id);
        $this->assertNotNull($record);

        $this->open('notify_navbar/rowdetail/1')->assertRedirect($record->getUrl());
        $this->assertTrue(boolval(NotifyNavbar::findOrFail(1)->read_flg));
    }

    public function testNotifyNavbarScreens(): void
    {
        $create = $this->open('notify_navbar/create');
        $this->assertScreen($create, exmtrans('notify_navbar.header'));
        $this->assertForm($this->html($create), 'notify_navbar', 'POST', []);

        $navbar = NotifyNavbar::findOrFail(1);
        $show = $this->open('notify_navbar/1');
        $this->assertScreen($show, exmtrans('notify_navbar.header'));
        $show->assertSee(strval($navbar->notify_subject));
    }

    public function testReadingATaskOpensItsRecord(): void
    {
        $list = $this->open('workflow_task');
        $this->assertScreen($list, exmtrans('workflow_task.header'));
        // each task links to read?key=<table id>:<record id>
        if (preg_match('#/workflow_task/read\?key=(\d+)%3A(\d+)"#', $this->html($list), $match) !== 1) {
            $this->fail('the task list links no task');
        }
        $record = $this->table(intval($match[1]))->getValueModel(intval($match[2]));
        $this->assertNotNull($record);

        $this->open('workflow_task/read?key=' . urlencode("{$match[1]}:{$match[2]}"))->assertRedirect($record->getUrl());
        // a missing or broken key goes back to the list
        $this->open('workflow_task/read')->assertRedirect(admin_url('workflow_task'));
    }

    public function testOperationLogListsTheRequestsAndItsSettings(): void
    {
        $this->open('auth/setting')->assertOk();

        $logs = $this->open('auth/logs');
        $this->assertScreen($logs, trans('admin.operation_log'));
        $logs->assertSee('admin/auth/setting');
        $this->assertForm($this->html($logs), 'auth/logs', 'GET', ['user_id', 'method', 'path', 'ip']);
        $this->assertForm($this->html($logs), 'auth/logs/setting', 'POST', ['operation_log_enable_automatic', 'operation_log_keep_days']);
    }

    public function testUserSettingShowsThePasswordForm(): void
    {
        $setting = $this->open('auth/setting');
        $this->assertScreen($setting, exmtrans('login.user_setting'));
        $this->assertForm($this->html($setting), 'auth/setting', 'POST', [
            'avatar', 'current_password', 'password', 'password_confirmation', '_method' => 'PUT',
        ]);
    }

    public function testCellStylePresetScreens(): void
    {
        $list = $this->open('cell_style_preset?per_page=100');
        $this->assertScreen($list, exmtrans('cell_style_preset.header'));
        foreach (CellStylePreset::all() as $preset) {
            $list->assertSee(strval($preset->preset_name));
        }

        $create = $this->open('cell_style_preset/create');
        $this->assertScreen($create, exmtrans('cell_style_preset.header'));
        $this->assertForm($this->html($create), 'cell_style_preset', 'POST', ['preset_name', 'options[grid_style]', 'column_types[]']);

        $preset = CellStylePreset::findOrFail(1);
        $edit = $this->open('cell_style_preset/1/edit');
        $this->assertScreen($edit, exmtrans('cell_style_preset.header'));
        $this->assertForm($this->html($edit), 'cell_style_preset/1', 'POST', ['preset_name' => strval($preset->preset_name)]);
    }

    public function testTemplateScreenShowsTheExportAndImportForms(): void
    {
        $template = $this->open('template');
        $this->assertScreen($template, exmtrans('template.header'));
        $this->assertForm($this->html($template), 'template/export', 'POST', ['template_name', 'template_view_name', 'export_target[]', 'target_tables[]']);
        $this->assertForm($this->html($template), 'template/import', 'POST', ['upload_template']);
    }

    public function testWorkflowScreens(): void
    {
        $workflow = Workflow::findOrFail(1);

        $list = $this->open('workflow');
        $this->assertScreen($list, exmtrans('workflow.header'));
        $list->assertSee(strval($workflow->workflow_view_name));

        $create = $this->open('workflow/create');
        $this->assertScreen($create, exmtrans('workflow.header'));
        $this->assertForm($this->html($create), 'workflow', 'POST', ['workflow_view_name', 'workflow_type', 'custom_table_id', 'start_status_name']);

        $edit = $this->open('workflow/1/edit');
        $this->assertScreen($edit, exmtrans('workflow.header'));
        $this->assertForm($this->html($edit), 'workflow/1', 'POST', [
            'workflow_view_name' => strval($workflow->workflow_view_name),
            'start_status_name' => strval($workflow->start_status_name),
        ]);

        // the beginning form switches each workflow of each table on or off
        $beginning = $this->open('workflow/beginning');
        $this->assertScreen($beginning, exmtrans('workflow.beginning'));
        $this->assertMatchesRegularExpression('/name="workflow_tables\[\d+\]\[workflows\]\[1\]\[active_flg\]"/', $this->html($beginning));

        // the designer boots with the statuses of the workflow
        $design = $this->open('workflow/1/design');
        $this->assertScreen($design, exmtrans('workflow.design.header'));
        foreach ($workflow->workflow_statuses()->get() as $status) {
            $design->assertSee('"name":' . json_encode(strval($status->status_name), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), false);
        }
        $new = $this->open('workflow/design/create');
        $this->assertScreen($new, exmtrans('workflow.design.new_workflow'));
        $new->assertSee('Exment.WorkflowDesignEdit.boot(', false);

        $this->assertModal($this->open('workflow/1/activateModal'), exmtrans('workflow.setting_complete'), 'workflow/1/activate', 'POST', ['activate_keyword', 'add_notify_flg']);
        $this->assertModal($this->open('workflow/1/deactivateModal'), exmtrans('workflow.delete_complete'), 'workflow/1/deactivate', 'POST', ['activate_keyword']);
    }

    public function testNotifyScreens(): void
    {
        // the old notify list only says where the settings went
        $moved = $this->open('notify');
        $this->assertScreen($moved, exmtrans('notify.header'));
        $moved->assertSee(exmtrans('notify.notify_moved'));

        $notify = Notify::findOrFail(1);
        $list = $this->open('notify/custom_value_edit_all');
        $this->assertScreen($list, exmtrans('notify.header'));
        $list->assertSee(strval($notify->notify_view_name));

        $create = $this->open('notify/custom_value_edit_all/create');
        $this->assertScreen($create, exmtrans('notify.header'));
        $this->assertForm($this->html($create), 'notify/custom_value_edit_all', 'POST', ['notify_name', 'notify_view_name', 'notify_trigger', 'custom_view_id']);

        $edit = $this->open('notify/custom_value_edit_all/1/edit');
        $this->assertForm($this->html($edit), 'notify/custom_value_edit_all/1', 'POST', ['notify_view_name' => strval($notify->notify_view_name)]);
    }

    public function testNotifyTargetsDependOnTheAction(): void
    {
        $table = $this->table('custom_value_edit_all');
        $emailColumn = $table->custom_columns_cache->first(fn ($column) => $column->column_type === ColumnType::EMAIL);
        $userColumn = $table->custom_columns_cache->first(fn ($column) => $column->column_type === ColumnType::USER);
        $this->assertNotNull($emailColumn);
        $this->assertNotNull($userColumn);

        $mail = $this->ids('notify/custom_value_edit_all/notify_action_target?q=' . NotifyAction::EMAIL);
        $page = $this->ids('notify/custom_value_edit_all/notify_action_target?q=' . NotifyAction::SHOW_PAGE);
        foreach (['administrator', 'has_roles', 'created_user', 'fixed_user', 'fixed_organization', $userColumn->id] as $target) {
            $this->assertContains($target, $mail);
            $this->assertContains($target, $page);
        }
        // only a mail goes to a fixed address or to an email column of the record
        $this->assertContains('fixed_email', $mail);
        $this->assertContains($emailColumn->id, $mail);
        $this->assertNotContains('fixed_email', $page);
        $this->assertNotContains($emailColumn->id, $page);

        // a public form notifies the administrators, the role users or a fixed address
        $this->assertSame(['administrator', 'has_roles', 'fixed_email'], $this->ids('formpublic/custom_value_edit_all/notify_action_target?q=' . NotifyAction::EMAIL));
    }

    public function testPluginScreens(): void
    {
        $list = $this->open('plugin?per_page=100');
        $this->assertScreen($list, exmtrans('plugin.header'));
        foreach (Plugin::all() as $plugin) {
            $list->assertSee(strval($plugin->plugin_name));
        }
        $this->assertForm($this->html($list), 'plugin', 'POST', ['fileUpload']);

        $plugin = Plugin::findOrFail(1);
        $edit = $this->open('plugin/1/edit');
        $this->assertScreen($edit, exmtrans('plugin.header'));
        $this->assertForm($this->html($edit), 'plugin/1', 'POST', ['active_flg', 'options[uri]' => strval($plugin->getOption('uri')), '_method' => 'PUT']);
    }

    public function testPluginCodeEditorOpensAFileOfTheTree(): void
    {
        $plugin = Plugin::findOrFail(1);
        $editor = $this->open('plugin/edit_code/1');
        $this->assertScreen($editor, exmtrans('plugincode.header'));
        $this->assertForm($this->html($editor), 'plugin/edit_code/1/fileupload', 'POST', ['fileUpload[]', 'nodeid']);

        $none = $this->open('plugin/edit_code/1/selectFile')->assertOk();
        $this->assertStringContainsString(exmtrans('plugincode.message.select_file'), strval($none->json('editor')));

        // the tree the editor loads keeps its nodes in the session; a php file of it opens in the code editor
        $nodes = collect((array) $this->open('plugin/edit_code/1/getTree')->assertOk()->json());
        $file = $nodes->first(fn ($node) => is_array($node) && ($node['icon'] ?? null) === 'jstree-file' && str_ends_with(strval($node['text'] ?? ''), '.php'));
        $this->assertIsArray($file, 'the plugin has a php file');
        $path = str_replace('//', '/', strval($file['path']));

        $code = strval($this->open('plugin/edit_code/1/selectFile?nodeid=' . urlencode(strval($file['id'])))->assertOk()->json('editor'));
        $this->assertStringContainsString('<span>' . e($path) . '</span>', $code);
        $this->assertStringContainsString('<textarea id="edit_plugin_file" class="form-control">' . e(strval($plugin->getPluginFiledata($path))) . '</textarea>', $code);
    }

    public function testLoginSettingScreens(): void
    {
        $list = $this->open('login_setting');
        $this->assertScreen($list, exmtrans('login.header'));
        $this->assertForm($this->html($list), 'login_setting/postglobal', 'POST', ['password_expiration_days', 'password_history_cnt', 'login_background_color']);

        $create = $this->open('login_setting/create');
        $this->assertScreen($create, exmtrans('login.header'));
        $this->assertForm($this->html($create), 'login_setting', 'POST', ['login_view_name', 'login_type', 'options[oauth_provider_type]']);
        $providers = $this->selectOptions($this->html($create), 'options[oauth_provider_type]');
        foreach (['google', 'github', 'other'] as $provider) {
            $this->assertArrayHasKey($provider, $providers);
        }

        // choosing a provider reloads the options area of the form
        $options = $this->open('login_setting/loginOptionHtml?' . http_build_query(['val' => 'github', 'form_type' => '1', 'form_uniqueName' => 'claude']))->assertOk();
        $this->assertStringContainsString('form_dynamic_options_response', strval($options->json('body')));
        $this->assertArrayHasKey('script', (array) $options->json());
    }

    public function testApiSettingListsTheClients(): void
    {
        $list = $this->open('api_setting?per_page=100');
        $this->assertScreen($list, exmtrans('api.header'));
        // the admin may use every client
        $clients = ApiClient::all();
        $this->assertGreaterThan(0, $clients->count());
        foreach ($clients as $client) {
            $list->assertSee(strval($client->name));
            $list->assertSee(strval($client->id));
        }
    }

    public function testLoginUserScreens(): void
    {
        $admin = $this->table(SystemTableName::USER)->getValueModel(1);
        $this->assertNotNull($admin);
        $list = $this->open('loginuser');
        $this->assertScreen($list, exmtrans('user.header'));
        $list->assertSee(strval($admin->getValue('email')));

        $edit = $this->open('loginuser/1/edit');
        $this->assertScreen($edit, exmtrans('user.header'));
        $this->assertForm($this->html($edit), 'loginuser/1', 'POST', ['use_loginuser' => '1', 'password', 'password_confirmation', '_method' => 'PUT']);

        $this->assertImportModal($this->open('loginuser/importModal'), 'loginuser/import');
    }

    public function testRoleGroupScreens(): void
    {
        $create = $this->open('role_group/create');
        $this->assertScreen($create, exmtrans('role_group.header'));
        $this->assertForm($this->html($create), 'role_group', 'POST', ['role_group_name', 'role_group_view_name', 'system_permission[system][permissions][]']);

        $group = RoleGroup::findOrFail(1);
        $edit = $this->open('role_group/1/edit');
        $this->assertScreen($edit, exmtrans('role_group.header'));
        $this->assertForm($this->html($edit), 'role_group/1', 'POST', ['role_group_view_name' => strval($group->role_group_view_name)]);

        $this->assertImportModal($this->open('role_group/importModal'), 'role_group/import');
    }

    public function testMeiliScreens(): void
    {
        $filters = $this->open('meili-filter');
        $this->assertScreen($filters, exmtrans('system.meili'));
        $this->assertForm($this->html($filters), 'meili-filter', 'GET', ['id']);

        $create = $this->open('meili-filter/create');
        $this->assertScreen($create, exmtrans('system.meili'));
        $this->assertForm($this->html($create), 'meili-filter', 'POST', ['custom_table_id', 'column_name', 'filter_type', 'mode', 'view_label', 'alias', 'order']);
        $this->assertSame(['', 'equality', 'range'], array_keys($this->selectOptions($this->html($create), 'filter_type')));

        // the column list of a table: the columns a filter can use, as "name (type)"
        $table = $this->table('custom_value_edit_all');
        $columns = collect((array) $this->open('meili-filter/columns?q=' . $table->id)->assertOk()->json())->pluck('text', 'id')->all();
        $this->assertSame('integer (integer)', $columns['integer'] ?? null);
        $this->assertSame('date (date)', $columns['date'] ?? null);
        $this->assertArrayNotHasKey('text', $columns);

        $dictionary = $this->open('meili-dictionary');
        $this->assertScreen($dictionary, exmtrans('system.meili_dictionary'));
        $newWord = $this->open('meili-dictionary/create');
        $this->assertScreen($newWord, exmtrans('system.meili_dictionary'));
        $this->assertForm($this->html($newWord), 'meili-dictionary', 'POST', ['type', 'word', 'synonyms', 'order', 'enabled']);
        $this->assertSame(['', 'synonym', 'stopword'], array_keys($this->selectOptions($this->html($newWord), 'type')));
    }

    public function testTableModals(): void
    {
        // the menu of a table links to its setting pages
        $table = $this->table('custom_value_edit_all');
        $menu = $this->open("table/menuModal/{$table->id}")->assertOk()
            ->assertJsonPath('title', exmtrans('change_page_menu.change_page_label'))
            ->assertJsonPath('showSubmit', false);
        foreach (['column', 'relation', 'form', 'view'] as $page) {
            $this->assertStringContainsString('href="' . admin_urls($page, $table->table_name) . '"', strval($menu->json('body')));
        }

        $this->assertModal($this->open('table/1/copyModal'), exmtrans('custom_table.copy_custom_table'), 'table/1/copy', 'POST', ['table_name', 'table_view_name', 'include_view_flg', 'include_form_flg']);
    }

    public function testShareModals(): void
    {
        foreach ([
            'dashboard/1/shareClick' => ['dashboard/1/sendShares', 'data_share_edit[]', 'data_share_view[]'],
            'view/custom_value_edit_all/1/shareClick' => ['view/custom_value_edit_all/1/sendShares', 'data_share_edit[]', 'data_share_view[]'],
            'data/custom_value_edit_all/1/shareClick' => ['data/custom_value_edit_all/1/sendShares', 'custom_value_edit[]', 'custom_value_view[]'],
        ] as $path => [$action, $edit, $view]) {
            $share = $this->open($path);
            $this->assertModal($share, exmtrans('common.shared'), $action, 'POST', [$edit, $view]);
            // the users to share with include the admin
            $this->assertArrayHasKey('user_1', $this->selectOptions(strval($share->json('body')), $edit));
        }
    }

    public function testBackupRestoreModal(): void
    {
        $this->assertModal($this->open('backup/importModal'), exmtrans('backup.restore'), 'backup/import', 'POST', ['upload_zipfile', 'restore_keyword']);
    }

    public function testDataImportModal(): void
    {
        $this->assertImportModal($this->open('data/custom_value_edit_all/importModal'), 'data/custom_value_edit_all/import', ['custom_table_name' => 'custom_value_edit_all']);
    }

    public function testRevisionCompareShowsTheChosenRevision(): void
    {
        $record = $this->newRecord('content revision zero');
        $record->setValue('text', 'content revision one')->save();
        $record->setValue('text', 'content revision two')->save();
        $revision = $record->revisionHistory()->get()->first(function ($revision) {
            $value = json_decode((string) $revision->new_value, true);
            return is_array($value) && array_get($value, 'text') === 'content revision one';
        });
        $this->assertNotNull($revision, 'saving the record wrote no revision');
        $suuid = strval($revision->suuid);

        $compare = $this->open("data/custom_value_edit_all/{$record->id}/compare");
        $this->assertScreen($compare, exmtrans('revision.revision'));
        $this->assertArrayHasKey($suuid, $this->selectOptions($this->html($compare), null), 'the revision list');

        // until a revision is chosen the latest is compared with itself
        $this->open("data/custom_value_edit_all/{$record->id}/compareitem")->assertOk()
            ->assertSee('content revision two')
            ->assertDontSee('content revision one')
            ->assertDontSee($suuid);
        $this->open("data/custom_value_edit_all/{$record->id}/compareitem?revision={$suuid}")->assertOk()
            ->assertSee($suuid)
            ->assertSee('content revision one')
            ->assertSee('content revision two');
    }

    public function testKanbanHistoryListsTheWorkflowHistory(): void
    {
        $value = WorkflowValue::query()->orderBy('id')->firstOrFail();
        $record = $this->table(strval($value->morph_type))->getValueModel($value->morph_id);
        $this->assertNotNull($record);
        $histories = $record->getWorkflowHistories();
        $this->assertGreaterThan(0, count($histories));

        $rows = (array) $this->open("data/{$value->morph_type}/{$value->morph_id}/kanbanHistory")->assertOk()->json('rows');
        $this->assertCount(count($histories), $rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertSame(['at', 'action', 'from', 'to', 'user', 'comment'], array_keys($row));
        }
        // each row is a step of the record: the status it went to
        $this->assertEqualsCanonicalizing(
            $histories->map(fn ($history) => strval($history->workflow_status_name))->all(),
            array_column($rows, 'to')
        );
    }

    public function testRestoreClickRestoresADeletedRecord(): void
    {
        $table = $this->table('custom_value_edit_all');
        $record = $this->newRecord('content restore');
        $record->delete();
        $this->assertNull($this->reload($table, $record->id));
        $this->assertTrue((bool) $this->reload($table, $record->id, true)?->trashed());

        $this->open("data/custom_value_edit_all/{$record->id}/restoreClick")->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('message', exmtrans('custom_value.message.restore_succeeded'));
        $restored = $this->reload($table, $record->id);
        $this->assertNotNull($restored, 'the restored record is found again');
        $this->assertFalse($restored->trashed());
    }

    public function testPreviewWithoutAPostedFormShowsTheExpiredMessage(): void
    {
        $this->open('view/custom_value_edit_all/preview')->assertOk()->assertSee(exmtrans('common.message.preview_error'));
        $this->open('form/custom_value_edit_all/preview')->assertOk()->assertSee(exmtrans('common.message.preview_error'));
    }

    public function testViewSummaryAndGroupConditionsFollowTheColumnType(): void
    {
        // a number can be summed; any column can be counted and have a min and a max
        $this->assertSame(['1', '3', '4', '5'], $this->ids('view/all_columns_table_fortest/summary-condition?q=' . urlencode($this->columnTarget(ColumnType::INTEGER))));
        $this->assertSame(['3', '4', '5'], $this->ids('view/all_columns_table_fortest/summary-condition?q=' . urlencode($this->columnTarget(ColumnType::TEXT))));
        // a date groups by year, month, day...
        $this->assertSame(['y', 'ym', 'ymd', 'm', 'd', 'w'], $this->ids('view/all_columns_table_fortest/group-condition?q=' . urlencode($this->columnTarget(ColumnType::DATE))));
    }

    public function testCopyNewModalListsTheTargetTables(): void
    {
        $modal = $this->open('copy/custom_value_edit_all/newModal');
        $this->assertModal($modal, trans('admin.setting'), 'copy/custom_value_edit_all/create', 'GET', ['to_custom_table']);
        $options = $this->selectOptions(strval($modal->json('body')), 'to_custom_table');
        foreach (CustomTable::where('showlist_flg', true)->get() as $table) {
            $this->assertSame(strval($table->table_view_name), $options[strval($table->suuid)] ?? null);
        }
    }

    public function testOperationValueAreaIsRenderedForAChosenColumn(): void
    {
        // no column chosen yet: nothing to render
        $this->open('operation/all_columns_table_fortest/filter-value')->assertOk()->assertExactJson([]);

        // the area of the value; its script then loads the input of the column type
        $response = $this->open('operation/all_columns_table_fortest/filter-value?' . http_build_query([
            'target_name' => 'custom_operation_columns[0][view_column_target]',
            'target_val' => $this->columnTarget(ColumnType::TEXT),
        ]))->assertOk();
        $json = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertSame(['html', 'script'], array_keys($json));
        $this->assertStringContainsString('changefield-div', strval($json['html']));
    }

    public function testPublicFormActivationModalListsTheTablesItUses(): void
    {
        $publicForm = PublicForm::findOrFail(1);
        $modal = $this->open('formpublic/custom_value_edit_all/1/activeModal');
        $this->assertModal($modal, trans('admin.alert'), 'formpublic/custom_value_edit_all/1/activate', 'POST', []);
        $modal->assertJsonPath('submitlabel', trans('admin.setting'));
        foreach ($publicForm->getListOfTablesUsed() as $table) {
            $this->assertStringContainsString('<li>' . esc_html($table->table_view_name) . '</li>', strval($modal->json('body')));
        }
    }

    public function testFormPriorityScreens(): void
    {
        $table = $this->table('custom_value_edit_all');
        $create = $this->open('formpriority/custom_value_edit_all/create');
        $this->assertScreen($create, exmtrans('custom_form_priority.header'));
        $this->assertForm($this->html($create), 'formpriority/custom_value_edit_all', 'POST', ['custom_form_id', 'order']);
        $forms = $this->selectOptions($this->html($create), 'custom_form_id');
        foreach (CustomForm::where('custom_table_id', $table->id)->get() as $form) {
            $this->assertSame(strval($form->form_view_name), $forms[strval($form->id)] ?? null);
        }

        $priority = CustomFormPriority::findOrFail(1);
        $edit = $this->open('formpriority/custom_value_edit_all/1/edit');
        $this->assertScreen($edit, exmtrans('custom_form_priority.header'));
        $this->assertForm($this->html($edit), 'formpriority/custom_value_edit_all/1', 'POST', ['order' => strval($priority->order)]);
    }

    public function testMenuTargetOptions(): void
    {
        $table = $this->table('custom_value_edit_all');
        $tables = collect((array) $this->open('webapi/menu/menutype?q=' . MenuType::TABLE)->assertOk()->json())->pluck('text', 'id')->all();
        $this->assertSame(strval($table->table_view_name), $tables[$table->id] ?? null);

        $system = $this->ids('webapi/menu/menutype?q=' . MenuType::SYSTEM);
        foreach (['home', 'custom_table', 'role_group', 'workflow', 'plugin', 'loginuser'] as $page) {
            $this->assertContains($page, $system);
        }

        $view = CustomView::where('custom_table_id', $table->id)->where('view_view_name', 'custom_value_edit_all-view-all')->firstOrFail();
        $views = collect((array) $this->open('webapi/menu/menutargetview?q=' . $table->id)->assertOk()->json())->pluck('text', 'id')->all();
        $this->assertSame('custom_value_edit_all-view-all', $views[$view->id] ?? null);
    }

    public function testMentionUsers(): void
    {
        $admin = $this->table(SystemTableName::USER)->getValueModel(1);
        $this->assertNotNull($admin);
        $expected = ['id' => 1, 'code' => strval($admin->getValue('user_code')), 'name' => strval($admin->getValue('user_name'))];

        // "@" with nothing typed lists users from the first
        $this->open('webapi/mention/users')->assertOk()->assertJsonPath('data.0', $expected);
        $this->open('webapi/mention/users?q=' . urlencode($expected['code']))->assertOk()->assertExactJson(['data' => [$expected]]);
    }

    public function testCellStylePresetWebApi(): void
    {
        $keys = array_column(CellStylePreset::getDefinitions(), 'key');
        $this->assertGreaterThan(0, count($keys));
        $this->assertSame($keys, collect((array) $this->open('webapi/custom_value_edit_all/cellstylepreset')->assertOk()->json('presets'))->pluck('key')->all());

        $modal = $this->open('webapi/custom_value_edit_all/cellstylepreset/modal?key=' . urlencode(strval($keys[0])))->assertOk()
            ->assertJsonPath('result', true)
            ->assertJsonPath('title', exmtrans('cell_style_preset.modal_title'))
            ->assertJsonPath('showSubmit', false)
            ->assertJsonPath('modalSize', 'modal-lg');
        $styles = $this->selectOptions(strval($modal->json('body')), 'grid_style');
        foreach (['plain', 'pill', 'badge'] as $style) {
            $this->assertArrayHasKey($style, $styles);
        }
    }

    public function testConditionOptionsFollowTheColumnType(): void
    {
        $text = urlencode($this->columnTarget(ColumnType::TEXT));
        $date = urlencode($this->columnTarget(ColumnType::DATE));

        $this->assertSame([1, 2, 5, 6, 3, 4], $this->ids("webapi/all_columns_table_fortest/filter-condition?q=$text"));
        $dateConditions = $this->ids("webapi/all_columns_table_fortest/filter-condition?q=$date");
        $this->assertContains(1001, $dateConditions);
        $this->assertContains(1011, $dateConditions);
        $this->assertNotContains(5, $dateConditions);

        // an operation sets a fixed value, or a system value (now) for a date
        $this->assertSame(['default'], $this->ids("webapi/all_columns_table_fortest/operation-update-type?q=$text"));
        $this->assertSame(['default', 'system'], $this->ids("webapi/all_columns_table_fortest/operation-update-type?q=$date"));

        $value = $this->open('webapi/all_columns_table_fortest/operation-filter-value?' . http_build_query([
            'target' => $this->columnTarget(ColumnType::TEXT),
            'cond_key' => '1',
            'cond_name' => 'custom_view_filters[0][view_filter_condition]',
        ]))->assertOk();
        $json = json_decode((string) $value->getContent(), true);
        $this->assertIsArray($json);
        $this->assertStringContainsString(exmtrans('condition.condition_value'), strval($json['html'] ?? ''));
    }

    public function testAuthForms(): void
    {
        $this->assertForm($this->html($this->open('auth/forget')->assertOk()), 'auth/forget', 'POST', ['email']);
        $this->assertForm($this->html($this->open('auth/change')->assertOk()), 'auth/change', 'POST', ['current_password', 'password', 'password_confirmation']);
        $this->assertForm($this->html($this->open('auth-2factor')->assertOk()), 'auth-2factor/verify', 'POST', ['verify_code']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function open(string $path): TestResponse
    {
        // exment caches records for a request; a test that changed data must read it again
        System::clearRequestSession();

        return $this->get(admin_url($path), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function html(TestResponse $response): string
    {
        return (string) $response->getContent();
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertScreen(TestResponse $response, string $header): void
    {
        $response->assertOk();
        $response->assertSee($header);
    }

    /**
     * The ids of the options an ajax endpoint answers, in order.
     *
     * @return array<int, mixed>
     */
    private function ids(string $path): array
    {
        return collect((array) $this->open($path)->assertOk()->json())->pluck('id')->values()->all();
    }

    /**
     * Assert that the html holds a form to $action with $method and the fields; a string key also
     * fixes the value of the field.
     *
     * @param array<int|string, string> $fields
     */
    private function assertForm(string $html, string $action, string $method, array $fields): void
    {
        $url = admin_url($action);
        $form = collect($this->forms($html))->first(fn (array $form) => $form['action'] === $url && $form['method'] === $method);
        $this->assertIsArray($form, "no $method form to $url");
        foreach ($fields as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $this->assertArrayHasKey($name, $form['fields'], "the form to $url has no field $name");
            if (!is_int($key)) {
                $this->assertSame($value, $form['fields'][$name], "the field $name of the form to $url");
            }
        }
    }

    /**
     * @param TestResponse<Response> $response
     * @param array<int|string, string> $fields
     */
    private function assertModal(TestResponse $response, string $title, string $action, string $method, array $fields): void
    {
        $response->assertOk()->assertJsonPath('result', true)->assertJsonPath('title', $title);
        $this->assertForm(strval($response->json('body')), $action, $method, $fields);
    }

    /**
     * The import modal of a table: its title starts with "import" and its form takes the file.
     *
     * @param TestResponse<Response> $response
     * @param array<int|string, string> $fields
     */
    private function assertImportModal(TestResponse $response, string $action, array $fields = []): void
    {
        $response->assertOk()->assertJsonPath('result', true);
        $this->assertTrue(str_starts_with(strval($response->json('title')), exmtrans('common.import')), 'the title of the import modal');
        $this->assertForm(strval($response->json('body')), $action, 'POST', array_merge(['custom_table_file', 'select_primary_key', 'select_action'], $fields));
    }

    /**
     * The forms of an html page or fragment: action, method and the value of each field (the
     * first one of a repeated name, as the hidden input before a checkbox).
     *
     * @return array<int, array{action: string, method: string, fields: array<string, string>}>
     */
    private function forms(string $html): array
    {
        $xpath = $this->xpath($html);
        $forms = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof \DOMElement) {
                continue;
            }
            $fields = [];
            foreach ($xpath->query('.//input[@name]|.//select[@name]|.//textarea[@name]', $form) ?: [] as $field) {
                if ($field instanceof \DOMElement) {
                    $fields[$field->getAttribute('name')] ??= $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
                }
            }
            $forms[] = ['action' => $form->getAttribute('action'), 'method' => strtoupper($form->getAttribute('method')), 'fields' => $fields];
        }

        return $forms;
    }

    /**
     * The options (value => text) of the select named $name, or of the first select.
     *
     * @return array<string, string>
     */
    private function selectOptions(string $html, ?string $name): array
    {
        $xpath = $this->xpath($html);
        $query = $name === null ? '(//select)[1]//option' : '//select[@name="' . $name . '"]//option';
        $options = [];
        foreach ($xpath->query($query) ?: [] as $option) {
            if ($option instanceof \DOMElement) {
                $options[$option->getAttribute('value')] = trim($option->textContent);
            }
        }

        return $options;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    /**
     * The "column?table_id=" target the view, operation and condition forms send for a column of
     * all_columns_table_fortest.
     */
    private function columnTarget(string $columnType): string
    {
        $table = $this->table('all_columns_table_fortest');
        $column = $table->custom_columns_cache->first(fn ($column) => $column->column_type === $columnType);
        $this->assertNotNull($column, "all_columns_table_fortest has a $columnType column");

        return $column->id . '?table_id=' . $table->id;
    }

    private function table(string|int $table): CustomTable
    {
        $custom_table = CustomTable::getEloquent($table);
        $this->assertInstanceOf(CustomTable::class, $custom_table);

        return $custom_table;
    }

    private function firstRecord(CustomTable $table): CustomValue
    {
        $record = $table->getValueModel()->query()->orderBy('id')->first();
        $this->assertInstanceOf(CustomValue::class, $record);

        return $record;
    }

    private function reload(CustomTable $table, int $id, bool $withTrashed = false): ?CustomValue
    {
        // exment caches the records it read for a request
        System::clearRequestSession();

        return $table->getValueModel($id, $withTrashed);
    }

    private function newRecord(string $text): CustomValue
    {
        $record = $this->table('custom_value_edit_all')->getValueModel();
        $this->assertInstanceOf(CustomValue::class, $record);
        $record->setValue(['text' => $text])->save();

        return $record;
    }

    private function activateJanCode(CustomTable $table): void
    {
        $table->setOption('active_jan_flg', true);
        $table->save();
    }

    private function admin(): LoginUser
    {
        return LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }
}
