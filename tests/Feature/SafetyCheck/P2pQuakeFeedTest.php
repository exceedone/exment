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

        $this->assertCount(2, $items);
        $this->assertEquals('千葉県北西部', $items[0]['hypocenter'] ?: $items[1]['hypocenter']);
        $first = $items[0];
        $this->assertInstanceOf(\Carbon\Carbon::class, $first['time']);
        $this->assertLessThanOrEqual($items[1]['time'], $first['time']);
        $this->assertEquals(45, collect($items)->max('max_scale'));
        $prefs = collect($items[array_key_last($items)]['points'])->pluck('pref');
        $this->assertTrue($prefs->contains('千葉県') || $prefs->contains('大阪府'));
    }

    public function testNetworkErrorReturnsEmpty()
    {
        Http::fake(['api.p2pquake.net/*' => Http::response('error', 500)]);
        $this->assertEquals([], (new P2pQuakeFeed())->fetchRecent());
    }

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
        $this->assertEquals(
            '2026-08-17 01:00:00',
            $items[0]['time']->copy()->utc()->format('Y-m-d H:i:s')
        );
        $this->assertEquals(config('app.timezone'), $items[0]['time']->timezone->getName());
    }

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
        $this->assertEquals(
            '2026-08-17 01:02:30.550',
            $items[0]['received_at']->copy()->utc()->format('Y-m-d H:i:s.v')
        );
        $this->assertEquals(
            '2026-08-17 01:00:00',
            $items[0]['time']->copy()->utc()->format('Y-m-d H:i:s')
        );
    }
}
