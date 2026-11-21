<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireCalledType;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The tuning advice (ISS00414).
 */
class TuningAdviceTest extends UnitTestBase
{
    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
    }

    /**
     * Every item answers OK or WARNING (never NG: the advice never blocks the install),
     * with a label, an explanation, a result text and a message when it is not OK.
     *
     * @return void
     */
    public function testEveryItemAnswers()
    {
        $list = SystemRequireList::make(SystemRequireCalledType::TUNING);
        $this->assertGreaterThanOrEqual(10, count($list->getItems()));
        $this->assertFalse($list->hasResultNg());

        foreach ($list->getItems() as $check) {
            $this->assertInstanceOf(Tuning\TuningBase::class, $check);
            $result = $check->checkResult();
            $this->assertContains($result, [SystemRequireResult::OK, SystemRequireResult::WARNING], get_class($check));
            $this->assertNotEmpty($check->getLabel());
            $this->assertStringNotContainsString('tuning.item', $check->getLabel(), 'no translation: ' . get_class($check));
            $this->assertStringNotContainsString('tuning.item', $check->getExplain(), 'no translation: ' . get_class($check));
            $this->assertNotNull($check->getResultText());
            $this->assertNotNull($check->getSettingUrl());
            if ($result != SystemRequireResult::OK) {
                $this->assertStringNotContainsString('tuning.item', (string) $check->getMessage(), 'no translation: ' . get_class($check));
            }
        }
    }

    /**
     * The requirement lists keep their items: the advice is not mixed into them.
     *
     * @return void
     */
    public function testRequirementsUnchanged()
    {
        foreach ([SystemRequireCalledType::COMMAND, SystemRequireCalledType::WEB, SystemRequireCalledType::INSTALL_WEB] as $type) {
            foreach (SystemRequireList::make($type)->getItems() as $check) {
                $this->assertNotInstanceOf(Tuning\TuningBase::class, $check);
            }
        }
    }

    /**
     * @return void
     */
    public function testAppDebug()
    {
        config(['app.debug' => true]);
        $this->assertSame(SystemRequireResult::WARNING, (new Tuning\AppDebugTuning())->checkResult());
        config(['app.debug' => false]);
        $this->assertSame(SystemRequireResult::OK, (new Tuning\AppDebugTuning())->checkResult());
    }

    /**
     * @return void
     */
    public function testQueueAndExmentCache()
    {
        config(['queue.default' => 'sync', 'exment.use_cache' => false]);
        $this->assertSame(SystemRequireResult::WARNING, (new Tuning\QueueTuning())->checkResult());
        $this->assertSame(SystemRequireResult::WARNING, (new Tuning\ExmentCacheTuning())->checkResult());
        config(['queue.default' => 'database', 'exment.use_cache' => true]);
        $this->assertSame(SystemRequireResult::OK, (new Tuning\QueueTuning())->checkResult());
        $this->assertSame(SystemRequireResult::OK, (new Tuning\ExmentCacheTuning())->checkResult());
    }

    /**
     * The performance log can be turned off from the page; a debug mode of .env shows what to write.
     *
     * @return void
     */
    public function testDebugLog()
    {
        $before = System::logging_toggle_available();
        try {
            foreach (array_keys(Tuning\DebugLogTuning::DEBUG_CONFIGS) as $key) {
                config(["exment.{$key}" => false]);
            }
            System::logging_toggle_available(false);
            $check = new Tuning\DebugLogTuning();
            $this->assertSame(SystemRequireResult::OK, $check->checkResult());
            $this->assertNull($check->getAction());

            System::logging_toggle_available(true);
            $check = new Tuning\DebugLogTuning();
            $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
            $this->assertSame(Tuning\DebugLogTuning::ACTION, $check->getAction());
            $this->assertFalse($check->isServerWide());

            System::logging_toggle_available(false);
            config(['exment.debugmode_sql' => true]);
            $check = new Tuning\DebugLogTuning();
            $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
            $this->assertNull($check->getAction());
            $this->assertStringContainsString('EXMENT_DEBUG_MODE_SQL=false', (string) $check->getFixText());
        } finally {
            config(['exment.debugmode_sql' => false]);
            System::logging_toggle_available($before);
        }
    }

    /**
     * Only the config is cached, never the routes (the routes of the plugins come from the database).
     *
     * @return void
     */
    public function testConfigCacheAction()
    {
        $check = new Tuning\ConfigCacheTuning();
        $expected = Tuning\ConfigCacheTuning::cached() ? Tuning\ConfigCacheTuning::ACTION_CLEAR : Tuning\ConfigCacheTuning::ACTION_CACHE;
        $this->assertSame($expected, $check->getAction());
        $this->assertTrue($check->isServerWide());
        $this->assertStringNotContainsString('route:cache', (string) $check->getFixText());
    }

    /**
     * Tuning is a feature of Feature management that is on until it is turned off, and a
     * server keeps it from the administrators of its sites with EXMENT_FEATURES_LOCKED=tuning
     * (a SaaS: the advice is about php.ini and .env, which the tenants cannot change; ISS00428).
     * Kept from them, its page answers "not found" and its menu is hidden.
     *
     * @return void
     */
    public function testTuningIsAFeatureOnUntilTurnedOff()
    {
        $before = System::features();
        try {
            $settings = json_decode(strval(json_encode($before ?? [])), true) ?: [];
            unset($settings[Features::TUNING]);
            System::features($settings);

            $this->assertArrayHasKey(Features::TUNING, Features::definitions());
            $this->assertSame(exmtrans('feature.features.tuning'), Features::label(Features::TUNING), 'translated when shown');
            $this->assertTrue(Features::enabled(Features::TUNING), 'on until it is turned off');
            $this->assertSame(Features::TUNING, Features::forRequest(\Illuminate\Http\Request::create(admin_url('tuning'))));
            $this->assertSame(Features::TUNING, Features::forRequest(\Illuminate\Http\Request::create(admin_url('tuning/action'), 'POST')));

            config(['exment.features_locked' => 'file_server,tuning']);
            $this->assertFalse(Features::enabled(Features::TUNING), 'kept off by the server');
            $this->assertFalse(Features::allowed(Features::TUNING));

            config(['exment.features_locked' => '']);
            Features::save([Features::TUNING => ['enabled' => false]]);
            $this->assertFalse(Features::enabled(Features::TUNING), 'turned off on the site');

            // on from the start is for Exment's own pages: a plugin asking for it starts off
            Features::register('exment_test_tuning.x', ['label' => 'x', 'endpoints' => ['plugins/exment_test_tuning'], 'default' => true]);
            $this->assertTrue(Features::exists('exment_test_tuning.x'));
            $this->assertFalse(Features::enabled('exment_test_tuning.x'));
        } finally {
            config(['exment.features_locked' => '']);
            System::features($before);
            Features::reset();
        }
    }

    /**
     * Feature management does not ask who may use Tuning: its row says the system administrators
     * (not "those who may use the plugin").
     *
     * @return void
     */
    public function testTheFeatureRowSaysTheSystemAdministrators()
    {
        $controller = new \Exceedone\Exment\Controllers\FeatureController();
        $method = new \ReflectionMethod($controller, 'featureRow');
        $method->setAccessible(true);
        $row = $method->invoke($controller, Features::TUNING, Features::definitions()[Features::TUNING]);
        $this->assertFalse($row['who']);
        $this->assertSame(exmtrans('feature.who_system_admin'), $row['who_text']);
        $this->assertNotSame(exmtrans('feature.who_plugin'), $row['who_text']);
    }

    /**
     * The page says that the server settings are for the server administrator, the advice is
     * not drawn as an error, and a form is not put in a p (the browser closes the p before it).
     *
     * @return void
     */
    public function testThePageMarkup()
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/tuning/index.blade.php');
        $this->assertStringContainsString("exmtrans('tuning.server_admin')", $view);
        $this->assertStringNotContainsString('class="red"', $view);
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*>\s*@if\(\$canRun\)/', $view);

        $this->assertStringNotContainsString('tuning.', exmtrans('tuning.server_admin'));
        $this->assertNotSame(exmtrans('tuning.result_ok'), exmtrans('tuning.result_recommended'));
    }

    /**
     * Writing .env removes a config cache, so the new values are read.
     *
     * @return void
     */
    public function testEnvWriteForgetsConfigCache()
    {
        // never the real cache of the site: a file of the test, through APP_CONFIG_CACHE
        $path = sys_get_temp_dir() . '/exment_tuning_test_config_' . getmypid() . '.php';
        $before = $_SERVER['APP_CONFIG_CACHE'] ?? null;
        $_SERVER['APP_CONFIG_CACHE'] = $path;
        try {
            $this->assertSame($path, app()->getCachedConfigPath());
            file_put_contents($path, '<?php return [];');
            $this->assertTrue(Tuning\ConfigCacheTuning::cached());
            EnvService::forgetCachedConfig();
            $this->assertFalse(is_file($path));
            $this->assertFalse(Tuning\ConfigCacheTuning::cached());
        } finally {
            if (is_null($before)) {
                unset($_SERVER['APP_CONFIG_CACHE']);
            } else {
                $_SERVER['APP_CONFIG_CACHE'] = $before;
            }
            @unlink($path);
        }
    }
}
