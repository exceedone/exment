<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Services\Mcp\McpGuide;
use Exceedone\Exment\Controllers\McpController;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;
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
            RateLimiter::clear(McpController::limiterKey((int)$id));
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
        // every answer: the note, then one object whose only key says the data is untrusted
        $this->assertStringStartsWith(McpTools::DATA_NOTE . "\n", $text);
        $json = json_decode(substr($text, strlen(McpTools::DATA_NOTE)), true);
        $this->assertIsArray($json);
        $this->assertSame(['untrusted_data'], array_keys($json));
        return $json['untrusted_data'];
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
        $this->assertSame([
            'list_tables', 'get_table', 'search_data', 'get_data', 'list_attachments', 'get_workflow_status',
            'list_organization_users', 'list_user_organizations', 'list_role_groups', 'get_role_group',
        ], array_column($tools, 'name'));
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
        $all = \DB::table(getDBTableName($table))->whereNull('deleted_at')->pluck('id')->map(fn ($v) => intval($v))->all();
        $this->be($user2);
        System::clearRequestSession();
        $visible = $table->getValueQuery()->pluck('id')->map(fn ($v) => intval($v))->all();
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
        RateLimiter::clear(McpController::limiterKey((int)TestDefine::TESTDATA_USER_LOGINID_USER1));
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $this->mcp($key, $ping)->assertOk();
        $this->mcp($key, $ping)->assertOk();
        $this->mcp($key, $ping)->assertStatus(429)->assertHeader('Retry-After');
    }

    /**
     * The calls of a user are counted per site (the sites of a SaaS may share a cache).
     */
    public function testTheLimitIsOfTheSite(): void
    {
        config(['exment.site_id' => 'site-a']);
        $a = McpController::limiterKey(2);
        config(['exment.site_id' => 'site-b']);
        $this->assertNotSame($a, McpController::limiterKey(2));
        $this->assertNotSame(McpController::limiterKey(2), McpController::limiterKey(3));
    }

    public function testTheAttachmentsAreLimited(): void
    {
        $key = $this->key();
        $id = (int)\DB::table(getDBTableName('custom_value_edit'))->whereNull('deleted_at')->value('id');
        $documentModel = getModelName(SystemTableName::DOCUMENT);
        for ($i = 0; $i < 5; $i++) {
            $document = new $documentModel();
            $document->parent_id = $id;
            $document->parent_type = 'custom_value_edit';
            $document->setValue(['document_name' => "doc $i", 'file_uuid' => make_uuid()]);
            $document->save();
        }
        config(['exment.mcp_max_rows' => 3]);
        $this->assertCount(3, $this->data($this->tool($key, 'list_attachments', ['table' => 'custom_value_edit', 'id' => $id]))['attachments']);
    }

    /**
     * A key limited to some tables is not told the names of the other tables, as the target of a column.
     */
    public function testOtherTablesAreNotNamed(): void
    {
        $key = $this->key(TestDefine::TESTDATA_USER_LOGINID_USER1, ['custom_value_edit']);
        $columns = $this->data($this->tool($key, 'get_table', ['table' => 'custom_value_edit']))['columns'];
        foreach ($columns as $column) {
            if (isset($column['target_table'])) {
                $this->assertSame('custom_value_edit', $column['target_table'], $column['column']);
            }
        }
        // without the limit, the targets are named (when the user may see them)
        $all = $this->data($this->tool($this->key(), 'get_table', ['table' => 'custom_value_edit']))['columns'];
        $this->assertGreaterThanOrEqual(count(array_filter(array_column($columns, 'target_table'))), count(array_filter(array_column($all, 'target_table'))));
    }

    public function testWhatIsNotRunOrLogged(): void
    {
        $key = $this->key();
        // too long words
        $long = $this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'q' => str_repeat('a', McpTools::MAX_QUERY_LENGTH + 1)]);
        $this->assertTrue($long['isError']);
        $this->assertTrue($this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'q' => ['a']])['isError']);

        // a tool that is not there: not logged
        $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'delete_everything', 'arguments' => []]])
            ->assertJsonPath('error.code', -32602);
        $this->assertSame(0, OperationLog::where('method', 'MCP')->where('path', 'mcp/delete_everything')->count());

        // the logged arguments are cut
        $this->tool($key, 'list_tables', ['padding' => str_repeat('x', 10000)]);
        $log = OperationLog::where('method', 'MCP')->where('path', 'mcp/list_tables')->orderByDesc('id')->firstOrFail();
        $this->assertLessThan(McpServer::MAX_LOG_INPUT + 200, strlen((string)$log->input));
    }

    /**
     * An error of Exment is a JSON-RPC error without its details.
     */
    public function testAnInternalError(): void
    {
        $server = new class (LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)) extends McpServer {
            protected function dispatch(string $method, array $params)
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $answer = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->assertSame(-32603, $answer['error']['code'] ?? null);
        $this->assertStringNotContainsString('secret detail', (string)json_encode($answer));
    }

    /**
     * An error before the server (here: the limit of the calls) is also a JSON-RPC error without
     * its details, not a page of Exment.
     */
    public function testAnErrorOfTheController(): void
    {
        $key = $this->key();
        RateLimiter::shouldReceive('tooManyAttempts')->andThrow(new \RuntimeException('secret detail'));
        $response = $this->mcp($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $response->assertStatus(500)->assertJsonPath('error.code', -32603)->assertJsonPath('error.message', 'Internal error.');
        $this->assertStringNotContainsString('secret detail', (string)$response->getContent());
    }

    /**
     * The scopes the pages name for each tool (McpGuide, ISS02879) are the ones the tools ask:
     * without its scope a tool says which one it needs, and a key "for MCP" runs every tool.
     */
    public function testTheScopesOfTheGuideAreTheOnesTheToolsAsk(): void
    {
        $this->assertSame(McpTools::names(), array_keys(McpGuide::TOOL_SCOPES), 'every tool has its scope, in the same order');
        $record = CustomTable::getEloquent('custom_value_edit')->getValueModel()->newQueryWithoutScopes()->first();
        $this->assertNotNull($record);
        $args = [
            'list_tables' => [],
            'get_table' => ['table' => 'custom_value_edit'],
            'search_data' => ['table' => 'custom_value_edit'],
            'get_data' => ['table' => 'custom_value_edit', 'id' => $record->id],
            'list_attachments' => ['table' => 'custom_value_edit', 'id' => $record->id],
            'get_workflow_status' => ['table' => 'custom_value_edit', 'id' => $record->id],
        ];
        // the tools of the members (ISS00335): not in a key "for MCP", see McpMemberToolsTest
        $memberArgs = [
            'list_organization_users' => ['organization_id' => 1],
            'list_user_organizations' => ['user_id' => 1],
            'list_role_groups' => [],
            'get_role_group' => ['role_group_id' => 1],
        ];
        $this->assertSame(McpGuide::MEMBER_TOOLS, array_keys($memberArgs));
        $this->assertSame(array_keys(McpGuide::TOOL_SCOPES), array_merge(array_keys($args), array_keys($memberArgs)));
        /** @var array<string, array<string, mixed>> $toolArgs */
        $toolArgs = array_merge($args, $memberArgs);
        $all = UserApiKeys::readOnlyScopes();
        foreach (McpGuide::TOOL_SCOPES as $tool => $scope) {
            $token = $this->getUser1AccessToken(array_values(array_diff($all, [$scope])));
            $denied = $this->tool($token, $tool, $toolArgs[$tool] ?? []);
            $this->assertTrue($denied['isError'], "$tool without $scope");
            $this->assertStringContainsString('"' . $scope . '"', $denied['content'][0]['text'], "$tool names $scope");
        }
        $base = (int)LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id;
        $key = UserApiKeys::create($base, 'for mcp', McpGuide::scopes())[1];
        foreach ($args as $tool => $arguments) {
            $result = $this->tool($key, $tool, $arguments);
            $this->assertStringNotContainsString('does not have the scope', (string)($result['content'][0]['text'] ?? ''), "$tool with a key for MCP");
        }
    }

    /**
     * search_data reads on with next_cursor: the pages one after another are the records one
     * call reads, in the same order, none twice (ISS02885).
     */
    public function testSearchReadsOnWithTheCursor(): void
    {
        $key = $this->key();
        config(['exment.mcp_max_rows' => 100]);

        foreach ([['table' => 'custom_value_edit'], ['table' => 'custom_value_access_all', 'q' => 'index']] as $args) {
            $all = $this->data($this->tool($key, 'search_data', $args + ['limit' => 100]));
            $expected = array_column($all['rows'], 'id');
            $this->assertGreaterThan(7, count($expected), json_encode($args) ?: '');

            // all of them in one call: the pages end (no cursor) with the last of them
            $enough = count($expected) < 100 ? PHP_INT_MAX : count($expected);
            $this->assertSame($expected, $this->readAllPages($key, $args + ['limit' => 7], $enough), json_encode($args) ?: '');
        }
    }

    public function testSearchCursorOfAnotherSearch(): void
    {
        $key = $this->key();
        $first = $this->data($this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'limit' => 2]));
        $this->assertNotNull($first['next_cursor']);

        foreach ([['q' => 'other'], ['table' => 'custom_value_access_all']] as $other) {
            $denied = $this->tool($key, 'search_data', $other + ['table' => 'custom_value_edit', 'cursor' => $first['next_cursor']]);
            $this->assertTrue($denied['isError']);
            $this->assertStringContainsString('another search', $denied['content'][0]['text']);
        }
        $denied = $this->tool($key, 'search_data', ['table' => 'custom_value_edit', 'cursor' => 'not a cursor']);
        $this->assertTrue($denied['isError']);
    }

    public function testSearchStopsAtTheTimeAndReadsOn(): void
    {
        $key = $this->key();
        config(['exment.mcp_max_rows' => 100]);
        $args = ['table' => 'custom_value_edit', 'limit' => 20];
        $expected = array_column($this->data($this->tool($key, 'search_data', $args))['rows'], 'id');

        // a time no record can be read in: one record a call, and the cursor goes on
        config(['exment.mcp_search_seconds' => 0.000001]);
        $first = $this->data($this->tool($key, 'search_data', $args));
        $this->assertLessThanOrEqual(1, $first['count']);
        $this->assertArrayHasKey('stopped', $first);
        $this->assertNotNull($first['next_cursor']);

        $this->assertSame($expected, array_slice($this->readAllPages($key, $args, count($expected)), 0, count($expected)));
    }

    /**
     * The ids of the pages, read on with next_cursor until there is none or there are enough.
     *
     * @param array<string, mixed> $args
     * @return array<int, mixed>
     */
    protected function readAllPages(string $key, array $args, int $enough): array
    {
        $ids = [];
        $cursor = null;
        for ($i = 0; $i < 200; $i++) {
            $page = $this->data($this->tool($key, 'search_data', $args + (isset($cursor) ? ['cursor' => $cursor] : [])));
            foreach (array_column($page['rows'], 'id') as $id) {
                $this->assertNotContains($id, $ids, 'no record twice');
                $ids[] = $id;
            }
            $cursor = $page['next_cursor'];
            if (!isset($cursor) || count($ids) >= $enough) {
                break;
            }
        }
        return array_slice($ids, 0, $enough);
    }
}
