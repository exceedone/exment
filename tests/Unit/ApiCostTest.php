<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\ThrottleRequests;
use Exceedone\Exment\Services\ApiCost;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limit of the API and MCP in points (ISS02885 #5).
 */
class ApiCostTest extends UnitTestBase
{
    /**
     * An address of this run: the counts of an earlier run may still be in the cache.
     */
    protected function ip(int $n): string
    {
        static $run = null;
        $run = $run ?? random_int(0, 255);
        return '10.' . $run . '.' . random_int(0, 255) . '.' . $n;
    }

    protected function tearDown(): void
    {
        config(['exment.api_rate_cost' => false]);
        parent::tearDown();
    }

    /**
     * A request of an API action, from an address of its own.
     *
     * @param array<string, mixed> $query
     */
    protected function apiRequest(string $method, array $query = [], string $ip = '198.51.100.10'): Request
    {
        $request = Request::create('/admin/api/data/test', 'GET', $query, [], [], ['REMOTE_ADDR' => $ip]);
        $action = 'Exceedone\\Exment\\Controllers\\ApiDataController@' . $method;
        // as the router makes it: the controller of the action
        $route = new Route('GET', 'admin/api/data/{tableKey}', ['uses' => $action, 'controller' => $action]);
        $route->bind($request);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });
        return $request;
    }

    public function testThePointsOfTheCalls(): void
    {
        // records: 1 + 1 for every 50 rows (the default is 20 rows)
        $this->assertSame(2, ApiCost::ofRequest($this->apiRequest('dataList')));
        $this->assertSame(2, ApiCost::ofRequest($this->apiRequest('dataList', ['count' => 50])));
        $this->assertSame(3, ApiCost::ofRequest($this->apiRequest('dataList', ['count' => 100])));
        $this->assertSame(3, ApiCost::ofRequest($this->apiRequest('dataList', ['count' => 10000])), 'at most the maximum rows');
        $this->assertSame(2, ApiCost::ofRequest($this->apiRequest('dataFind')), 'one record');
        // a search: one more
        $this->assertSame(3, ApiCost::ofRequest($this->apiRequest('dataQuery', ['q' => 'abc'])));
        // a view: 2 + 1 for every 50 rows
        $this->assertSame(3, ApiCost::ofRequest($this->apiRequest('viewDataList')));
        $this->assertSame(4, ApiCost::ofRequest($this->apiRequest('viewDataList', ['count' => 100])));
        // children and parents: one more each
        $this->assertSame(4, ApiCost::ofRequest($this->apiRequest('dataList', ['children' => 1, 'parents' => 1])));
        $this->assertSame(2, ApiCost::ofRequest($this->apiRequest('dataList', ['children' => 0])));
        // total=0: one less, never below 1
        $this->assertSame(1, ApiCost::ofRequest($this->apiRequest('dataList', ['total' => 0])));
        $this->assertSame(1, ApiCost::ofRequest($this->apiRequest('dataFind', ['total' => 'false'])));
        // the others: 1
        $this->assertSame(1, ApiCost::ofRequest($this->apiRequest('dataCreate')));
        $request = Request::create('/admin/api/table', 'GET');
        $this->assertSame(1, ApiCost::ofRequest($request), 'no route');
    }

    public function testThePointsOfMcp(): void
    {
        $call = function (array $arguments) {
            return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_data', 'arguments' => $arguments]];
        };
        $this->assertSame(2, ApiCost::ofMcpMessage($call(['table' => 'x'])));
        $this->assertSame(3, ApiCost::ofMcpMessage($call(['table' => 'x', 'q' => 'abc'])));
        $this->assertSame(2, ApiCost::ofMcpMessage($call(['table' => 'x', 'limit' => 50, 'q' => ' '])));
        $this->assertSame(3, ApiCost::ofMcpMessage($call(['table' => 'x', 'limit' => 51])));
        $this->assertSame(1, ApiCost::ofMcpMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        $this->assertSame(1, ApiCost::ofMcpMessage(['method' => 'tools/call', 'params' => ['name' => 'list_tables']]));
        $this->assertSame(3, ApiCost::ofMcpMessage([$call(['table' => 'x']), ['method' => 'tools/list']]), 'a batch');
        $this->assertSame(1, ApiCost::ofMcpMessage('not json'));
    }

    public function testTheModes(): void
    {
        foreach ([[false, 'off'], ['false', 'off'], [null, 'off'], ['', 'off'], [true, 'on'], ['true', 'on'], ['1', 'on'], ['log', 'log'], ['LOG', 'log']] as [$value, $mode]) {
            config(['exment.api_rate_cost' => $value]);
            $this->assertSame($mode, ApiCost::mode(), json_encode($value) ?: '');
        }
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function throttle(Request $request)
    {
        return app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle($request, function () {
            return response('ok');
        }, 'api');
    }

    public function testTheApiLimiterCountsPointsWhenOn(): void
    {
        $this->assertInstanceOf(ThrottleRequests::class, app(\Illuminate\Routing\Middleware\ThrottleRequests::class));
        config(['exment.api_max_rate_limit' => 60]);

        // off: 1 a call, as before
        config(['exment.api_rate_cost' => false]);
        $response = $this->throttle($this->apiRequest('dataList', ['count' => 100], $this->ip(21)));
        $this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));

        // on: the points of the call
        config(['exment.api_rate_cost' => true]);
        $response = $this->throttle($this->apiRequest('dataList', ['count' => 100], $this->ip(22)));
        $this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('57', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testACallOverThePointsIsRefusedWhenOn(): void
    {
        config(['exment.api_max_rate_limit' => 5, 'exment.api_rate_cost' => true]);
        $ip = $this->ip(31);
        $this->throttle($this->apiRequest('dataList', ['count' => 100], $ip)); // 3 of 5
        try {
            $this->throttle($this->apiRequest('dataList', ['count' => 100], $ip)); // 6 > 5
            $this->fail('the second call does not fit');
        } catch (ThrottleRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
        }
        // a light call still fits in what is left
        $response = $this->throttle($this->apiRequest('dataCreate', [], $ip));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * A log line of an API key says its user and the key: the key signs in after the limiter, so
     * it is found by its hash.
     */
    public function testTheLogLineOfAnApiKeySaysItsUserAndKey(): void
    {
        \DB::beginTransaction();
        try {
            [$key, $plain] = \Exceedone\Exment\Services\UserApiKeys::create(1, 'api cost log', [\Exceedone\Exment\Enums\ApiScope::ME]);
            $request = $this->apiRequest('dataList', [], $this->ip(51));
            $request->headers->set('Authorization', 'Bearer ' . $plain);
            $this->assertSame('(user 1, API key ' . intval($key->id) . ')', ApiCost::whoOf($request));

            // an unknown key, or none: the address
            $request->headers->set('Authorization', 'Bearer exk_unknown0000000000000000000000000000');
            $this->assertStringStartsWith('(from ', ApiCost::whoOf($request));
            $request->headers->remove('Authorization');
            $this->assertStringStartsWith('(from ', ApiCost::whoOf($request));
        } finally {
            \DB::rollBack();
        }
    }

    public function testLogModeLogsAndDoesNotRefuse(): void
    {
        config(['exment.api_max_rate_limit' => 5, 'exment.api_rate_cost' => 'log']);
        $log = Log::spy();
        $ip = $this->ip(41);
        foreach ([1, 2] as $i) {
            $response = $this->throttle($this->apiRequest('dataList', ['count' => 100], $ip));
            $this->assertSame('ok', $response->getContent(), 'not refused');
        }
        // limited as before: 1 a call
        $this->assertSame('3', $response->headers->get('X-RateLimit-Remaining'));
        $log->shouldHaveReceived('warning')->once()->withArgs(function ($message) {
            return str_contains($message, 'over its rate limit in points (6 / 5 a minute)');
        });
    }
}
