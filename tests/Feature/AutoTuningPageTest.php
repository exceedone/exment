<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The settings Exment can make by itself on the tuning page: the choices, the button, and
 * what was done last. .env and the artisan commands are recorded, not run.
 */
class AutoTuningPageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private $commands = [];

    /** @var string */
    private $resultPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->resultPath = sys_get_temp_dir() . '/exment_auto_tuning_page_' . uniqid('', true) . '.json';
        AutoTuning::$resultPath = $this->resultPath;
        AutoTuning::$envWriter = function (array $values) {
        };
        AutoTuning::$artisan = function (string $command) {
            $this->commands[] = $command;
        };
        AutoTuning::$redisAnswers = fn () => true;
        config(['exment.features_locked' => '', 'exment.tuning_server_actions' => true, 'cache.default' => 'file', 'session.driver' => 'file']);
    }

    protected function tearDown(): void
    {
        @unlink($this->resultPath);
        AutoTuning::$resultPath = null;
        AutoTuning::$envWriter = null;
        AutoTuning::$artisan = null;
        AutoTuning::$redisAnswers = null;
        parent::tearDown();
    }

    public function testTheChoicesTheButtonAndTheLastResult(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('id="tuning-auto"', $content);
        $this->assertStringContainsString('name="auto_tuning[]" value="cache_driver" checked', $content);
        $this->assertStringContainsString('name="auto_tuning[]" value="session_driver" checked', $content);
        $this->assertStringContainsString('name="auto_tuning[]" value="config_cache" checked', $content);

        $this->post(admin_url('tuning/action'), ['action' => 'auto', 'auto_tuning' => ['cache_driver', 'config_cache']])
            ->assertRedirect(admin_url('tuning'));
        $this->assertContains('config:cache', $this->commands);

        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('data-auto-tuning="cache_driver" data-status="done"', $content);
        $this->assertStringContainsString('data-auto-tuning="config_cache" data-status="done"', $content);
        $this->assertStringNotContainsString('data-auto-tuning="session_driver"', $content, 'not chosen: not done');
    }

    public function testTheSetupDoesNotOfferTheSession(): void
    {
        $items = (new AutoTuning(true))->available();
        $this->assertArrayHasKey('cache_driver', $items);
        $this->assertArrayNotHasKey('session_driver', $items);
    }
}
