<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\EarthquakeFeedInterface;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Task 5 - SafetyWatchCommand (`exment:safetywatch`): polls the earthquake feed
 * (EarthquakeFeedInterface) every minute and, comparing the bulletin's NATIONWIDE
 * max scale against the configured threshold only (no prefecture filtering),
 * auto-creates a `jma_auto` safety_check_event + dispatches the send. Dedupes by
 * `jma_event_id`, suppresses re-trigger within a cooldown window, and always
 * advances the feed cursor (`safety_check_last_feed_time`), even for skipped items.
 */
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

    /** Bind a fake feed returning the given normalized items; returns the fake so tests can inspect it. */
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
        // received_at (bulletin receive time, the cursor field) defaults to the
        // occurred time unless a test needs them to differ
        $item['received_at'] = $item['received_at'] ?? $item['time']->copy();
        return $item;
    }

    /** All safety_check_event rows, via a fresh query (no per-request cache). */
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

        // cursor advances to the processed item's time
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

        // cursor still advances even though the item was skipped
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

    /**
     * P2PQuake sends several bulletins per earthquake, every one with its OWN id,
     * so the jma_event_id dedupe cannot catch them. The cooldown must: a correction
     * bulletin sharing the quake's occurred time with an already-triggered event
     * is suppressed, instead of blasting every user a second time.
     */
    public function testCorrectionBulletinForSameQuakeIsSuppressedByCooldown()
    {
        Log::spy();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $quakeTime = Carbon::now()->subMinutes(10)->startOfSecond();
        $prompt = $this->feedItem([
            'id' => 'eq-cd-prompt',
            'max_scale' => 50,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(9),
        ]);
        $this->bindFeed([$prompt]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(1, $this->eventRows()->count());

        // correction: same earthquake.time, new bulletin id
        $correction = $this->feedItem([
            'id' => 'eq-cd-correction',
            'max_scale' => 55,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(8),
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

    /**
     * The cooldown throttles bulletins of the SAME earthquake only (that is what
     * the setting's help text promises). A DISTINCT quake — e.g. a bigger
     * mainshock minutes after a foreshock already triggered an event — must
     * still trigger its own safety check: suppressing it would silently drop
     * the notification exactly when it matters most.
     */
    public function testDistinctQuakeWithinCooldownStillTriggers()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $foreshock = $this->feedItem([
            'id' => 'eq-foreshock',
            'max_scale' => 50,
            'time' => Carbon::now()->subMinutes(10)->startOfSecond(),
            'received_at' => Carbon::now()->subMinutes(9),
        ]);
        $this->bindFeed([$foreshock]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(1, $this->eventRows()->count());

        // a DIFFERENT earthquake (own occurred time), inside the 60-min cooldown window
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

    /**
     * A stale bulletin (older than the safety_check_max_bulletin_age_minutes setting,
     * default 30) must be skipped even though its scale qualifies, so that a first run
     * (null cursor) or a re-enable after the watcher was off for a while does not blast
     * every user with a days-old quake. The cursor still advances to the stale item's
     * time, exactly like the other skip branches (below-threshold, duplicate, cooldown).
     */
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

    /**
     * The staleness cutoff is a system setting, not a hardcoded 30 minutes: with
     * safety_check_max_bulletin_age_minutes raised to 180, a 2-hour-old qualifying
     * bulletin (skipped as stale under the default) MUST create an event.
     */
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

    /**
     * P2PQuake sends several bulletins per earthquake (prompt report -> detail ->
     * correction) that all share `earthquake.time`. A correction that upgrades the
     * max scale above the threshold MUST still trigger, so the cursor has to run on
     * the bulletin's receive time (`received_at`), not the quake's occurred time.
     */
    public function testUpgradeBulletinSharingQuakeTimeStillTriggers()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        // whole-second like real feed-parsed times ('Y/m/d H:i:s' has no microseconds) —
        // otherwise stray microseconds sneak past the cursor's lte() and mask the bug
        $quakeTime = Carbon::now()->subMinutes(10)->startOfSecond();
        $prompt = $this->feedItem([
            'id' => 'eq1-prompt',
            'max_scale' => 40, // below threshold 45
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(9),
        ]);
        $this->bindFeed([$prompt]);
        \Artisan::call('exment:safetywatch');
        $this->assertEquals(0, $this->eventRows()->count(), 'The prompt report is below threshold.');

        // correction arrives later: same earthquake.time, higher scale, new id
        $detail = $this->feedItem([
            'id' => 'eq1-detail',
            'max_scale' => 50,
            'time' => $quakeTime->copy(),
            'received_at' => Carbon::now()->subMinutes(8),
        ]);
        $this->bindFeed([$prompt, $detail]);
        \Artisan::call('exment:safetywatch');

        $rows = $this->eventRows();
        $this->assertEquals(1, $rows->count(), 'The upgraded correction bulletin must trigger even though it shares earthquake.time with the already-consumed prompt report.');
        $this->assertEquals('eq1-detail', array_get($rows->first()->value, 'jma_event_id'));
        Bus::assertDispatchedTimes(LineSendJob::class, 1);
    }

    /**
     * When creating the event row itself fails, NOTHING durable exists (no event, no
     * answer rows, no dedupe key), so the bulletin must NOT be consumed: the cursor
     * stays put and the next poll retries it. (A permanently failing bulletin
     * self-heals via max_bulletin_age: it goes stale and is then skipped normally.)
     */
    public function testEventSaveFailureDoesNotAdvanceCursorSoBulletinIsRetried()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $item = $this->feedItem(['id' => 'quake-savefail', 'max_scale' => 50]);
        $this->bindFeed([$item]);

        // inject a save failure on the event model (DB error stand-in)
        getModelName('safety_check_event')::saving(function () {
            throw new \RuntimeException('forced event save failure');
        });

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count());
        Bus::assertNotDispatched(LineSendJob::class);
        $this->assertNull(System::safety_check_last_feed_time(), 'The cursor must not advance past a bulletin whose event row was never saved.');
    }

    /**
     * With exment.use_cache enabled, saving the cursor must NOT Cache::flush() the
     * whole store: that would also evict every unrelated cache entry — including the
     * schedule's withoutOverlapping mutex, silently re-enabling overlapping runs.
     */
    public function testWatcherDoesNotFlushWholeCacheStore()
    {
        config(['exment.use_cache' => true]);
        \Cache::put('unrelated-sentinel', 'keep-me', 300);

        $item = $this->feedItem(['id' => 'quake-cache', 'max_scale' => 50]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals('keep-me', \Cache::get('unrelated-sentinel'), 'The watcher must clear only its own keys, not flush the whole cache store.');
        // and the cursor still reads back fresh (its own cache keys were cleared)
        $this->assertNotNull(System::safety_check_last_feed_time());
    }

    /**
     * The every-minute schedule entry must carry withoutOverlapping(): a slow poll
     * (LINE outage, many users on the sync queue) otherwise overlaps the next run,
     * and the jma_event_id dedupe is check-then-insert with no unique constraint.
     */
    public function testScheduleRegistersSafetywatchWithoutOverlapping()
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $event = collect($schedule->events())->first(function ($e) {
            return str_contains((string) $e->command, 'exment:safetywatch');
        });

        $this->assertNotNull($event, 'exment:safetywatch must be scheduled.');
        $this->assertTrue($event->withoutOverlapping, 'exment:safetywatch must use withoutOverlapping().');
    }

    /**
     * A numeric setting emptied in the admin UI is stored as 0. For settings where 0
     * is meaningless it must fall back to the Define default instead of breaking the
     * watcher: max_bulletin_age=0 would mark EVERY bulletin stale (watcher silently
     * dead), min_scale=0 would trigger on every tiny quake, cooldown=0 would remove
     * the re-trigger suppression.
     */
    public function testEmptyMaxBulletinAgeFallsBackToDefault()
    {
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        System::safety_check_max_bulletin_age_minutes(0); // emptied in UI -> stored 0
        System::clearCache();

        // 5 minutes old: inside the default 30-minute window
        $item = $this->feedItem(['id' => 'quake-age-default', 'max_scale' => 50, 'time' => Carbon::now()->subMinutes(5)]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count(), 'max_bulletin_age=0 must fall back to the default, not mark every bulletin stale.');
    }

    public function testEmptyMinScaleFallsBackToDefault()
    {
        System::safety_check_min_scale(0); // emptied in UI -> stored 0
        System::clearCache();

        // scale 40 is below the DEFAULT threshold 45 -> must not trigger
        $item = $this->feedItem(['id' => 'quake-minscale-default', 'max_scale' => 40]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(0, $this->eventRows()->count(), 'min_scale=0 must fall back to the default, not trigger on every quake.');
    }

    public function testEmptyCooldownFallsBackToDefault()
    {
        System::safety_check_cooldown_minutes(0); // emptied in UI -> stored 0
        System::clearCache();

        // an already-triggered event for the SAME earthquake (shared occurred time)
        $quakeTime = Carbon::now()->subMinutes(11)->startOfSecond();
        $this->createExistingEvent([
            'jma_event_id' => 'previous-quake-cd',
            'triggered_at' => Carbon::now()->subMinutes(10)->format('Y-m-d H:i:s'),
            'quake_time'   => $quakeTime->format('Y-m-d H:i:s'),
        ]);

        $item = $this->feedItem(['id' => 'new-quake-cd', 'max_scale' => 55, 'time' => $quakeTime->copy()]);
        $this->bindFeed([$item]);

        \Artisan::call('exment:safetywatch');

        $this->assertEquals(1, $this->eventRows()->count(), 'cooldown=0 must fall back to the default 60 minutes and suppress the re-trigger.');
        Bus::assertNotDispatched(LineSendJob::class);
    }

    /** The feed fetch size comes from config (exment.safety_check.feed_limit), not a hardcoded 10. */
    public function testFeedLimitConfigPassedToFeed()
    {
        config(['exment.safety_check.feed_limit' => 25]);

        $feed = $this->bindFeed([]);

        \Artisan::call('exment:safetywatch');

        $this->assertTrue($feed->called);
        $this->assertEquals(25, $feed->receivedLimit);
    }

    /**
     * P2PQuake rejects limit > 100 with HTTP 400 — a misconfigured
     * EXMENT_SAFETY_CHECK_FEED_LIMIT would silently kill the whole feed.
     * The watcher must clamp the value to 100.
     */
    public function testFeedLimitClampedTo100()
    {
        config(['exment.safety_check.feed_limit' => 250]);

        $feed = $this->bindFeed([]);

        \Artisan::call('exment:safetywatch');

        $this->assertTrue($feed->called);
        $this->assertEquals(100, $feed->receivedLimit);
    }

    /**
     * A half-installed environment (migration marked run while the LINE template
     * was not imported, so ensureAll() no-oped and the tables are absent) must not
     * fatal the every-minute watcher on a null table — log an error and return,
     * so the operator has something to find instead of a crash-loop.
     */
    public function testMissingSafetyTablesLogsErrorInsteadOfFataling()
    {
        Log::spy();

        // Make CustomTable::getEloquent('safety_check_event') return null without any
        // DDL: rename the metadata row (rolled back by the test transaction).
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

/**
 * Test double for EarthquakeFeedInterface: returns preset normalized items and
 * records whether fetchRecent() was called (used to prove the watcher short-circuits
 * before touching the feed when auto-trigger is disabled).
 */
class FakeEarthquakeFeed implements EarthquakeFeedInterface
{
    /** @var array */
    public $items = [];

    /** @var bool */
    public $called = false;

    /** @var int|null The $limit the watcher actually passed in. */
    public $receivedLimit = null;

    public function fetchRecent(int $limit = 10): array
    {
        $this->called = true;
        $this->receivedLimit = $limit;
        return $this->items;
    }
}
