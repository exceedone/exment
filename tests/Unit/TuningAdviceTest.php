<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireCalledType;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The tuning advice (ISS00414).
 */
class TuningAdviceTest extends UnitTestBase
{
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
