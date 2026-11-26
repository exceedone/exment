<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\McpController;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Services\LogManagement\McpLogs;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The log of the MCP calls (ISS02915): nothing while it is off; on, each call with its tool, user,
 * time taken and answer size, the tool errors, the JSON-RPC errors and the refusals with their
 * reason (a flood of refusals once a minute), the secrets of the arguments masked, and the page
 * for the system administrators and the role groups with "Audit" only.
 */
class McpLogTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        McpLog::resetTableExists();
        LogCatalog::reset();
        config(['exment.features_locked' => '', 'exment.mcp_max_rows' => 50, 'exment.mcp_calls_per_minute' => 60]);
        $this->useLog(false);
        $base = $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        RateLimiter::clear(McpController::limiterKey((int)TestDefine::TESTDATA_USER_LOGINID_USER1));
        Cache::forget('exment_mcp_log_refused:' . $base);
    }

    protected function tearDown(): void
    {
        $this->useLog(false);
        System::clearCache();
        parent::tearDown();
    }

    protected function useLog(bool $on): void
    {
        System::features([
            Features::MCP => ['enabled' => true, 'role_groups' => null],
            Features::API_KEYS => ['enabled' => true, 'role_groups' => null],
            McpLogs::FEATURE => ['enabled' => $on, 'role_groups' => null],
        ]);
        System::clearRequestSession();
        Features::reset();
    }

    protected function baseUserId(string $loginId): int
    {
        return (int)LoginUser::findOrFail($loginId)->base_user_id;
    }

    protected function key(): string
    {
        return UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'mcp log test', UserApiKeys::readOnlyScopes(), null)[1];
    }

    /**
     * @param array<string, string> $headers
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function raw(string $bearer, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        \Auth::forgetGuards();
        return $this->call('POST', admin_urls('api', 'mcp'), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer $bearer",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ] + $headers), $body);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function tool(string $bearer, string $name, array $arguments = []): \Illuminate\Testing\TestResponse
    {
        return $this->raw($bearer, (string)json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]));
    }

    /**
     * @return \Illuminate\Support\Collection<int, McpLog>
     */
    protected function rowsAfter(int $last)
    {
        LogWriter::flush();
        return McpLog::query()->where('id', '>', $last)->orderBy('id')->get();
    }

    protected function last(): int
    {
        return McpLog::tableExists() ? intval(McpLog::query()->max('id')) : 0;
    }

    public function testNothingWhileItIsOff(): void
    {
        $this->assertTrue(McpLog::tableExists(), 'the table is made by the migration');
        $key = $this->key();
        $last = $this->last();
        $this->tool($key, 'list_tables')->assertOk();
        $this->assertCount(0, $this->rowsAfter($last));
    }

    public function testACallIsKept(): void
    {
        $this->useLog(true);
        $key = $this->key();
        $last = $this->last();

        $response = $this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'limit' => 2, 'password' => 'TOPSECRET7319']);
        $response->assertOk();

        $rows = $this->rowsAfter($last);
        $this->assertCount(1, $rows);
        /** @var McpLog $row */
        $row = $rows[0];
        $this->assertSame($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), (int)$row->base_user_id);
        $this->assertSame('tools/call', $row->method);
        $this->assertSame('search_data', $row->tool);
        $this->assertSame(McpLog::STATUS_OK, $row->status);
        $this->assertNull($row->reason);
        $this->assertNotNull($row->duration_ms);
        $this->assertSame(strlen(strval($response->getContent())), $row->result_bytes);
        $this->assertNotNull($row->created_at);
        // the arguments, a secret masked
        $this->assertStringContainsString('table', strval($row->args));
        $this->assertStringNotContainsString('TOPSECRET7319', strval($row->args));
    }

    public function testToolErrorsAndErrors(): void
    {
        $this->useLog(true);
        $key = $this->key();
        $last = $this->last();

        // the tool answers isError (no such record)
        $this->tool($key, 'get_data', ['table' => 'custom_value_edit', 'id' => 99999999])->assertOk();
        // a JSON-RPC error (no such tool)
        $this->tool($key, 'no_such_tool')->assertOk();
        // a method there is not
        $this->raw($key, (string)json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list']))->assertOk();

        $rows = $this->rowsAfter($last);
        $this->assertSame([McpLog::STATUS_TOOL_ERROR, McpLog::STATUS_ERROR, McpLog::STATUS_ERROR], $rows->pluck('status')->all());
        $this->assertSame('get_data', $rows[0]->tool);
        $this->assertNotEmpty($rows[0]->reason);
        $this->assertSame(-32602, $rows[1]->rpc_code);
        $this->assertSame('no_such_tool', $rows[1]->tool);
        $this->assertSame('resources/list', $rows[2]->method);
        $this->assertSame(-32601, $rows[2]->rpc_code);
    }

    public function testRefusalsAndTheirReasons(): void
    {
        $this->useLog(true);
        $key = $this->key();
        $last = $this->last();

        $this->raw($key, '{not json')->assertStatus(400);
        $this->raw($key, (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), ['MCP-Protocol-Version' => '1999-01-01'])->assertStatus(400);

        config(['exment.mcp_calls_per_minute' => 1]);
        RateLimiter::clear(McpController::limiterKey((int)TestDefine::TESTDATA_USER_LOGINID_USER1));
        $ping = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->raw($key, $ping)->assertOk();
        $this->raw($key, $ping)->assertStatus(429);
        $this->raw($key, $ping)->assertStatus(429);
        $this->raw($key, $ping)->assertStatus(429);

        $rows = $this->rowsAfter($last);
        $this->assertSame(['parse_error', 'unsupported_version', null, 'rate_limited'], $rows->pluck('reason')->all(), 'the refusals for too many calls: one row a minute');
        $this->assertSame([McpLog::STATUS_REFUSED, McpLog::STATUS_REFUSED, McpLog::STATUS_OK, McpLog::STATUS_REFUSED], $rows->pluck('status')->all());
        $this->assertSame('ping', $rows[3]->method);
    }

    /**
     * Refused by the limit of the API route (before the controller): kept too, once a minute, and
     * the client gets a JSON-RPC error.
     */
    public function testRefusedByTheLimitOfTheApi(): void
    {
        $this->useLog(true);
        // the limit of the API on (ApiTestBase turns ThrottleRequests off)
        $this->app->forgetInstance(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['exment.api_max_rate_limit' => 2, 'exment.mcp_calls_per_minute' => 100]);
        $key = $this->key();
        $last = $this->last();
        $ping = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $this->raw($key, $ping)->assertOk();
        $this->raw($key, $ping)->assertOk();
        $refused = $this->raw($key, $ping);
        $refused->assertStatus(429);
        $this->assertSame(-32000, $refused->json('error.code'));
        $this->raw($key, $ping)->assertStatus(429);

        $rows = $this->rowsAfter($last);
        $this->assertSame([McpLog::STATUS_OK, McpLog::STATUS_OK, McpLog::STATUS_REFUSED], $rows->pluck('status')->all());
        $this->assertSame('rate_limited', $rows[2]->reason);
        $this->assertSame('ping', $rows[2]->method);
        $this->assertSame($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), (int)$rows[2]->base_user_id);
    }

    /**
     * While the input is kept (log_store_input), the values named token, authorization or
     * credentials are masked too, at any depth; the others are kept.
     */
    public function testTheSecretsAreMaskedWhileTheInputIsKept(): void
    {
        $masked = McpLogs::maskGenericSecrets([
            'table' => 'custom_value_edit',
            'token' => 'tok-SECRET333',
            'nested' => ['Authorization' => 'Bearer SECRET444', 'credentials' => 'SECRET555', 'q' => 'kept'],
            'credential' => ['SECRET666', ['deep' => 'SECRET777']],
        ]);
        $this->assertSame('***', $masked['credential'], 'a list is masked whole');
        $this->assertSame('custom_value_edit', $masked['table']);
        $this->assertSame('kept', $masked['nested']['q']);
        $text = strval(json_encode($masked));
        foreach (['SECRET333', 'SECRET444', 'SECRET555', 'SECRET666', 'SECRET777'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    /**
     * The IT audit report: the MCP calls of the period, a user a row (counts and tools, no
     * arguments), and its CSV; not there while the MCP log is off.
     */
    public function testTheAuditReport(): void
    {
        $this->useLog(true);
        $user1 = $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $row = function (string $at, ?string $tool, string $status, string $args = '{"table":"x"}') use ($user1): void {
            McpLog::query()->insert(['base_user_id' => $user1, 'method' => isset($tool) ? 'tools/call' : 'ping', 'tool' => $tool, 'status' => $status, 'args' => $args, 'created_at' => $at]);
        };
        $row('2020-05-01 10:00:00', 'search_data', McpLog::STATUS_OK, '{"table":"ARGUMENT7319"}');
        $row('2020-05-02 11:00:00', 'get_data', McpLog::STATUS_TOOL_ERROR);
        $row('2020-05-03 12:00:00', 'search_data', McpLog::STATUS_OK);
        $row('2020-05-04 13:00:00', null, McpLog::STATUS_REFUSED);
        // out of the period
        $row('2020-07-01 10:00:00', 'list_tables', McpLog::STATUS_OK);

        $this->assertContains('mcp_calls', \Exceedone\Exment\Services\AuditReport\AuditReport::sectionKeys());
        $report = new \Exceedone\Exment\Services\AuditReport\AuditReport(\Carbon\Carbon::parse('2020-04-01'), \Carbon\Carbon::parse('2020-06-30'));
        $section = $report->section('mcp_calls');
        $this->assertSame(['user_code', 'user_name', 'mcp_calls', 'mcp_errors', 'mcp_refused', 'mcp_tools', 'first_call', 'last_call'], array_keys($section['columns']));
        $mine = array_values(array_filter($section['rows'], fn ($r) => $r[0] === strval(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->user_code)));
        $this->assertCount(1, $mine);
        $this->assertSame(['4', '1', '1', 'get_data, search_data', '2020-05-01 10:00', '2020-05-04 13:00'], array_slice($mine[0], 2));
        $this->assertStringNotContainsString('ARGUMENT7319', strval(json_encode($section)));

        // the CSV (the system administrators and the auditors)
        \Auth::forgetGuards();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        System::clearRequestSession();
        $query = ['from' => '2020-04-01', 'to' => '2020-06-30'];
        $csv = $this->get(admin_urls_query('log_management', 'audit', 'mcp_calls.csv', $query));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('get_data, search_data', $content);
        $this->assertStringNotContainsString('ARGUMENT7319', $content);

        // off: not in the report
        $this->useLog(false);
        $this->assertNotContains('mcp_calls', \Exceedone\Exment\Services\AuditReport\AuditReport::sectionKeys());
        $this->get(admin_urls_query('log_management', 'audit', 'mcp_calls.csv', $query))->assertNotFound();
    }

    public function testThePageIsForTheAuditors(): void
    {
        $this->useLog(true);
        $key = $this->key();
        $this->tool($key, 'list_tables')->assertOk();
        LogWriter::flush();

        \Auth::forgetGuards();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        System::clearRequestSession();
        $this->get(admin_urls('log_management', 'mcp'))->assertOk()->assertSee('list_tables');
        $this->get(admin_urls_query('log_management', 'mcp', ['tool' => 'search_data']))->assertOk()->assertDontSee('<td class="small">list_tables</td>', false);
        $this->get(admin_url('log_management'))->assertOk()->assertSee(admin_urls('log_management', 'mcp'), false);

        // the days kept: the system administrators
        $this->post(admin_urls('log_management', 'mcp', 'setting'), ['log_retention_mcp_log' => '30'])->assertRedirect();
        System::clearCache();
        $this->assertSame(30, System::log_retention_mcp_log());
        $this->assertSame(30, LogCatalog::types()['mcp_log']['retention_days']);

        // not for a user without Audit
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1), 'admin');
        System::clearRequestSession();
        $denied = $this->get(admin_urls('log_management', 'mcp'), ['X-Requested-With' => 'XMLHttpRequest']);
        $denied->assertStatus(403);
        $denied->assertDontSee('list_tables');
    }
}
