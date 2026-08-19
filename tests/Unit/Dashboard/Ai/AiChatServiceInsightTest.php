<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Ai;

use ReflectionMethod;
use ReflectionProperty;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Services\AiChatService;
use Exceedone\Exment\Controllers\AiChatController;
use Exceedone\Exment\Model\CustomTable;

/**
 * AI summary service — everything around the LLM call, DB-free: the data-egress gates,
 * the redaction / anonymisation / sampling of the chart payload, the deterministic key
 * figures, and insight() end-to-end against a mocked OpenAI-compatible endpoint (request
 * shape, cache hit, soft failures, PII data never leaving the server). The rolling
 * rate-limit slot of the controller is pinned too.
 */
class AiChatServiceInsightTest extends DashboardUnitTestCase
{
    /** @var array<int, array> recorded HTTP transactions of the mocked client */
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'exment.ai.insight_enabled'   => true,
            'exment.ai.blocked_tables'    => '',
            'exment.ai.max_data_rows'     => 50,
            'exment.ai.insight_cache_ttl' => 3600,
            'exment.ai.scope_benchmark'   => false, // the benchmark needs the box + DB; off here
            'exment.ai.reasoning_effort'  => 'low',
            'exment.ai.insight_max_tokens' => 333,
            'exment.ai.log_usage'         => false,
            'exment.ai.anomaly_min_points' => 5,
        ]);
        Cache::flush();
        $this->history = [];
    }

    /** A service whose HTTP client answers from $responses (no network). */
    private function service(array $responses): AiChatService
    {
        $svc = new AiChatService();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $p = new ReflectionProperty($svc, 'client');
        $p->setAccessible(true);
        $p->setValue($svc, new Client(['handler' => $stack, 'base_uri' => 'https://mock.local/v1/']));
        return $svc;
    }

    private function llm(string $content, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]));
    }

    private function invokeSvc(AiChatService $svc, string $method, ...$args)
    {
        $m = new ReflectionMethod($svc, $method);
        $m->setAccessible(true);
        return $m->invoke($svc, ...$args);
    }

    private function payload(array $labels, array $values, array $extra = []): array
    {
        return $extra + [
            'title' => '地方別 平均点', 'chart_type' => 'bar', 'axis_x_label' => '地方', 'axis_y_label' => '平均点',
            'labels' => $labels, 'values' => $values, 'is_aggregate' => true,
        ];
    }

    // ---- gates ------------------------------------------------------------------------

    public function testGatesFollowConfigAndDashboardOptIn(): void
    {
        $this->assertTrue(AiChatService::insightEnabled());
        config(['exment.ai.insight_enabled' => false]);
        $this->assertFalse(AiChatService::insightEnabled());
        $this->assertFalse(AiChatService::summaryEnabledForBox(null), 'site switch off wins');

        config(['exment.ai.insight_enabled' => true, 'exment.ai.blocked_tables' => ' salary , medical_record ']);
        $salary = new CustomTable(['table_name' => 'salary']);
        $score  = new CustomTable(['table_name' => 'score']);
        $this->assertTrue(AiChatService::tableBlocked($salary), 'csv entries are trimmed');
        $this->assertFalse(AiChatService::tableBlocked($score));
        $this->assertFalse(AiChatService::tableBlocked(null));
        config(['exment.ai.blocked_tables' => '']);
        $this->assertFalse(AiChatService::tableBlocked($salary), 'empty list blocks nothing');

        // per-dashboard opt-in, default OFF
        $this->assertFalse(AiChatService::summaryEnabledForBox(null), 'no box / no dashboard = no summary');
        $off = $this->makeBox('b1', [], $this->makeDashboard(null));
        $this->assertFalse(AiChatService::summaryEnabledForBox($off));
        $on = $this->makeBox('b2', [], $this->makeDashboard(null, ['ai_summary' => true]));
        $this->assertTrue(AiChatService::summaryEnabledForBox($on));
        config(['exment.ai.insight_enabled' => false]);
        $this->assertFalse(AiChatService::summaryEnabledForBox($on), 'site switch overrides the opt-in');
    }

    // ---- redaction --------------------------------------------------------------------

    public function testSensitiveYValuesWithholdTheWholeChart(): void
    {
        $svc = $this->service([]);
        // an email anywhere in Y trips even on aggregate charts
        $this->assertNull($this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b'], ['x@y.jp', 3]), 0, 'ref'));
        // phone-shaped values: list view (raw per-record) → withheld; aggregate measure → kept
        $phones = ['090-1234-5678', '080-9999-0000', '03-1234-5678'];
        $this->assertNull($this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b', 'c'], $phones, ['is_aggregate' => false]), 0, 'ref'));
        $this->assertNotNull($this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b', 'c'], $phones, ['is_aggregate' => true]), 0, 'ref'));
        // large legitimate sums on an aggregate chart are NOT ids
        $this->assertNotNull($this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b'], [1234567890, 9876543210]), 0, 'ref'));
    }

    public function testPiiLabelsAreAnonymisedButValuesKept(): void
    {
        $svc = $this->service([]);
        $out = $this->invokeSvc($svc, 'redactInsightData', $this->payload(['taro@example.com', 'hanako'], [10, 20]), 7, 'ref');
        $this->assertTrue($out['x_redacted']);
        $this->assertSame('Category (anonymised)', $out['axis_x_label']);
        $this->assertSame([['label' => '#1', 'value' => 10], ['label' => '#2', 'value' => 20]], $out['rows']);
        $this->assertSame(7, $out['view_id']);

        $ok = $this->invokeSvc($svc, 'redactInsightData', $this->payload(['関東', '関西'], [10, 20]), 0, 'ref');
        $this->assertFalse($ok['x_redacted']);
        $this->assertSame('関東', $ok['rows'][0]['label']);
        $this->assertSame('地方', $ok['axis_x_label']);
    }

    public function testTitleScrubOnlyHitsRealIdentifiers(): void
    {
        $svc = $this->service([]);
        $t = function (string $title) use ($svc) {
            return $this->invokeSvc($svc, 'redactInsightData', $this->payload(['a'], [1], ['title' => $title]), 0, 'ref')['title'];
        };
        $this->assertSame('[redacted] の売上', $t('a.b@example.co.jp の売上'));
        $this->assertSame('売上 2015 - 2024', $t('売上 2015 - 2024'), 'a year range (8 digits) survives');
        $this->assertSame('card [redacted]', $t('card 4111 1111 1111 1111'), '16 digits = scrubbed');
    }

    public function testRowsAreCappedSampledForCategoriesHeadForSeries(): void
    {
        config(['exment.ai.max_data_rows' => 4]);
        $svc = $this->service([]);
        $labels = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
        $values = [10, 70, 30, 60, 20, 50, 40];

        $agg = $this->invokeSvc($svc, 'redactInsightData', $this->payload($labels, $values, ['is_aggregate' => true]), 0, 'ref');
        $this->assertTrue($agg['truncated']);
        $this->assertTrue($agg['sampled']);
        $this->assertSame(['b', 'd', 'e', 'a'], array_column($agg['rows'], 'label'), 'top half by value + bottom half (extremes survive the cap)');
        $this->assertCount(7, $agg['rows_full'], 'stats / anomalies still see every point');
        $this->assertSame(7, $agg['record_count']);

        $list = $this->invokeSvc($svc, 'redactInsightData', $this->payload($labels, $values, ['is_aggregate' => false]), 0, 'ref');
        $this->assertFalse($list['sampled']);
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($list['rows'], 'label'), 'time-like series keep their head');

        $small = $this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b'], [1, 2]), 0, 'ref');
        $this->assertFalse($small['truncated']);
        $this->assertSame($small['rows'], $small['rows_full']);
    }

    // ---- deterministic figures ----------------------------------------------------

    public function testInsightStats(): void
    {
        $svc = $this->service([]);
        $payload = $this->invokeSvc($svc, 'redactInsightData', $this->payload(['a', 'b', 'c', 'd'], [10, 'n/a', 40, 25]), 0, 'ref');
        $s = $this->invokeSvc($svc, 'computeInsightStats', $payload);
        $this->assertSame('平均点', $s['metric']);
        $this->assertSame(3, $s['count']);
        $this->assertSame(75.0, $s['total']);
        $this->assertSame(25.0, $s['average']);
        $this->assertSame(30.0, $s['range']);
        $this->assertSame(['label' => 'c', 'value' => 40.0], $s['highest']);
        $this->assertSame(['label' => 'a', 'value' => 10.0], $s['lowest']);
        $this->assertFalse($s['redacted']);

        $none = $this->invokeSvc($svc, 'redactInsightData', $this->payload(['a'], ['x']), 0, 'ref');
        $this->assertNull($this->invokeSvc($svc, 'computeInsightStats', $none));
    }

    // ---- insight() against a mocked endpoint -----------------------------------------

    public function testInsightCallsTheLlmOnceThenServesTheCache(): void
    {
        $svc = $this->service([$this->llm('関東が最も高く、九州が最も低い。')]);
        $data = $this->payload(['関東', '関西', '九州'], [70.5, 66.0, 60.2]);

        $first = $svc->insight('boxsuuid', $data, 9);
        $this->assertTrue($first['success']);
        $this->assertFalse($first['cached']);
        $this->assertSame('関東が最も高く、九州が最も低い。', $first['insight']);
        $this->assertNotSame('', $first['generated_at']);
        $this->assertSame(3, $first['stats']['count']);
        $this->assertArrayHasKey('anomalies', $first);
        $this->assertCount(1, $this->history, 'exactly one LLM call');

        // request shape: OpenAI-compatible, locale-aware, no leftover tool params
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/v1/chat/completions', (string) $req->getUri());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame(333, $body['max_tokens']);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertStringContainsString('関東', $body['messages'][0]['content'], 'the rows reach the prompt');
        $this->assertStringContainsString('70.5', $body['messages'][0]['content']);

        $second = $svc->insight('boxsuuid', $data, 9);
        $this->assertTrue($second['cached']);
        $this->assertSame($first['insight'], $second['insight']);
        $this->assertSame(3, $second['stats']['count'], 'stats are recomputed and attached on a cache hit too');
        $this->assertCount(1, $this->history, 'no second LLM call');
    }

    public function testCacheKeyFollowsTheDataAndTheLocale(): void
    {
        $svc = $this->service([$this->llm('A'), $this->llm('B'), $this->llm('C')]);
        $a = $svc->insight('box', $this->payload(['x', 'y'], [1, 2]), 0);
        $b = $svc->insight('box', $this->payload(['x', 'y'], [1, 3]), 0);
        $this->assertSame('A', $a['insight']);
        $this->assertSame('B', $b['insight'], 'different data = new generation');
        $this->assertSame('A', $svc->insight('box', $this->payload(['x', 'y'], [1, 2]), 0)['insight']);

        app()->setLocale('en');
        $c = $svc->insight('box', $this->payload(['x', 'y'], [1, 2]), 0);
        $this->assertSame('C', $c['insight'], 'another UI language = another generation');
        $this->assertStringContainsString('English', json_decode((string) $this->history[2]['request']->getBody(), true)['messages'][0]['content']);
    }

    public function testReasoningEffortIsOmittedWhenBlankAndTtlZeroDisablesTheCache(): void
    {
        config(['exment.ai.reasoning_effort' => '', 'exment.ai.insight_cache_ttl' => 0]);
        $svc = $this->service([$this->llm('one'), $this->llm('two')]);
        $data = $this->payload(['x', 'y'], [1, 2]);
        $this->assertSame('one', $svc->insight('box', $data, 0)['insight']);
        $this->assertSame('two', $svc->insight('box', $data, 0)['insight'], 'ttl 0 = every call generates');
        $this->assertArrayNotHasKey('reasoning_effort', json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testSoftFailuresAreNotCached(): void
    {
        $svc = $this->service([
            $this->llm(''),                                             // empty answer (reasoning budget exhausted)
            new Response(429, [], '{"error":{"message":"rate"}}'),      // upstream quota
            $this->llm('finally'),
        ]);
        $data = $this->payload(['x', 'y'], [1, 2]);

        $r1 = $svc->insight('box', $data, 0);
        $this->assertFalse($r1['success']);
        $this->assertSame(exmtrans('dashboard.ai_chat.no_valid_answer'), $r1['message']);

        $r2 = $svc->insight('box', $data, 0);
        $this->assertFalse($r2['success']);
        $this->assertSame(exmtrans('dashboard.ai_chat.api_error_rate'), $r2['message'], 'generic message, no upstream detail');

        $r3 = $svc->insight('box', $data, 0);
        $this->assertTrue($r3['success'], 'a failure never poisons the cache');
        $this->assertSame('finally', $r3['insight']);
        $this->assertCount(3, $this->history);
    }

    public function testWithheldOrEmptyDataNeverReachesTheEndpoint(): void
    {
        $svc = $this->service([$this->llm('never')]);
        $pii = $svc->insight('box', $this->payload(['a', 'b'], ['x@y.jp', 1]), 0);
        $this->assertFalse($pii['success']);
        $this->assertSame(exmtrans('dashboard.ai_insight.withheld'), $pii['message']);

        $empty = $svc->insight('box', $this->payload([], []), 0);
        $this->assertFalse($empty['success']);
        $this->assertCount(0, $this->history, 'no HTTP call for withheld / empty data');
    }

    // ---- controller rate slot -----------------------------------------------------

    public function testRateSlotIsRollingAndOnlyCountsWhatTheCallerIncrements(): void
    {
        $ctl = new AiChatController();
        $m = new ReflectionMethod($ctl, 'rateSlotFull');
        $m->setAccessible(true);
        $key = 'ai_ratec_unit';
        $this->assertFalse($m->invoke($ctl, $key, 2, 3600), 'first request anchors the window');
        $this->assertFalse($m->invoke($ctl, $key, 2, 3600), 'checking does not consume a slot');
        Cache::increment($key);
        $this->assertFalse($m->invoke($ctl, $key, 2, 3600));
        Cache::increment($key);
        $this->assertTrue($m->invoke($ctl, $key, 2, 3600), 'limit reached after two real generations');
        Cache::forget($key);
        $this->assertFalse($m->invoke($ctl, $key, 2, 3600), 'a new window starts clean');
    }
}
