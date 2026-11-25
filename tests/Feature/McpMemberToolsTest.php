<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\McpController;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\MemberDirectory;
use Exceedone\Exment\Services\Mcp\McpTools;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The read tools of the members on the MCP server (ISS00335): the same answers as the API
 * (Services\MemberDirectory), the scopes user_org_read / role_group_read, the feature
 * api_members and the permission of the role groups, paging; no tool writes.
 */
class McpMemberToolsTest extends ApiTestBase
{
    use DatabaseTransactions;

    public const TOOLS = ['list_organization_users', 'list_user_organizations', 'list_role_groups', 'get_role_group'];

    public const SCOPES = [ApiScope::USER_ORG_READ, ApiScope::ROLE_GROUP_READ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['exment.features_locked' => '', 'exment.mcp_max_rows' => 50, 'exment.mcp_calls_per_minute' => 1000]);
        $this->setFeatures(true);
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER2] as $id) {
            RateLimiter::clear(McpController::limiterKey((int)$id));
        }
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    protected function setFeatures(bool $members): void
    {
        $settings = Features::settings();
        $settings[Features::MCP] = ['enabled' => true, 'role_groups' => null, 'menu' => true];
        $settings[MemberDirectory::FEATURE] = ['enabled' => $members, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the result of tools/call
     */
    protected function tool(string $token, string $name, array $arguments = []): array
    {
        \Auth::forgetGuards();
        System::clearRequestSession();
        $response = $this->call('POST', admin_urls('api', 'mcp'), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]), (string)json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments ?: new \stdClass()]]));
        $response->assertOk();
        return $response->json('result');
    }

    /**
     * @param array<string, mixed> $result
     * @return mixed
     */
    protected function data(array $result)
    {
        $this->assertFalse($result['isError'], (string)($result['content'][0]['text'] ?? ''));
        $text = (string)$result['content'][0]['text'];
        $this->assertStringStartsWith(McpTools::DATA_NOTE . "\n", $text);
        $json = json_decode(substr($text, strlen(McpTools::DATA_NOTE)), true);
        return $json['untrusted_data'];
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function assertToolError(array $result, string $contains): void
    {
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString($contains, (string)$result['content'][0]['text']);
    }

    public function testTheToolsAreListedAndReadOnly(): void
    {
        $definitions = collect(McpTools::definitions())->keyBy('name');
        foreach (static::TOOLS as $name) {
            $this->assertTrue($definitions->has($name), $name);
            $this->assertTrue($definitions[$name]['annotations']['readOnlyHint']);
            $this->assertStringContainsString('adding or removing members is done with the REST API', $definitions[$name]['description']);
        }
        // no tool that changes the members
        foreach (McpTools::names() as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(add|remove|set|delete|update)_/', $name);
        }
    }

    public function testTheSameAnswersAsTheApi(): void
    {
        $token = $this->getAdminAccessToken(static::SCOPES);
        $org = intval(TestDefine::TESTDATA_ORGANIZATION_DEV);
        $user2 = intval(\Exceedone\Exment\Model\LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2)->base_user_id);
        $group = intval(TestDefine::TESTDATA_ROLEGROUP_GENERAL);

        $users = $this->data($this->tool($token, 'list_organization_users', ['organization_id' => $org, 'limit' => 50]));
        $expected = MemberDirectory::organizationUsers($org);
        $this->assertSame($expected['organization'], $users['organization']);
        $this->assertSame($expected['users'], $users['users']);
        $this->assertSame(count($expected['users']), $users['total']);

        $orgs = $this->data($this->tool($token, 'list_user_organizations', ['user_id' => $user2]));
        $this->assertSame(MemberDirectory::userOrganizations($user2)['organizations'], $orgs['organizations']);

        $groups = $this->data($this->tool($token, 'list_role_groups', ['limit' => 50]));
        $this->assertSame(MemberDirectory::roleGroups(), $groups['role_groups']);

        $one = $this->data($this->tool($token, 'get_role_group', ['role_group_id' => $group, 'limit' => 50]));
        $expected = MemberDirectory::roleGroup($group);
        $this->assertSame($expected['permissions'], $one['permissions']);
        $this->assertSame($expected['user'], $one['user']['user']);
        $this->assertSame($expected['organization'], $one['organization']['organization']);

        // not there
        $this->assertToolError($this->tool($token, 'get_role_group', ['role_group_id' => 999999]), 'No such role group');
        $this->assertToolError($this->tool($token, 'list_organization_users', ['organization_id' => 'x']), 'must be an id');
    }

    public function testPaging(): void
    {
        $token = $this->getAdminAccessToken(static::SCOPES);
        $all = MemberDirectory::roleGroups();
        $this->assertGreaterThan(1, count($all), 'the test data has role groups');
        $first = $this->data($this->tool($token, 'list_role_groups', ['limit' => 1]));
        $this->assertCount(1, $first['role_groups']);
        $this->assertSame(count($all), $first['total']);
        $this->assertSame(1, $first['next_offset']);
        $second = $this->data($this->tool($token, 'list_role_groups', ['limit' => 1, 'offset' => 1]));
        $this->assertSame($all[1], $second['role_groups'][0]);
        $last = $this->data($this->tool($token, 'list_role_groups', ['limit' => 50]));
        $this->assertNull($last['next_offset']);
    }

    public function testRefused(): void
    {
        $org = intval(TestDefine::TESTDATA_ORGANIZATION_DEV);
        // no scope
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ, ApiScope::TABLE_READ]);
        $this->assertToolError($this->tool($token, 'list_organization_users', ['organization_id' => $org]), 'user_org_read');
        $this->assertToolError($this->tool($token, 'list_role_groups'), 'role_group_read');

        // a user without the permission of the role groups
        $user2 = $this->getUser2AccessToken(static::SCOPES);
        $this->assertToolError($this->tool($user2, 'list_role_groups'), 'Not allowed');

        // the feature off
        $admin = $this->getAdminAccessToken(static::SCOPES);
        $this->setFeatures(false);
        $this->assertToolError($this->tool($admin, 'list_role_groups'), 'Not allowed');
    }
}
