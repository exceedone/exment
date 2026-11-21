<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\RoleGroup;
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
 * administrator turns it on; when it is off its pages and menus are not there
 * (even by the URL) and its data stays; the plugins keep what they had; an
 * update does not change what the users of a site see.
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
        config(['exment.features_locked' => '']);
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

    /**
     * A role group user1 is in and user2 is not.
     */
    protected function groupOfUser1Only(): int
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1, 'user1 has a role group user2 does not have');
        return $only1[0];
    }

    public function testFeaturesAreOffUntilTurnedOn(): void
    {
        $this->setFeatures([]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertFalse(Features::enabled(Features::STUDIO));
        $this->assertFalse(Features::allowed(Features::STUDIO));
        $this->assertTrue(Features::isNew(Features::STUDIO));
        $this->assertFalse(Features::enabled('not_a_feature'));
        // the file server only when Exment has it
        $this->assertSame(class_exists('\Exceedone\Exment\Services\FileServer\Library'), Features::exists(Features::FILE_SERVER));

        Features::save(['studio' => ['enabled' => true]]);
        $this->assertTrue(Features::enabled(Features::STUDIO));
        $this->assertTrue(Features::allowed(Features::STUDIO));
        $this->assertFalse(Features::isNew(Features::STUDIO));
        // the others are kept when one is saved
        Features::save(['plugin:x' => ['menu' => false]]);
        $this->assertTrue(Features::enabled(Features::STUDIO));
    }

    public function testRoleGroups(): void
    {
        $group = $this->groupOfUser1Only();
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => [$group], 'menu' => true]]);
        $this->assertTrue(Features::allowed(Features::STUDIO, $user1));
        $this->assertFalse(Features::allowed(Features::STUDIO, $user2));
        // a system administrator always may (when it is used)
        $this->assertTrue(Features::allowed(Features::STUDIO, $admin));

        // none chosen: the system administrators only (not "all")
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => [], 'menu' => true]]);
        $this->assertSame([], Features::setting(Features::STUDIO)['role_groups']);
        $this->assertFalse(Features::allowed(Features::STUDIO, $user1));
        $this->assertTrue(Features::allowed(Features::STUDIO, $admin));
        Features::save(['studio' => ['enabled' => true, 'role_groups' => []]]);
        $this->assertSame([], Features::setting(Features::STUDIO)['role_groups']);
        Features::save(['studio' => ['enabled' => true, 'role_groups' => null]]);
        $this->assertNull(Features::setting(Features::STUDIO)['role_groups']);
        $this->assertTrue(Features::allowed(Features::STUDIO, $user2));

        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $this->assertFalse(Features::allowed(Features::STUDIO, $admin));
    }

    public function testTheServerKeepsFeaturesOff(): void
    {
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        config(['exment.features_locked' => 'file_server, studio']);
        $this->assertTrue(Features::locked(Features::STUDIO));
        $this->assertFalse(Features::enabled(Features::STUDIO));
        $this->assertSame('locked', Features::reason(Features::STUDIO));
        // the page does not change it
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_url('feature'), ['features' => ['studio' => ['present' => '1', 'all' => '1', 'menu' => '1']]])->assertRedirect();
        System::clearRequestSession();
        $this->assertTrue(Features::setting(Features::STUDIO)['enabled']);
        config(['exment.features_locked' => '']);
        $this->assertTrue(Features::enabled(Features::STUDIO));
    }

    public function testTheUrlOfAFeature(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('studio'), $headers)->assertOk();
        $this->get(admin_url('app/manifest.webmanifest'))->assertOk();

        // off: not found, also for the system administrators
        $this->setFeatures(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('studio'), $headers)->assertNotFound()->assertJson(['message' => exmtrans('feature.message_not_used')]);
        $this->get(admin_url('studio/create'), $headers)->assertNotFound();
        $page = $this->get(admin_url('app'));
        $page->assertNotFound();
        $page->assertSee(exmtrans('feature.message_not_used'));
        $page->assertSee(admin_url('feature'), false);
        $this->get(admin_url('app/sw.js'))->assertNotFound();
        // pages of other names are not touched
        $this->assertNotSame(404, $this->get(admin_url('dashboard'), $headers)->getStatusCode());

        // used, but not for this user: forbidden
        $this->setFeatures(['studio' => ['enabled' => true, 'role_groups' => [$this->groupOfUser1Only()], 'menu' => true]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_url('app'))->assertForbidden()->assertSee(exmtrans('feature.message_not_allowed'))->assertDontSee(admin_url('feature'), false);
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
        $this->assertNull(Features::forRequest($make('studios')));
        $this->assertNull(Features::forRequest($make('application')));
        $this->assertNull(Features::forRequest($make('data/information')));
        $this->assertNull(Features::forRequest($make('api/data/information')));

        // the features of a plugin: off until turned on; the longest endpoint wins
        Features::register('exment_test.desk', ['label' => 'Desk', 'endpoints' => ['plugins/exment_test']]);
        Features::register('exment_test.mail', ['label' => 'Mail', 'endpoints' => ['plugins/exment_test/mail'], 'api' => ['plugins/exment_test/mail']]);
        $this->assertTrue(Features::exists('exment_test.mail'));
        $this->assertFalse(Features::allowed('exment_test.mail'));
        $this->assertSame('exment_test.mail', Features::forRequest($make('plugins/exment_test/mail/inbox')));
        $this->assertSame('exment_test.mail', Features::forRequest($make('api/plugins/exment_test/mail/send')));
        $this->assertSame('exment_test.desk', Features::forRequest($make('plugins/exment_test/other')));
        $this->assertNull(Features::forRequest($make('plugins/exment_other')));
        // the menus go with the pages (a plugin's menu, or a custom menu to the URL)
        $this->assertTrue(Features::hidesMenuUri('plugins/exment_test/mail'));
        $this->assertTrue(Features::hidesMenuUri('plugins/exment_test/mail/inbox?x=1'));
        $this->assertFalse(Features::hidesMenuUri('plugins/exment_other'));
        $this->assertFalse(Features::hidesMenuUri('https://example.com/plugins/exment_test/mail'));
        $this->assertFalse(Features::hidesMenuUri(null));

        // the names of Exment's features, "plugin:" and "_" are not taken
        Features::register('studio', ['label' => 'x']);
        Features::register('plugin:x', ['label' => 'x']);
        Features::register('_since', ['label' => 'x']);
        $this->assertNotSame('x', Features::label('studio'));
        $this->assertArrayNotHasKey('plugin:x', Features::definitions());
        $this->assertArrayNotHasKey('_since', Features::definitions());
        // a key of the translations is translated when shown
        Features::register('exment_test.lang', ['label' => 'exment::exment.feature.header']);
        $this->assertSame(exmtrans('feature.header'), Features::label('exment_test.lang'));
        Features::register('exment_test.plain', ['label' => 'a::b c']);
        $this->assertSame('a::b c', Features::label('exment_test.plain'));
    }

    public function testRequiresAndWho(): void
    {
        Features::register('exment_test.desk', ['label' => 'Desk', 'endpoints' => ['plugins/exment_test']]);
        Features::register('exment_test.portal', ['label' => 'Portal', 'endpoints' => ['plugins/exment_test/portal'], 'requires' => 'exment_test.desk', 'who' => false]);
        $group = $this->groupOfUser1Only();
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $register = function () {
            Features::register('exment_test.desk', ['label' => 'Desk', 'endpoints' => ['plugins/exment_test']]);
            Features::register('exment_test.portal', ['label' => 'Portal', 'endpoints' => ['plugins/exment_test/portal'], 'requires' => 'exment_test.desk', 'who' => false]);
        };

        $this->setFeatures(['exment_test.portal' => ['enabled' => true, 'role_groups' => [$group], 'menu' => true]]);
        $register();
        $this->assertFalse(Features::enabled('exment_test.portal'), 'the feature it needs is off');
        $this->assertSame('requires', Features::reason('exment_test.portal'));

        $this->setFeatures([
            'exment_test.desk' => ['enabled' => true, 'role_groups' => [$group], 'menu' => true],
            'exment_test.portal' => ['enabled' => true, 'role_groups' => [$group], 'menu' => true],
        ]);
        $register();
        $this->assertTrue(Features::enabled('exment_test.portal'));
        // "who" is not asked for the portal: all who may use the desk
        $this->assertTrue(Features::allowed('exment_test.portal', $user1));
        $this->assertFalse(Features::allowed('exment_test.portal', $user2), 'not when the user may not use what it needs');
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

        // turned on with its menu on the page: the menu is made again when it was removed
        DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'studio')->delete();
        $this->post(admin_url('feature'), ['features' => ['studio' => ['present' => '1', 'enabled' => '1', 'all' => '1', 'menu' => '1']]])->assertRedirect();
        $this->assertTrue(DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'studio')->exists());
    }

    public function testThePageKeepsThePluginsAsTheyAre(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $before = $this->pluginState();
        $this->get(admin_url('feature'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee(exmtrans('feature.header'));

        // save the page as it is shown: the plugins stay as they were (the
        // page does not change whether a plugin is active)
        $plugins = $this->pluginInput();
        foreach ($plugins as $name => $row) {
            unset($plugins[$name]['menu']);
        }
        $this->post(admin_url('feature'), ['plugins' => $this->pluginInput()])->assertRedirect();
        $this->assertSame($before, $this->pluginState());
        $this->post(admin_url('feature'), ['plugins' => $plugins])->assertRedirect();
        $this->assertSame($before['plugins'], $this->pluginState()['plugins'], 'menus off do not make plugins inactive');

        // a group checked on the page gets plugin_access; a change made
        // meanwhile on the page of the role groups stays
        $plugin = Plugin::query()->get()->first(function ($plugin) {
            $types = (array)$plugin->plugin_types;
            return count(array_intersect($types, \Exceedone\Exment\Enums\PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE())) > 0
                && !boolval($plugin->getOption('all_user_enabled'));
        });
        $this->assertNotNull($plugin);
        $input = $this->pluginInput();
        $groups = RoleGroup::query()->pluck('id')->map(function ($id) {
                return intval($id);
            })->all();
        $shown = json_decode($input[$plugin->plugin_name]['shown'], true);
        $free = array_values(array_diff($groups, $shown));
        if (count($free) < 2) {
            $this->markTestIncomplete('the test data needs two role groups without the plugin');
        }
        // meanwhile, on the page of the role groups: free[1] gets it
        RoleGroupPermission::create(['role_group_id' => $free[1], 'role_group_permission_type' => RoleType::PLUGIN, 'role_group_target_id' => $plugin->id, 'permissions' => ['plugin_access']]);
        $input[$plugin->plugin_name]['role_groups'] = array_merge($shown, [$free[0]]);
        $this->post(admin_url('feature'), ['plugins' => $input])->assertRedirect();
        $now = $this->pluginAccess($plugin);
        $this->assertContains($free[0], $now);
        $this->assertContains($free[1], $now, 'not taken off: it was not on the page');
    }

    /**
     * The input of the page for the plugins, as it is shown.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function pluginInput(): array
    {
        $plugins = [];
        foreach (Plugin::query()->get() as $plugin) {
            $groups = $this->pluginAccess($plugin);
            $plugins[$plugin->plugin_name] = ['present' => '1', 'menu' => '1', 'role_groups' => $groups, 'shown' => json_encode($groups)];
        }
        return $plugins;
    }

    /**
     * @return int[]
     */
    protected function pluginAccess(Plugin $plugin): array
    {
        return RoleGroupPermission::query()->where('role_group_permission_type', RoleType::PLUGIN)->where('role_group_target_id', $plugin->id)->get()
            ->filter(function ($p) {
                return in_array('plugin_access', (array)$p->permissions, true);
            })->pluck('role_group_id')->map(function ($id) {
                return intval($id);
            })->values()->all();
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
        $logTable = config('admin.database.operation_log_table', 'admin_operation_log');
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $run = function () use ($migration) {
            DB::table('systems')->where('system_name', 'features')->delete();
            $migration->up();
            System::clearRequestSession();
            Features::reset();
        };

        // a menu alone (an update adds it) does not make Studio on
        DB::table($logTable)->where('path', 'like', '%studio%')->orWhere('path', 'like', '%/app%')->delete();
        $run();
        $this->assertFalse(Features::enabled(Features::STUDIO));
        $this->assertTrue(DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->exists());
        $this->assertSame($plugins, $this->pluginState(), 'the plugins stay as they are');

        // someone used Studio: it stays on
        DB::table($logTable)->insert(['user_id' => 1, 'path' => trim($prefix . '/studio', '/'), 'method' => 'GET', 'ip' => '127.0.0.1', 'input' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $run();
        $this->assertTrue(Features::enabled(Features::STUDIO));
        $this->assertFalse(Features::isNew(Features::STUDIO));

        // the features the plugins had before stay on, for a plugin installed before the update
        $plugin = Plugin::query()->first();
        $this->assertNotNull($plugin);
        Plugin::query()->whereKey($plugin->id)->update(['created_at' => now()->subDays(10)]);
        System::clearRequestSession();
        $uri = strval($plugin->getOption('uri') ?: $plugin->plugin_name);
        $register = function () use ($uri) {
            Features::register($uri . '.old', ['label' => 'Old', 'existing' => true]);
            Features::register($uri . '.new', ['label' => 'New']);
        };
        $register();
        $this->assertTrue(Features::enabled($uri . '.old'));
        $this->assertFalse(Features::enabled($uri . '.new'));
        // a plugin installed after it: off
        Plugin::query()->whereKey($plugin->id)->update(['created_at' => now()->addDay()]);
        System::clearRequestSession();
        Features::reset();
        $register();
        $this->assertFalse(Features::enabled($uri . '.old'));

        // a second run changes nothing
        Features::save(['studio' => ['enabled' => false]]);
        $migration->up();
        System::clearRequestSession();
        $this->assertFalse(Features::enabled(Features::STUDIO));
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'feature')->count());

        // a new site (no menus yet): all is new, and plugins' features are off
        DB::table('systems')->where('system_name', 'features')->delete();
        System::clearRequestSession();
        Features::reset();
        $register();
        $this->assertFalse(Features::enabled($uri . '.old'));
    }
}
