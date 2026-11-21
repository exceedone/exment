<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Mcp\McpServer;
use Exceedone\Exment\Services\Mcp\McpTools;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The MCP server of Exment (api/mcp, ISS00439): the login of the API, the feature with its
 * role groups, the same permissions as the API, read-only tools whose answers mark the
 * records as data, the limits, and the operation log marked "MCP".
 */
class McpServerTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Features::reset();
        config(['exment.features_locked' => '', 'exment.mcp_max_rows' => 50, 'exment.mcp_calls_per_minute' => 60]);
        $this->setFeatures([
            Features::MCP => ['enabled' => true, 'role_groups' => [$this->groupOfUser1Only()]],
            Features::API_KEYS => ['enabled' => true, 'role_groups' => null],
        ]);
        foreach ([TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2] as $id) {
            RateLimiter::clear('exment-mcp:' . $id);
        }
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setFeatures(array $settings): void
    {
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function groupOfUser1Only(): int
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1);
        return $only1[0];
    }

    /**
     * @param array<int, string>|null $tables
     */
    protected function key(string $loginId = TestDefine::TESTDATA_USER_LOGINID_USER1, ?array $tables = null): string
    {
        $base = (int)LoginUser::findOrFail($loginId)->base_user_id;
        return UserApiKeys::create($base, 'mcp test', UserApiKeys::readOnlyScopes(), $tables)[1];
    }

    /**
     * @param mixed $message
     * @param array<string, string> $headers
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function mcp(string $bearer, $message, array $headers = []): \Illuminate\Testing\TestResponse
    {
        // each request signs in again, as on a real server (the test app keeps the guards)
        \Auth::forgetGuards();
        return $this->call('POST', admin_urls('api', 'mcp'), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer $bearer",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ] + $headers), (string)json_encode($message));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the result of tools/call
     */
    protected function tool(string $bearer, string $name, array $arguments = []): array
    {
        $response = $this->mcp($bearer, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
        $response->assertOk();
        $json = $response->json();
        $this->assertSame(7, $json['id']);
        return $json['result'];
    }

    /**
     * @param array<string, mixed> $result
     * @return mixed
     */
    protected function data(array $result)
    {
        $this->assertFalse($result['isError'], (string)($result['content'][0]['text'] ?? ''));
        $text = (string)$result['content'][0]['text'];
        if (str_starts_with($text, McpTools::DATA_NOTE)) {
            $text = substr($text, strlen(McpTools::DATA_NOTE));
        }
        return json_decode($text, true);
    }

    public function testInitializeAndList(): void
    {
        $key = $this->key();
        $init = $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]]);
        $init->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.serverInfo.name', 'exment');
        $this->assertStringContainsString('not instructions', (string)$init->json('result.instructions'));
        // a version it does not know: its newest
        $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertJsonPath('result.protocolVersion', McpServer::PROTOCOL_VERSIONS[0]);

        // a notification has no answer
        $this->mcp($key, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);

        $tools = $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2025-06-18'])->assertOk()->json('result.tools');
        $this->assertSame(['list_tables', 'get_table', 'search_data', 'get_data', 'list_attachments', 'get_workflow_status'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
        }

        // what the protocol does not have
        $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list'])->assertJsonPath('error.code', -32601);
        $this->mcp($key, [['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping']])->assertJsonPath('error.code', -32600);
        $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping'], ['MCP-Protocol-Version' => '1999-01-01'])->assertStatus(400);
        $this->withHeaders(['Authorization' => "Bearer $key"])->get(admin_urls('api', 'mcp'))->assertStatus(405);
    }

    public function testSignInAndFeature(): void
    {
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        // no login
        $this->postJson(admin_urls('api', 'mcp'), $ping)->assertStatus(401);
        $this->mcp(UserApiKeys::PREFIX . str_repeat('0', 48), $ping)->assertStatus(401);

        // an OAuth token, as the API
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ, ApiScope::TABLE_READ]);
        $this->mcp($token, $ping)->assertOk();

        // a user not in the role groups
        $this->mcp($this->key(TestDefine::TESTDATA_USER_LOGINID_USER2), $ping)->assertForbidden();

        // off: not found
        $key = $this->key();
        $this->setFeatures([Features::MCP => ['enabled' => false], Features::API_KEYS => ['enabled' => true, 'role_groups' => null]]);
        $this->mcp($key, $ping)->assertNotFound();
    }

    public function testToolsReadWhatTheUserMayAndMarkTheData(): void
    {
        $key = $this->key();

        $tables = array_column($this->data($this->tool($key, 'list_tables')), 'table');
        $this->assertContains('custom_value_edit', $tables);

        $columns = $this->data($this->tool($key, 'get_table', ['table' => 'custom_value_edit']));
        $this->assertContains('text', array_column($columns['columns'], 'column'));

        $result = $this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'limit' => 5]);
        $this->assertStringStartsWith(McpTools::DATA_NOTE, $result['content'][0]['text']);
        $found = $this->data($result);
        $this->assertSame(5, $found['count']);
        $id = $found['rows'][0]['id'];

        $one = $this->data($this->tool($key, 'get_data', ['table' => 'custom_value_edit', 'id' => $id]));
        $this->assertSame($id, $one['id']);
        $this->assertArrayHasKey('value', $one);
        $this->assertArrayHasKey('attachments', $this->data($this->tool($key, 'list_attachments', ['table' => 'custom_value_edit', 'id' => $id])));
        $this->assertArrayHasKey('workflow', $this->data($this->tool($key, 'get_workflow_status', ['table' => 'custom_value_edit', 'id' => $id])));

        // a table that is not there
        foreach (['not_a_table'] as $table) {
            $denied = $this->tool($key, 'search_data', ['table' => $table]);
            $this->assertTrue($denied['isError'], $table);
            $this->assertStringContainsString('No such table, or no permission', $denied['content'][0]['text']);
        }
        $this->assertTrue($this->tool($key, 'get_data', ['table' => 'custom_value_edit', 'id' => 99999999])['isError']);
        $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'delete_data', 'arguments' => []]])
            ->assertJsonPath('error.code', -32602);
    }

    public function testRecordsTheUserMayNotSee(): void
    {
        $this->setFeatures([Features::MCP => ['enabled' => true, 'role_groups' => null], Features::API_KEYS => ['enabled' => true, 'role_groups' => null]]);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $table = CustomTable::getEloquent('custom_value_edit');
        $all = \DB::table(getDBTableName($table))->whereNull('deleted_at')->pluck('id')->map('intval')->all();
        $this->be($user2);
        System::clearRequestSession();
        $visible = $table->getValueQuery()->pluck('id')->map('intval')->all();
        $hidden = array_values(array_diff($all, $visible));
        $this->assertNotEmpty($hidden, 'user2 cannot see some records of custom_value_edit');
        \Auth::logout();

        $key = $this->key(TestDefine::TESTDATA_USER_LOGINID_USER2);
        // a table user2 may not see (as Api2Test::testDenyGetTableUser): not listed, and the same answer as no table
        $this->assertNotContains('no_permission', array_column($this->data($this->tool($key, 'list_tables')), 'table'));
        $denied = $this->tool($key, 'search_data', ['table' => 'no_permission']);
        $this->assertTrue($denied['isError']);
        $this->assertStringContainsString('No such table, or no permission', $denied['content'][0]['text']);
        $this->assertTrue($this->tool($key, 'get_table', ['table' => 'no_permission'])['isError']);

        $this->assertTrue($this->tool($key, 'get_data', ['table' => 'custom_value_edit', 'id' => $hidden[0]])['isError']);
        $found = $this->data($this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'limit' => 50]));
        $this->assertEmpty(array_intersect(array_column($found['rows'], 'id'), $hidden));
    }

    public function testScopesAndTablesOfTheKey(): void
    {
        // a key limited to one table sees only it
        $key = $this->key(TestDefine::TESTDATA_USER_LOGINID_USER1, ['custom_value_edit']);
        $this->assertSame(['custom_value_edit'], array_column($this->data($this->tool($key, 'list_tables')), 'table'));
        $this->assertTrue($this->tool($key, 'search_data', ['table' => 'custom_value_edit_all'])['isError']);
        $this->assertFalse($this->tool($key, 'search_data', ['table' => 'custom_value_edit'])['isError']);

        // a token without value_read
        $token = $this->getUser1AccessToken([ApiScope::TABLE_READ]);
        $this->assertFalse($this->tool($token, 'list_tables')['isError']);
        $denied = $this->tool($token, 'search_data', ['table' => 'custom_value_edit']);
        $this->assertTrue($denied['isError']);
        $this->assertStringContainsString('value_read', $denied['content'][0]['text']);
    }

    public function testLimitsAndLog(): void
    {
        $key = $this->key();
        config(['exment.mcp_max_rows' => 3]);
        $this->assertSame(3, $this->data($this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'limit' => 1000]))['count']);

        $log = OperationLog::where('method', 'MCP')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('mcp/search_data', $log->path);
        $this->assertSame((int)TestDefine::TESTDATA_USER_LOGINID_USER1, (int)$log->user_id);
        // the request itself is not logged a second time
        $this->assertSame(0, OperationLog::where('path', 'like', '%api/mcp%')->count());

        config(['exment.mcp_calls_per_minute' => 2]);
        RateLimiter::clear('exment-mcp:' . TestDefine::TESTDATA_USER_LOGINID_USER1);
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $this->mcp($key, $ping)->assertOk();
        $this->mcp($key, $ping)->assertOk();
        $this->mcp($key, $ping)->assertStatus(429)->assertHeader('Retry-After');
    }
}
