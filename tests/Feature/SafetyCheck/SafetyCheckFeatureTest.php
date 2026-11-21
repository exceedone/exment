<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Artisan;

/**
 * The safety check in Feature management: off for a new site, on for a site
 * that used it; when off, its page, the answer page and the automatic sending
 * stop and its menu is hidden; the data stays.
 */
class SafetyCheckFeatureTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        config(['exment.features_locked' => '']);
        Features::reset();
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    protected function setFeature(?bool $enabled): void
    {
        $settings = Features::settings();
        unset($settings[Features::SAFETY_CHECK]);
        if (isset($enabled)) {
            $settings[Features::SAFETY_CHECK] = ['enabled' => $enabled, 'role_groups' => null, 'menu' => true];
        }
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function migrate(): void
    {
        $migration = require exment_package_path('database/migrations/2026_11_26_000001_keep_safety_check_feature.php');
        $migration->up();
        System::clearRequestSession();
        Features::reset();
    }

    public function testOffUntilTurnedOn(): void
    {
        $this->setFeature(null);
        $this->assertTrue(Features::exists(Features::SAFETY_CHECK));
        $this->assertFalse(Features::enabled(Features::SAFETY_CHECK));
        $this->assertTrue(Features::hidesMenuUri('safety_check'));

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_url('safety_check'))->assertNotFound();
        $this->post(admin_urls('safety_check', 'send'))->assertNotFound();
        $this->get(admin_urls('safety', 'answer') . '?e=1&u=1')->assertNotFound();

        $this->setFeature(true);
        $this->assertFalse(Features::hidesMenuUri('safety_check'));
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->assertSame(200, $this->get(admin_url('safety_check'))->getStatusCode());
        // the answer page: not 404 any more (the link itself is not right here)
        $this->assertNotSame(404, $this->get(admin_urls('safety', 'answer') . '?e=1&u=1')->getStatusCode());
    }

    public function testTheAutomaticSendingStopsWhenOff(): void
    {
        $this->setFeature(false);
        System::safety_check_auto_enabled(true);
        System::safety_check_last_feed_time(null);
        System::clearRequestSession();
        $this->assertSame(0, Artisan::call('exment:safetywatch'));
        // it did not read the feed (the cursor is not moved)
        System::clearRequestSession();
        $this->assertNull(System::safety_check_last_feed_time());
    }

    public function testTheDataStays(): void
    {
        $this->setFeature(true);
        $event = $this->createEvent();
        $this->setFeature(false);
        $this->assertNotNull($this->freshEvent($event->id));
    }

    public function testEverySiteThatIsThereKeepsIt(): void
    {
        // a site that is there (initialized), even one that never sent one: on
        $this->setFeature(null);
        System::initialized(true);
        System::safety_check_auto_enabled(false);
        $this->migrate();
        $this->assertTrue(Features::enabled(Features::SAFETY_CHECK));

        // a new site (the install runs the migrations before it is initialized): off
        $this->setFeature(null);
        System::initialized(false);
        $this->migrate();
        $this->assertFalse(array_key_exists(Features::SAFETY_CHECK, Features::settings()));
        System::initialized(true);

        // decided already: kept
        $this->setFeature(false);
        $this->migrate();
        $this->assertFalse(Features::enabled(Features::SAFETY_CHECK));
    }

    public function testLineSaysItIsNotInUse(): void
    {
        $this->setFeature(false);
        $this->setUpLineWebhookMock();
        $this->postWebhook(['events' => [[
            'type' => 'postback',
            'replyToken' => 'reply-1',
            'source' => ['userId' => 'U-not-in-use'],
            'postback' => ['data' => 'act=safety&e=1&st=safe'],
            'webhookEventId' => 'ev-not-in-use',
        ]]]);
        $this->assertSame(exmtrans('safety.not_in_use'), $this->lastReplyText());
    }

    public function testTheSettingsSayItIsOff(): void
    {
        $this->setFeature(false);
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_url('system') . '?advanced=1')->assertSee(exmtrans('safety.feature_off'));
        $this->setFeature(true);
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_url('system') . '?advanced=1')->assertDontSee(exmtrans('safety.feature_off'));
    }
}
