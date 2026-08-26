<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\AiSummaryService;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class AiSummaryServiceTest extends DashboardUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['exment.ai.summary_enabled' => true, 'exment.ai.blocked_tables' => '', 'exment.ai.summary_cache_ttl' => 3600, 'exment.ai.max_data_rows' => 50, 'exment.ai.log_usage' => false]);
    }

    /** @var array requests captured by the Guzzle history middleware */
    private $history = [];

    /** @return array{0: AiSummaryService, 1: MockHandler} service, handler (requests land in $this->history) */
    private function service(array $responses): array
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($this->history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://ai.test/v1/']);
        return [new AiSummaryService($client), $mock];
    }

    private function data(array $labels = ['A', 'B', 'C', 'D', 'E', 'F'], array $values = [10, 12, 11, 13, 50, 12], bool $aggregate = true): array
    {
        return ['title' => 'Avg score', 'chart_type' => 'bar', 'axis_x_label' => 'Class', 'axis_y_label' => 'Score', 'labels' => $labels, 'values' => $values, 'is_aggregate' => $aggregate];
    }

    private static function reply(string $text): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['message' => ['content' => $text]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ]));
    }

    public function testGates()
    {
        $on = $this->makeDashboard(null, ['ai_summary' => true]);
        $off = $this->makeDashboard(null);
        $this->assertTrue(AiSummaryService::enabledForBox($this->makeBox('b1', [], $on)));
        $this->assertFalse(AiSummaryService::enabledForBox($this->makeBox('b2', [], $off)), 'opt-in per dashboard, default OFF');
        $this->assertFalse(AiSummaryService::enabledForBox(null));

        config(['exment.ai.summary_enabled' => false]);
        $this->assertFalse(AiSummaryService::enabled());
        $this->assertFalse(AiSummaryService::enabledForBox($this->makeBox('b1', [], $on)), 'site-wide switch wins');
    }

    public function testBlockedTables()
    {
        config(['exment.ai.blocked_tables' => ' salary , medical ']);
        $salary = new CustomTable();
        $salary->table_name = 'salary';
        $sales = new CustomTable();
        $sales->table_name = 'sales';
        $this->assertTrue(AiSummaryService::tableBlocked($salary));
        $this->assertFalse(AiSummaryService::tableBlocked($sales));
        $this->assertFalse(AiSummaryService::tableBlocked(null));
        config(['exment.ai.blocked_tables' => '']);
        $this->assertFalse(AiSummaryService::tableBlocked($salary));
    }

    public function testSummaryWithStatsAnomaliesAndCache()
    {
        [$service, $mock] = $this->service([self::reply('Class E stands out at 50.')]);
        $result = $service->summarize('box1|fp', $this->data());

        $this->assertTrue($result['success']);
        $this->assertFalse($result['cached']);
        $this->assertSame('Class E stands out at 50.', $result['text']);
        $this->assertSame(6, $result['stats']['count']);
        $this->assertSame(['label' => 'E', 'value' => 50.0], $result['stats']['highest']);
        $this->assertSame(['label' => 'A', 'value' => 10.0], $result['stats']['lowest']);
        $this->assertSame(1, $result['anomalies']['count']);
        $this->assertSame('E', $result['anomalies']['points'][0]['label']);

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('chat/completions', ltrim($this->history[0]['request']->getUri()->getPath(), '/v1/'));
        $this->assertStringContainsString('"E"=50 (unusually high)', $sent['messages'][0]['content']);
        $this->assertStringContainsString('highest = "E" at 50', $sent['messages'][0]['content']);
        $this->assertStringContainsString('spread = highest - lowest = 40', $sent['messages'][0]['content']);
        $this->assertStringContainsString('lowest to highest (10 to 50)', $sent['messages'][0]['content'], 'range semantics pinned to the Key figures extremes');
        $this->assertStringContainsString('takeaway that stays inside the data', $sent['messages'][0]['content'], 'recommendation must not promise outcomes the data cannot show');
        $this->assertStringContainsString('title="Avg score"', $sent['messages'][0]['content']);

        // same data again: served from cache, no provider call
        $again = $service->summarize('box1|fp', $this->data());
        $this->assertTrue($again['cached']);
        $this->assertSame('Class E stands out at 50.', $again['text']);
        $this->assertSame(1, $result['anomalies']['count']);
        $this->assertCount(1, $this->history);

        // a different filter state is a different cache entry
        $mock->append(self::reply('other'));
        $this->assertFalse($service->summarize('box1|fp2', $this->data())['cached']);
        $this->assertCount(2, $this->history);
    }

    public function testStableSeriesPromptOmitsFenceValues()
    {
        [$service, $mock] = $this->service([self::reply('stable')]);
        // evenly spread values: no outlier (fences 7.5 .. 17.5)
        $result = $service->summarize('b', $this->data(['A', 'B', 'C', 'D', 'E', 'F'], [10, 11, 12, 13, 14, 15]));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['anomalies']['count']);
        $this->assertSame(7.5, $result['anomalies']['lower'], 'the strip still gets the fences');

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true)['messages'][0]['content'];
        $this->assertStringContainsString('no significant anomaly', $sent);
        $this->assertStringContainsString('do NOT quote any expected range', $sent);
        $this->assertStringNotContainsString('7.5', $sent, 'fence values are withheld from the narrative prompt');
    }

    public function testWithheldWhenValuesContainPii()
    {
        [$service, $mock] = $this->service([]);
        $result = $service->summarize('b', $this->data(['a', 'b', 'c'], ['x@example.com', 'y@example.com', 'z@example.com'], false));
        $this->assertFalse($result['success']);
        $this->assertSame(exmtrans('dashboard.ai.withheld'), $result['message']);
        $this->assertCount(0, $this->history, 'nothing leaves the server');

        // long numbers in raw list-view values look like ids
        $result = $service->summarize('b', $this->data(['a', 'b', 'c'], ['0901234567', '0912345678', '0923456789'], false));
        $this->assertFalse($result['success']);
        $this->assertCount(0, $this->history);
    }

    public function testLabelsAnonymisedAndTitleScrubbed()
    {
        [$service, $mock] = $this->service([self::reply('ok')]);
        $data = $this->data(['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com'], [1, 2, 3, 4, 5]);
        $data['title'] = 'Contact john@example.com card 4111 1111 1111 1111 years 2015 - 2024';
        $result = $service->summarize('b', $data);
        $this->assertTrue($result['success']);
        $this->assertSame('#5', $result['stats']['highest']['label']);
        $sent = json_decode((string) $this->history[0]['request']->getBody(), true)['messages'][0]['content'];
        $this->assertStringNotContainsString('a@x.com', $sent);
        $this->assertStringContainsString('anonymised', $sent);
        $this->assertStringContainsString('Contact [redacted] card [redacted] years 2015 - 2024', $sent);
    }

    public function testLargeAggregateValuesAreNotIds()
    {
        [$service, $mock] = $this->service([self::reply('ok')]);
        $result = $service->summarize('b', $this->data(['A', 'B', 'C', 'D', 'E'], [1234567890, 2345678901, 3456789012, 4567890123, 5678901234], true));
        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->history);
    }

    public function testProviderErrorsAndEmptyReply()
    {
        [$service, $mock] = $this->service([
            new RequestException('denied', new PsrRequest('POST', 'chat/completions'), new Response(401)),
            self::reply(''),
        ]);
        $result = $service->summarize('b', $this->data());
        $this->assertFalse($result['success']);
        $this->assertSame(exmtrans('dashboard.ai.api_error_auth'), $result['message']);
        $this->assertNotNull($result['stats'], 'deterministic figures are still returned');

        $result = $service->summarize('b', $this->data());
        $this->assertFalse($result['success']);
        $this->assertSame(exmtrans('dashboard.ai.no_valid_answer'), $result['message']);
        $this->assertCount(2, $this->history, 'a failed generation is never cached');
    }

    public function testEmptyData()
    {
        [$service] = $this->service([]);
        $this->assertFalse($service->summarize('b', ['labels' => []])['success']);
    }
}
