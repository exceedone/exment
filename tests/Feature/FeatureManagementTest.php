<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin setting → Feature management: a new feature is off until a system
 * administrator turns it on; when it is off its pages and menu are not there
 * (even by the URL) and its data stays; the plugins keep what they had.
 */
class FeatureManagementTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Features::reset();
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setFeatures(array $settings): void
    {
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    /**
     * @return string[] the system menus the login user sees
     */
    protected function systemMenus(): array
    {
        return collect((new Menu())->allNodes())->where('menu_type', 'system')->pluck('menu_target')->map('strval')->all();
    }

    public function testFeaturesAreOffUntilTurnedOn(): void
    {
        $this->setFeatures([]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        foreach ([Features::FILE_SERVER, Features::STUDIO] as $key) {
            $this->assertFalse(Features::enabled($key), $key);
            $this->assertFalse(Features::allowed($key), $key);
        }
        $this->assertFalse(Features::enabled('not_a_feature'));

        Features::save(['studio' => ['enabled' => true]]);
        $this->assertTrue(Features::enabled(Features::STUDIO));
        $this->assertTrue(Features::allowed(Features::STUDIO));
        $this->assertFalse(Features::enabled(Features::FILE_SERVER));
        // the others are kept when one is saved
        Features::save(['file_server' => ['enabled' => true]]);
        $this->assertTrue(Features::enabled(Features::STUDIO));
    }

    public function testRoleGroups(): void
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1, 'user1 has a role group user2 does not have');

        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => [$only1[0]], 'menu' => true]]);
        $this->assertTrue(Features::allowed(Features::STUDIO, $user1));
        $this->assertFalse(Features::allowed(Features::STUDIO, $user2));
        // a system administrator always may (when it is used)
        $this->assertTrue(Features::allowed(Features::STUDIO, LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));

        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $this->assertFalse(Features::allowed(Features::STUDIO, LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));
    }

    public function testTheUrlOfAFeatureThatIsOff(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('studio'), $headers)->assertOk();
        $this->get(admin_url('app/manifest.webmanifest'))->assertOk();

        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('studio'), $headers)->assertNotFound();
        $this->get(admin_url('studio/create'), $headers)->assertNotFound();
        $this->get(admin_url('app'))->assertNotFound();
        $this->get(admin_url('app/sw.js'))->assertNotFound();
        // pages of other names are not touched
        $this->assertNotSame(404, $this->get(admin_url('dashboard'), $headers)->getStatusCode());
    }

    public function testTheRequestsOfAFeature(): void
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $make = function (string $path) use ($prefix) {
            return Request::create('/' . trim($prefix . '/' . $path, '/'));
        };
        $this->assertSame(Features::STUDIO, Features::forRequest($make('studio')));
        $this->assertSame(Features::STUDIO, Features::forRequest($make('studio/create')));
        $this->assertSame(Features::STUDIO, Features::forRequest($make('app/manifest.webmanifest')));
        $this->assertSame(Features::STUDIO, Features::forRequest($make('api/studio/screens')));
        $this->assertSame(Features::FILE_SERVER, Features::forRequest($make('file_server/3/folder/4')));
        $this->assertNull(Features::forRequest($make('studios')));
        $this->assertNull(Features::forRequest($make('application')));
        $this->assertNull(Features::forRequest($make('data/information')));
        $this->assertNull(Features::forRequest($make('api/data/information')));

        // a feature of a plugin: off until turned on
        Features::register('exment_test.mail', ['label' => 'Mail', 'endpoints' => ['plugins/exment_test/mail']]);
        $this->assertTrue(Features::exists('exment_test.mail'));
        $this->assertFalse(Features::allowed('exment_test.mail'));
        $this->assertSame('exment_test.mail', Features::forRequest($make('plugins/exment_test/mail/inbox')));
        $this->assertNull(Features::forRequest($make('plugins/exment_test/other')));
        // the names of Exment's features and the plugin menu keys are not taken
        Features::register('studio', ['label' => 'x']);
        Features::register('plugin:x', ['label' => 'x']);
        $this->assertNotSame('x', array_get(Features::definitions(), 'studio.label'));
        $this->assertArrayNotHasKey('plugin:x', Features::definitions());
    }

    public function testMenus(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertContains('studio', $this->systemMenus());

        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotContains('studio', $this->systemMenus());

        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotContains('studio', $this->systemMenus());
        // the row of the menu stays (it comes back when the feature is on again)
        $this->assertTrue(DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'studio')->exists());
    }

    public function testThePageKeepsThePluginsAsTheyAre(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $before = $this->pluginState();
        $this->get(admin_url('feature'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee(exmtrans('feature.header'));

        // save the page as it is shown: the plugins stay as they were
        $plugins = [];
        foreach (Plugin::query()->get() as $plugin) {
            $groups = RoleGroupPermission::query()->where('role_group_permission_type', RoleType::PLUGIN)->where('role_group_target_id', $plugin->id)->get()
                ->filter(function ($p) {
                    return in_array('plugin_access', (array)$p->permissions, true);
                })->pluck('role_group_id')->all();
            $plugins[$plugin->plugin_name] = array_filter([
                'present' => '1',
                'enabled' => $plugin->active_flg ? '1' : null,
                'menu' => '1',
                'role_groups' => $groups,
            ], function ($v) {
                return $v !== null;
            });
        }
        $this->post(admin_url('feature'), [
            'features' => ['studio' => ['enabled' => '1', 'all' => '1', 'menu' => '1'], 'file_server' => ['enabled' => '1', 'all' => '1', 'menu' => '1']],
            'plugins' => $plugins,
        ])->assertRedirect();
        $this->assertSame($before, $this->pluginState());

        // a plugin turned off and on again: its permissions stay
        $plugin = Plugin::query()->where('active_flg', true)->first();
        $this->assertNotNull($plugin);
        $plugins[$plugin->plugin_name]['enabled'] = null;
        unset($plugins[$plugin->plugin_name]['enabled']);
        $plugins[$plugin->plugin_name]['menu'] = null;
        unset($plugins[$plugin->plugin_name]['menu']);
        $this->post(admin_url('feature'), ['plugins' => $plugins])->assertRedirect();
        $this->assertFalse(boolval($plugin->refresh()->active_flg));
        $this->assertTrue(Features::hidesPluginMenu($plugin->plugin_name));
        $plugins[$plugin->plugin_name]['enabled'] = '1';
        $plugins[$plugin->plugin_name]['menu'] = '1';
        $this->post(admin_url('feature'), ['plugins' => $plugins])->assertRedirect();
        $this->assertSame($before, $this->pluginState());
    }

    /**
     * @return array<string, mixed>
     */
    protected function pluginState(): array
    {
        return [
            'plugins' => Plugin::query()->orderBy('id')->get(['id', 'active_flg'])->map(function ($p) {
                return [intval($p->id), boolval($p->active_flg)];
            })->all(),
            'permissions' => RoleGroupPermission::query()->where('role_group_permission_type', RoleType::PLUGIN)->orderBy('id')->get()
                ->map(function ($p) {
                    $list = (array)$p->permissions;
                    sort($list);
                    return [intval($p->role_group_id), intval($p->role_group_target_id), $list];
                })->filter(function ($row) {
                    return count($row[2]) > 0;
                })->values()->all(),
        ];
    }

    public function testTheUpdateKeepsWhatTheSiteHad(): void
    {
        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_11_23_000000_add_feature_management.php');
        $plugins = $this->pluginState();

        // a site that had Studio before the update keeps it; a new menu of the same update does not count
        DB::table('systems')->where('system_name', 'features')->delete();
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'studio')->update(['created_at' => now()->subDays(3)]);
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'file_server')->delete();
        $migration->up();
        System::clearRequestSession();
        Features::reset();
        $this->assertTrue(Features::enabled(Features::STUDIO));
        $this->assertFalse(Features::enabled(Features::FILE_SERVER));
        $this->assertTrue(DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->exists());
        $this->assertSame($plugins, $this->pluginState());

        DB::table('systems')->where('system_name', 'features')->delete();
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'studio')->update(['created_at' => now()]);
        $migration->up();
        System::clearRequestSession();
        Features::reset();
        $this->assertFalse(Features::enabled(Features::STUDIO));

        // a second run changes nothing
        Features::save(['studio' => ['enabled' => true]]);
        $migration->up();
        System::clearRequestSession();
        $this->assertTrue(Features::enabled(Features::STUDIO));
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->count());
    }
}
