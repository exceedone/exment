<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\ExmentServiceProvider;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\PluginServiceProvider;
use Exceedone\Exment\Services\Plugin\PluginBootGuard;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Storage;

/**
 * An error of one plugin in what runs for every plugin on each page (the views at the boot, the
 * routes, the scripts and styles, the schedule) leaves out that plugin only: Exment and the other
 * plugins keep working, and the error is logged once.
 */
class PluginBootIsolationTest extends FeatureTestBase
{
    /** the files replaced by a version of other types ("page,batch"), the site still has "page" */
    protected const TYPES = 'TestPluginBootTypes';
    /** a page plugin that throws when its views are made, and inside the group of its routes */
    protected const THROWS = 'TestPluginBootThrows';
    /** a script plugin that throws when its scripts are put on the page (its CDNs not a list) */
    protected const SCRIPT = 'TestPluginBootScript';
    /** batch plugins: a cron that cannot be read, and one that can */
    protected const BAD_CRON = 'TestPluginBootBadCron';
    protected const GOOD_CRON = 'TestPluginBootGoodCron';

    /** @var array<string, Plugin> */
    protected $plugins = [];

    /** @var array<MessageLogged> */
    protected $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->delete();
        }
        foreach ([static::TYPES, static::THROWS, static::SCRIPT] as $name) {
            Storage::disk(Define::DISKNAME_PLUGIN_LOCAL)->deleteDirectory($name);
        }
        PluginBootGuard::forget($this->names());
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testABrokenPluginDoesNotStopThePages(): void
    {
        $this->makePlugins();

        // the boot of a request with the plugins (it stopped every page before)
        $this->refreshApplication();
        $this->initAllTest();

        \Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
        PluginBootGuard::forget($this->names());

        // what the boot does for the plugins, again with the log watched
        $this->callProtected($this->app->getProvider(ExmentServiceProvider::class), 'load');
        $groups = count(app('router')->getGroupStack());
        $this->callProtected($this->app->getProvider(PluginServiceProvider::class), 'map');
        $this->assertSame($groups, count(app('router')->getGroupStack()), 'a route group of the broken plugin is left open');

        // the login and the dashboard
        $this->get(admin_url('auth/login'))->assertStatus(200);
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // the scripts of the other script plugin (TestPluginScript) are still on the page
        $this->get(admin_url(''))->assertStatus(200)->assertSee('ajaxzip3-source.js', false);
        $this->get(admin_url('plugin'))->assertStatus(200);

        // the other plugins are loaded: the views and the routes of TestPluginPage
        $page = Plugin::where('plugin_name', 'TestPluginPage')->firstOrFail();
        /** @var \Illuminate\View\FileViewFinder $finder */
        $finder = view()->getFinder();
        $this->assertArrayHasKey('exment_test_plugin_page', $finder->getHints());
        $this->assertTrue(app('router')->has("exment.plugins.{$page->id}.get.index"), 'the routes of TestPluginPage');

        // the schedule: the batch whose cron cannot be read is left out, the others are kept
        $schedule = new Schedule();
        ExmentServiceProvider::registerSchedule($schedule);
        $commands = collect($schedule->events())->map(function ($event) {
            return strval($event->command);
        })->implode("\n");
        $bad = $this->plugins[static::BAD_CRON];
        $good = $this->plugins[static::GOOD_CRON];
        $this->assertStringContainsString("exment:batch {$good->id}", $commands);
        $this->assertDoesNotMatchRegularExpression("/exment:batch {$bad->id}\\b/", $commands);
        $this->assertStringContainsString('exment:schedule', $commands);

        // each broken plugin logged once, without the stack trace; the types are told
        $all = collect($this->logged)->map(function (MessageLogged $event) {
            return $event->level . ': ' . $event->message;
        })->implode("\n");
        $this->assertSame(1, $this->countLogged(static::TYPES, function (string $message) {
            return strpos($message, "the installed files expect type 'page,batch' but the site has 'page'; upload the plugin again or update plugin_types") !== false;
        }), $all);
        $this->assertSame(1, $this->countLogged(static::THROWS, function (string $message) {
            return strpos($message, 'RuntimeException: broken in the boot') !== false;
        }), $all);
        $this->assertSame(1, $this->countLogged(static::SCRIPT, function (string $message) {
            return strpos($message, '(failed in: scripts and styles). ErrorException: foreach()') !== false;
        }), $all);
        $this->assertSame(1, $this->countLogged(static::BAD_CRON, function (string $message) {
            return strpos($message, 'is not valid') !== false;
        }), $all);
        $this->assertSame(0, $this->countLogged(static::GOOD_CRON), $all);
        $this->assertSame(0, $this->countLogged('TestPluginPage'), $all);
    }

    /**
     * How many times the plugin was logged as left out.
     */
    protected function countLogged(string $name, ?\Closure $check = null): int
    {
        return collect($this->logged)->filter(function (MessageLogged $event) use ($name, $check) {
            return $event->level === 'error'
                && strpos($event->message, "[Exment] plugin {$name}:") === 0
                && (is_null($check) || $check($event->message));
        })->count();
    }

    protected function makePlugins(): void
    {
        $disk = Storage::disk(Define::DISKNAME_PLUGIN_LOCAL);

        // like a plugin whose files were replaced: config.json and the classes of "page,batch",
        // the row of the site still "page", so that the class made is the class "Plugin" of the
        // new version, which is no page
        $ns = 'App\\Plugins\\' . static::TYPES;
        $disk->put(static::TYPES . '/config.json', strval(json_encode([
            'plugin_name' => static::TYPES,
            'plugin_view_name' => static::TYPES,
            'uuid' => '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a01',
            'version' => '2.0.0',
            'plugin_type' => 'page,batch',
        ])));
        $disk->put(static::TYPES . '/Plugin.php', "<?php\nnamespace {$ns};\n\nclass Plugin\n{\n}\n");
        $disk->put(static::TYPES . '/PluginPage.php', "<?php\nnamespace {$ns};\n\nclass PluginPage extends \\Exceedone\\Exment\\Services\\Plugin\\PluginPageBase\n{\n}\n");
        $disk->put(static::TYPES . '/PluginBatch.php', "<?php\nnamespace {$ns};\n\nclass PluginBatch extends \\Exceedone\\Exment\\Services\\Plugin\\PluginBatchBase\n{\n    public function execute()\n    {\n        return true;\n    }\n}\n");
        $this->plugins[static::TYPES] = $this->savePlugin(static::TYPES, '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a01', PluginType::PAGE);

        $ns = 'App\\Plugins\\' . static::THROWS;
        $disk->put(static::THROWS . '/config.json', strval(json_encode([
            'plugin_name' => static::THROWS,
            'plugin_view_name' => static::THROWS,
            'uuid' => '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a02',
            'version' => '1.0.0',
            'plugin_type' => 'page',
            // a route without its method: an error inside the route group of the plugin
            'route' => [['uri' => 'broken', 'function' => 'index']],
        ])));
        $disk->put(static::THROWS . '/Plugin.php', "<?php\nnamespace {$ns};\n\nclass Plugin extends \\Exceedone\\Exment\\Services\\Plugin\\PluginPageBase\n{\n    public function _getLoadView()\n    {\n        throw new \\RuntimeException('broken in the boot');\n    }\n}\n");
        $this->plugins[static::THROWS] = $this->savePlugin(static::THROWS, '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a02', PluginType::PAGE);

        $disk->put(static::SCRIPT . '/config.json', strval(json_encode([
            'plugin_name' => static::SCRIPT,
            'plugin_view_name' => static::SCRIPT,
            'uuid' => '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a05',
            'version' => '1.0.0',
            'plugin_type' => 'script',
        ])));
        $disk->put(static::SCRIPT . '/public/script.js', "// test\n");
        // its CDNs not a list: a warning (an ErrorException) when the scripts are put on the page
        $this->plugins[static::SCRIPT] = $this->savePlugin(static::SCRIPT, '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a05', PluginType::SCRIPT, ['cdns' => 'https://cdn.example.invalid/x.js']);

        $this->plugins[static::BAD_CRON] = $this->savePlugin(static::BAD_CRON, '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a03', PluginType::BATCH, ['batch_cron' => 'not a cron']);
        $this->plugins[static::GOOD_CRON] = $this->savePlugin(static::GOOD_CRON, '9d6f1d0e-3b8a-4c39-9a51-2f3c7f0b0a04', PluginType::BATCH, ['batch_cron' => '5 4 * * *']);

        System::clearCache();
        System::clearRequestSession();
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function savePlugin(string $name, string $uuid, string $type, array $options = []): Plugin
    {
        $plugin = new Plugin();
        $plugin->uuid = $uuid;
        $plugin->plugin_name = $name;
        $plugin->plugin_view_name = $name;
        $plugin->plugin_types = $type;
        $plugin->active_flg = true;
        $plugin->version = '1.0.0';
        $plugin->options = $options;
        $plugin->save();
        return $plugin;
    }

    /**
     * @return array<string>
     */
    protected function names(): array
    {
        return [static::TYPES, static::THROWS, static::SCRIPT, static::BAD_CRON, static::GOOD_CRON];
    }

    /**
     * @param object $object
     * @return mixed
     */
    protected function callProtected($object, string $method)
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($object);
    }
}
