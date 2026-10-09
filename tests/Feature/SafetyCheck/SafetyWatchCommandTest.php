<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\EarthquakeFeedInterface;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SafetyWatchCommandTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        Bus::fake([LineSendJob::class]);

        System::safety_check_auto_enabled(true);
        System::safety_check_min_scale(45);
        System::safety_check_cooldown_minutes(60);
        System::clearCache();
    }

    protected function bindFeed(array $items = []): FakeEarthquakeFeed
    {
        $feed = new FakeEarthquakeFeed();
        $feed->items = $items;
        $this->app->singleton(EarthquakeFeedInterface::class, function () use ($feed) {
            return $feed;
        });
        return $feed;
    }

    protected function feedItem(array $overrides = []): array
    {
        $item = array_merge([
            'id' => 'quake-' . uniqid(),
            'time' => Carbon::now()->subMinute(),
            'hypocenter' => 'Test Hypocenter',
            'max_scale' => 50,
            'points' => [['pref' => 'Tokyo', 'scale' => 50]],
        ], $overrides);
        $item['received_at'] = $item['received_at'] ?? $item['time']->copy();
        return $item;
    }

    protected function eventRows()
    {
        $eventTable = CustomTable::getEloquent('safety_check_event');
        return $eventTable->getValueQuery()->get();
    }

    protected function createExistingEvent(array $overrides = [])
    {
        $value = array_merge([
            'title' => 'Existing event',
            'trigger_type' => 'jma_auto',
            'event_status' => 'open',
            'triggered_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ], $overrides);

        $event = CustomTable::getEloquent('safety_check_event')->getValueModel();
        $event->setValue($value)->save();
        return $event;
    }

    public function testQuakeInfoShowsIntensityLabelNotRawScaleCode()
    {
        $this->bindFeed([$this->feedItem(['id' => 'quake-label', 'max_scale' => 50])]);

        \Artisan::call('exment:safetywatch');

        $quakeInfo = (string) array_get($this->eventRows()->first()->value, 'quake_info');
        $this->assertStringContainsString(SafetyCheckDefine::scaleLabel(50), $quakeInfo);
        $this->assertStringNotContainsString('max scale', $quakeInfo);
        $this->assertStringNotContainsString('/ 50 /', $quakeInfo);
    }

    public function testScaleAboveThresholdCreatesEventAndDispatches()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $itemTime = Carbon::now()->subMinute();
        $item = $this->feedItem(['id' => 'quake-above', 'max_scale' => 50, 'time' => $itemTime]);
        $this->bindFeed([$item]);

        $this->assertNull(System::safety_check_last_feed_time());

        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(1, $rows->count());
        $row = $rows->first();
        $this->assertEquals('jma_auto', array_get($row->value, 'trigger_type'));
        $this->assertEquals('open', array_get($row->value, 'event_status'));
        $this->assertEquals('quake-above', array_get($row->value, 'jma_event_id'));

        Bus::assertDispatchedTimes(LineSendJob::class, 1);

        $lastFeedTime = System::safety_check_last_feed_time();
        $this->assertNotNull($lastFeedTime);
        $this->assertEquals($itemTime->format('Y-m-d H:i:s'), $lastFeedTime->format('Y-m-d H:i:s'));
    }

    public function testScaleBelowThresholdCreatesNoEvent()
    {
        $itemTime = Carbon::now()->subMinute();
        $item = $this->feedItem(['id' => 'quake-below', 'max_scale' => 40, 'time' => $itemTime]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        Bus::assertNotDispatched(LineSendJob::class);

        $lastFeedTime = System::safety_check_last_feed_time();
        $this->assertNotNull($lastFeedTime);
        $this->assertEquals($itemTime->format('Y-m-d H:i:s'), $lastFeedTime->format('Y-m-d H:i:s'));
    }

    public function testDuplicateJmaEventIdCreatesNoNewEvent()
    {
        $this->createExistingEvent([
            'jma_event_id' => 'dup-1',
            'triggered_at' => Carbon::now()->subHours(2)->format('Y-m-d H:i:s'),
        ]);

        $item = $this->feedItem(['id' => 'dup-1', 'max_scale' => 55, 'time' => Carbon::now()->subMinute()]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count());
        Bus::assertNotDispatched(LineSendJob::class);
    }

    public function testCorrectionBulletinForSameQuakeIsSuppressedByCooldown()
    {
        Log::spy();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $quakeTime = Carbon::now()->subMinutes(10)->startOfSecond();
        $prompt = $this->feedItem([
            'id' => 'eq-cd-prompt',
            'max_scale' => 50,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(4),
        ]);
        $this->bindFeed([$prompt]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(1, $this->eventRows()->count());

        $correction = $this->feedItem([
            'id' => 'eq-cd-correction',
            'max_scale' => 55,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(2),
        ]);
        $this->bindFeed([$prompt, $correction]);
        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count(), 'A correction bulletin for an already-triggered quake must not create a second event.');
        Bus::assertDispatchedTimes(LineSendJob::class, 1);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context = []) {
                return $message === 'safety check suppressed by cooldown'
                    && array_get($context, 'jma_event_id') === 'eq-cd-correction';
            });
    }

    public function testDistinctQuakeWithinCooldownStillTriggers()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $foreshock = $this->feedItem([
            'id' => 'eq-foreshock',
            'max_scale' => 50,
            'time' => Carbon::now()->subMinutes(10)->startOfSecond(),
            'received_at' => Carbon::now()->subMinutes(4),
        ]);
        $this->bindFeed([$foreshock]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(1, $this->eventRows()->count());

        $mainshock = $this->feedItem([
            'id' => 'eq-mainshock',
            'max_scale' => 60,
            'time' => Carbon::now()->subMinutes(2)->startOfSecond(),
            'received_at' => Carbon::now()->subMinutes(2),
        ]);
        $this->bindFeed([$foreshock, $mainshock]);
        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(2, $rows->count(), 'A distinct larger quake within the cooldown window must still trigger its own event.');
        $this->assertEquals('eq-mainshock', array_get($rows->last()->value, 'jma_event_id'));
        Bus::assertDispatchedTimes(LineSendJob::class, 2);
    }

    public function testStaleBulletinCreatesNoEventButAdvancesCursor()
    {
        $itemTime = Carbon::now()->subHours(2);
        $item = $this->feedItem(['id' => 'quake-stale', 'max_scale' => 60, 'time' => $itemTime]);
        $this->bindFeed([$item]);

        $this->assertNull(System::safety_check_last_feed_time());

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        Bus::assertNotDispatched(LineSendJob::class);

        $lastFeedTime = System::safety_check_last_feed_time();
        $this->assertNotNull($lastFeedTime);
        $this->assertEquals($itemTime->format('Y-m-d H:i:s'), $lastFeedTime->format('Y-m-d H:i:s'));
    }

    public function testBulletinAgeSettingControlsStaleness()
    {
        System::safety_check_max_bulletin_age_minutes(180);
        System::clearCache();

        $itemTime = Carbon::now()->subHours(2);
        $item = $this->feedItem(['id' => 'quake-old-but-allowed', 'max_scale' => 60, 'time' => $itemTime]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(1, $rows->count());
        $this->assertEquals('quake-old-but-allowed', array_get($rows->first()->value, 'jma_event_id'));
    }

    public function testUpgradeBulletinSharingQuakeTimeStillTriggers()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $quakeTime = Carbon::now()->subMinutes(10)->startOfSecond();
        $prompt = $this->feedItem([
            'id' => 'eq1-prompt',
            'max_scale' => 40,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(4),
        ]);
        $this->bindFeed([$prompt]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(0, $this->eventRows()->count(), 'The prompt report is below threshold.');

        $detail = $this->feedItem([
            'id' => 'eq1-detail',
            'max_scale' => 50,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(2),
        ]);
        $this->bindFeed([$prompt, $detail]);
        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(1, $rows->count(), 'The upgraded correction bulletin must trigger even though it shares earthquake.time with the already-consumed prompt report.');
        $this->assertEquals('eq1-detail', array_get($rows->first()->value, 'jma_event_id'));
        Bus::assertDispatchedTimes(LineSendJob::class, 1);
    }

    public function testPromptBulletinWithoutHypocenterUsesPendingPlaceholder()
    {
        $this->bindFeed([$this->feedItem(['id' => 'eq-nohypo', 'hypocenter' => '', 'max_scale' => 50])]);

        \Artisan::call('exment:safetywatch');

        $event = $this->eventRows()->first();
        $this->assertNotNull($event);
        $placeholder = exmtrans('safety.hypocenter_pending');
        $this->assertStringContainsString($placeholder, (string) array_get($event->value, 'title'));
        $this->assertStringContainsString($placeholder, (string) array_get($event->value, 'quake_info'));
        $this->assertStringNotContainsString(' ）', (string) array_get($event->value, 'title'));
    }

    public function testDetailBulletinBackfillsHypocenterWithoutResending()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $quakeTime = Carbon::now()->subMinutes(10)->startOfSecond();
        $prompt = $this->feedItem([
            'id' => 'eq-bf-prompt', 'hypocenter' => '', 'max_scale' => 50,
            'time' => $quakeTime->copy(), 'received_at' => Carbon::now()->subMinutes(4),
        ]);
        $this->bindFeed([$prompt]);
        \Artisan::call('exment:safetywatch');

        $detail = $this->feedItem([
            'id' => 'eq-bf-detail', 'hypocenter' => '熊本県熊本地方', 'max_scale' => 50,
            'time' => $quakeTime->copy(), 'received_at' => Carbon::now()->subMinutes(2),
        ]);
        $this->bindFeed([$prompt, $detail]);
        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(1, $rows->count());
        Bus::assertDispatchedTimes(LineSendJob::class, 1);
        $event = $rows->first();
        $this->assertStringContainsString('熊本県熊本地方', (string) array_get($event->value, 'quake_info'));
        $this->assertStringContainsString('熊本県熊本地方', (string) array_get($event->value, 'title'));
        $this->assertStringNotContainsString(exmtrans('safety.hypocenter_pending'), (string) array_get($event->value, 'quake_info'));
    }

    public function testStaleQualifyingBulletinLogsWarning()
    {
        Log::spy();
        $this->bindFeed([
            $this->feedItem(['id' => 'stale-big', 'max_scale' => 60, 'time' => Carbon::now()->subHours(2)]),
            $this->feedItem(['id' => 'stale-small', 'max_scale' => 10, 'time' => Carbon::now()->subHours(2)]),
        ]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                return $message === 'safety check stale bulletin skipped'
                    && array_get($context, 'jma_event_id') === 'stale-big';
            });
    }

    public function testEventSaveFailureDoesNotAdvanceCursorSoBulletinIsRetried()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $item = $this->feedItem(['id' => 'quake-savefail', 'max_scale' => 50]);
        $this->bindFeed([$item]);

        getModelName('safety_check_event')::saving(function () {
            throw new \RuntimeException('forced event save failure');
        });

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        Bus::assertNotDispatched(LineSendJob::class);
        $this->assertNull(System::safety_check_last_feed_time(), 'The cursor must not advance past a bulletin whose event row was never saved.');
    }

    public function testWatcherDoesNotFlushWholeCacheStore()
    {
        config(['exment.use_cache' => true]);
        \Cache::put('unrelated-sentinel', 'keep-me', 300);

        $item = $this->feedItem(['id' => 'quake-cache', 'max_scale' => 50]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals('keep-me', \Cache::get('unrelated-sentinel'), 'The watcher must clear only its own keys, not flush the whole cache store.');
        $this->assertNotNull(System::safety_check_last_feed_time());
    }

    public function testScheduleRegistersSafetywatchWithoutOverlapping()
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $event = collect($schedule->events())->first(function ($e) {
            return str_contains((string) $e->command, 'exment:safetywatch');
        });

        $this->assertNotNull($event, 'exment:safetywatch must be scheduled.');
        $this->assertTrue($event->withoutOverlapping, 'exment:safetywatch must use withoutOverlapping().');
    }

    public function testEmptyMaxBulletinAgeFallsBackToDefault()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        System::safety_check_max_bulletin_age_minutes(0);
        System::clearCache();

        $item = $this->feedItem(['id' => 'quake-age-default', 'max_scale' => 50, 'time' => Carbon::now()->subMinutes(5)]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count(), 'max_bulletin_age=0 must fall back to the default, not mark every bulletin stale.');
    }

    public function testEmptyMinScaleFallsBackToDefault()
    {
        System::safety_check_min_scale(0);
        System::clearCache();

        $item = $this->feedItem(['id' => 'quake-minscale-default', 'max_scale' => 40]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count(), 'min_scale=0 must fall back to the default, not trigger on every quake.');
    }

    public function testEmptyCooldownFallsBackToDefault()
    {
        System::safety_check_cooldown_minutes(0);
        System::clearCache();

        $quakeTime = Carbon::now()->subMinutes(5)->startOfSecond();
        $this->createExistingEvent([
            'jma_event_id' => 'previous-quake-cd',
            'triggered_at' => Carbon::now()->subMinutes(4)->format('Y-m-d H:i:s'),
            'quake_time'   => $quakeTime->format('Y-m-d H:i:s'),
        ]);

        $item = $this->feedItem(['id' => 'new-quake-cd', 'max_scale' => 55, 'time' => $quakeTime->copy()]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count(), 'cooldown=0 must fall back to the default 60 minutes and suppress the re-trigger.');
        Bus::assertNotDispatched(LineSendJob::class);
    }

    public function testFeedLimitConfigPassedToFeed()
    {
        config(['exment.safety_check.feed_limit' => 25]);

        $feed = $this->bindFeed([]);

        \Artisan::call('exment:safetywatch');

        $this->assertTrue($feed->called);
        $this->assertEquals(25, $feed->receivedLimit);
    }

    public function testFeedLimitClampedTo100()
    {
        config(['exment.safety_check.feed_limit' => 250]);

        $feed = $this->bindFeed([]);

        \Artisan::call('exment:safetywatch');

        $this->assertTrue($feed->called);
        $this->assertEquals(100, $feed->receivedLimit);
    }

    public function testMissingSafetyTablesLogsErrorInsteadOfFataling()
    {
        Log::spy();

        \DB::table('custom_tables')->where('table_name', 'safety_check_event')
            ->update(['table_name' => 'zz_hidden_safety_check_event']);
        System::clearCache();

        $item = $this->feedItem(['id' => 'quake-no-tables', 'max_scale' => 60]);
        $this->bindFeed([$item]);

        $exitCode = \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $exitCode);
        Bus::assertNotDispatched(LineSendJob::class);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'safety check tables are not installed');
            });
    }

    public function testAutoDisabledSkipsFeedEntirely()
    {
        System::safety_check_auto_enabled(false);
        System::clearCache();

        $item = $this->feedItem(['max_scale' => 60]);
        $feed = $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        $this->assertFalse($feed->called);
        Bus::assertNotDispatched(LineSendJob::class);
    }
}

class FakeEarthquakeFeed implements EarthquakeFeedInterface
{
    /** @var array */
    public $items = [];

    /** @var bool */
    public $called = false;

    /** @var int|null */
    public $receivedLimit = null;

    public function fetchRecent(int $limit = 10): array
    {
        $this->called = true;
        $this->receivedLimit = $limit;
        return $this->items;
    }
}
