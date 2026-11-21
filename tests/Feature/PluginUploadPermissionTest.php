<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;

/**
 * Uploading a plugin installs PHP code, so it needs the permission of all plugins (PLUGIN_ALL).
 * The route admin/plugin also lets in a user who may only set one plugin (PLUGIN_SETTING of a
 * plugin role); such a user may open the plugin's setting, but not upload a plugin.
 */
class PluginUploadPermissionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * user2 gets the setting permission of one plugin only.
     */
    protected function actAsPluginSetter(): LoginUser
    {
        $plugin = Plugin::first();
        $this->assertNotNull($plugin);

        $role_group = new RoleGroup();
        $role_group->role_group_name = 'plugin_setter_test';
        $role_group->role_group_view_name = 'plugin setter test';
        $role_group->save();

        RoleGroupPermission::create([
            'role_group_id' => $role_group->id,
            'role_group_permission_type' => RoleType::PLUGIN,
            'role_group_target_id' => $plugin->id,
            'permissions' => ['plugin_setting'],
        ]);

        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertInstanceOf(LoginUser::class, $user);
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $user->base_user_id,
        ]);

        System::clearCache();
        System::clearRequestSession();
        $this->be($user);
        return $user;
    }

    public function testAUserWhoMaySetOnePluginDoesNotUploadAPlugin(): void
    {
        $this->actAsPluginSetter();
        $file = UploadedFile::fake()->create('Plugin.zip', 1, 'application/zip');

        System::clearRequestSession();
        $this->post(admin_url('plugin'), ['fileUpload' => $file], static::AJAX)->assertStatus(403);
    }

    public function testAUserWhoMaySetOnePluginDoesNotWriteItsCode(): void
    {
        $this->actAsPluginSetter();
        $plugin = Plugin::first();

        System::clearRequestSession();
        $this->post(admin_urls('plugin', 'edit_code', $plugin->id), [
            'nodeid' => '/config.json',
            'original_file' => 'config.json',
            'edit_file' => '{}',
        ], static::AJAX)->assertStatus(403);

        System::clearRequestSession();
        $this->post(admin_urls('plugin', 'edit_code', $plugin->id, 'fileupload'), [
            'nodeid' => '/',
            'fileUpload' => UploadedFile::fake()->create('evil.php', 1, 'text/plain'),
        ], static::AJAX)->assertStatus(403);
    }

    public function testTheAdministratorStillReachesTheUpload(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();

        // no file: the upload answers with its own message, not with a denial
        $this->post(admin_url('plugin'), [], static::AJAX)->assertStatus(302);
    }
}
