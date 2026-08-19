<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Services\SafetyCheck\P2pQuakeFeed;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Http;

class P2pQuakeFeedTest extends FeatureTestBase
{
    use TestTrait;

    public function testFetchRecentNormalizesAndSkipsBroken()
    {
        Http::fake(['api.p2pquake.net/*' => Http::response(
            file_get_contents(__DIR__ . '/fixtures/p2p_551.json'),
            200
        )]);

        $items = (new P2pQuakeFeed())->fetchRecent();

        $this->assertCount(2, $items); // item hỏng bị bỏ
        $this->assertEquals('千葉県北西部', $items[0]['hypocenter'] ?: $items[1]['hypocenter']);
        $first = $items[0];
        $this->assertInstanceOf(\Carbon\Carbon::class, $first['time']);
        $this->assertLessThanOrEqual($items[1]['time'], $first['time']); // cũ -> mới
        $this->assertEquals(45, collect($items)->max('max_scale'));
        $prefs = collect($items[array_key_last($items)]['points'])->pluck('pref');
        $this->assertTrue($prefs->contains('千葉県') || $prefs->contains('大阪府'));
    }

    public function testNetworkErrorReturnsEmpty()
    {
        Http::fake(['api.p2pquake.net/*' => Http::response('error', 500)]);
        $this->assertEquals([], (new P2pQuakeFeed())->fetchRecent());
    }

    /** The endpoint comes from config (exment.safety_check.feed_url), not a hardcoded URL. */
    public function testFeedUrlConfigOverride()
    {
        config(['exment.safety_check.feed_url' => 'https://feed.example.test/history']);
        Http::fake(['feed.example.test/*' => Http::response(
            file_get_contents(__DIR__ . '/fixtures/p2p_551.json'),
            200
        )]);

        $items = (new P2pQuakeFeed())->fetchRecent();

        $this->assertCount(2, $items);
        Http::assertSent(function ($request) {
            return \Illuminate\Support\Str::startsWith($request->url(), 'https://feed.example.test/history');
        });
    }

    /**
     * P2PQuake reports times in JST with no offset in the string. They must be parsed
     * as Asia/Tokyo regardless of APP_TIMEZONE — parsing them in the app timezone
     * shifts every bulletin by the JST-vs-app offset, which can make the watcher's
     * max_bulletin_age guard silently discard every qualifying bulletin (e.g. app in
     * UTC: JST times look 9 hours old).
     */
    public function testFeedTimesAreParsedAsJst()
    {
        Http::fake(['api.p2pquake.net/*' => Http::response(json_encode([[
            'id' => 'jst-test-1',
            'code' => 551,
            'time' => '2026/08/17 10:00:30.123',
            'earthquake' => [
                'time' => '2026/08/17 10:00:00',
                'maxScale' => 50,
                'hypocenter' => ['name' => '千葉県北西部'],
            ],
            'points' => [['pref' => '千葉県', 'scale' => 50]],
        ]]), 200)]);

        $items = (new P2pQuakeFeed())->fetchRecent();

        $this->assertCount(1, $items);
        // JST 10:00 == UTC 01:00 — the instant must be right, whatever tz the app runs in
        $this->assertEquals(
            '2026-08-17 01:00:00',
            $items[0]['time']->copy()->utc()->format('Y-m-d H:i:s')
        );
        // and the returned Carbon is converted to the app timezone, so downstream
        // format('Y-m-d H:i:s') strings (cursor, event title) stay app-tz consistent
        $this->assertEquals(config('app.timezone'), $items[0]['time']->timezone->getName());
    }

    /**
     * `received_at` is normalized from the TOP-LEVEL `time` field (when the P2P server
     * received the bulletin, with milliseconds) — NOT from `earthquake.time` (when the
     * quake occurred). A detail/correction bulletin that upgrades the max scale shares
     * `earthquake.time` with the first prompt report, so the watcher's cursor must be
     * able to tell the two bulletins apart by their receive time.
     */
    public function testReceivedAtComesFromTopLevelTimeWithMilliseconds()
    {
        Http::fake(['api.p2pquake.net/*' => Http::response(json_encode([[
            'id' => 'recv-test-1',
            'code' => 551,
            'time' => '2026/08/17 10:02:30.550',
            'earthquake' => [
                'time' => '2026/08/17 10:00:00',
                'maxScale' => 50,
                'hypocenter' => ['name' => '千葉県北西部'],
            ],
            'points' => [['pref' => '千葉県', 'scale' => 50]],
        ]]), 200)]);

        $items = (new P2pQuakeFeed())->fetchRecent();

        $this->assertCount(1, $items);
        $this->assertInstanceOf(\Carbon\Carbon::class, $items[0]['received_at'] ?? null);
        // JST 10:02:30.550 == UTC 01:02:30.550 — milliseconds preserved
        $this->assertEquals(
            '2026-08-17 01:02:30.550',
            $items[0]['received_at']->copy()->utc()->format('Y-m-d H:i:s.v')
        );
        // occurred time stays on `time` (from earthquake.time)
        $this->assertEquals(
            '2026-08-17 01:00:00',
            $items[0]['time']->copy()->utc()->format('Y-m-d H:i:s')
        );
    }
}
