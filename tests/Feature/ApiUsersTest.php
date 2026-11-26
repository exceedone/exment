<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\McpController;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\ApiUsers;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may use the API (ISS02915): nothing chosen, every user (as before); else the users,
 * organizations (and the ones below) and role groups chosen, and the system administrators. The
 * access tokens, the API keys and MCP alike, refused with 403 and the code 302; the web API of the
 * pages is not the API. The users section of the IT audit report tells it.
 */
class ApiUsersTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        System::api_allowed_users([]);
        System::api_allowed_organizations([]);
        System::api_allowed_role_groups([]);
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function user(string $login): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($login);
        return $user;
    }

    /**
     * @return TestResponse<Response>
     */
    protected function version(string $token): TestResponse
    {
        \Auth::forgetGuards();
        System::clearRequestSession();
        return $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'version'));
    }

    /**
     * @param TestResponse<Response> $response
     */
    protected function assertRefused(TestResponse $response, string $what): void
    {
        $response->assertStatus(403);
        $this->assertSame(ErrorCode::API_NOT_ALLOWED, strval($response->json('code')), $what);
    }

    public function testNothingChosenEveryUser(): void
    {
        $this->assertFalse(ApiUsers::limited());
        $this->version($this->getUser2AccessToken())->assertStatus(200);
        $this->assertTrue(ApiUsers::allows($this->user(TestDefine::TESTDATA_USER_LOGINID_USER2), false));
    }

    public function testOnlyTheChosenUsers(): void
    {
        $admin = $this->getAdminAccessToken();
        $user1 = $this->getUser1AccessToken();
        $user2 = $this->getUser2AccessToken();
        System::api_allowed_users([$this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id]);
        System::clearCache();

        $this->version($user1)->assertStatus(200);
        $this->assertRefused($this->version($user2), 'an access token');
        // the system administrators always
        $this->version($admin)->assertStatus(200);

        // the web API of the pages is the pages
        System::clearRequestSession();
        \Auth::forgetGuards();
        $this->flushHeaders();
        $this->be($this->user(TestDefine::TESTDATA_USER_LOGINID_USER2), 'admin');
        $this->get(admin_urls('webapi', 'version'), ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(200);
    }

    public function testApiKeysAndMcp(): void
    {
        Features::reset();
        config(['exment.features_locked' => '']);
        System::features([
            Features::MCP => ['enabled' => true, 'role_groups' => null],
            Features::API_KEYS => ['enabled' => true, 'role_groups' => null],
        ]);
        System::clearRequestSession();
        Features::reset();
        $user2 = $this->user(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $key = UserApiKeys::create((int) $user2->base_user_id, 'api users test', UserApiKeys::readOnlyScopes(), null)[1];
        $this->version($key)->assertStatus(200);

        System::api_allowed_users([$this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id]);
        System::clearCache();
        $this->assertRefused($this->version($key), 'an API key');

        RateLimiter::clear(McpController::limiterKey((int) $user2->id));
        \Auth::forgetGuards();
        System::clearRequestSession();
        $response = $this->call('POST', admin_urls('api', 'mcp'), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer $key",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]), strval(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])));
        $this->assertRefused($response, 'MCP');
    }

    public function testOrganizationsAndRoleGroups(): void
    {
        $userB = $this->getDevUserBAccessToken();
        $userC = $this->getDev1UserCAccessToken();
        $user1 = $this->getUser1AccessToken();
        // an organization: its users and the users of the organizations below it
        System::api_allowed_organizations([TestDefine::TESTDATA_ORGANIZATION_DEV]);
        System::clearCache();
        $this->version($userB)->assertStatus(200);
        $this->version($userC)->assertStatus(200);
        $this->assertRefused($this->version($user1), 'not in the organization');

        // a role group
        System::api_allowed_organizations([]);
        System::api_allowed_role_groups([TestDefine::TESTDATA_ROLEGROUP_GENERAL]);
        System::clearCache();
        foreach ([TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC] as $login) {
            $user = $this->user($login);
            $this->assertSame(in_array(intval(TestDefine::TESTDATA_ROLEGROUP_GENERAL), Features::roleGroupIds($user), true), ApiUsers::allows($user, false), $login);
        }
    }

    public function testTheAuditReport(): void
    {
        $this->initAllTest();
        System::api_allowed_users([$this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id]);
        System::clearCache();
        $this->be($this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $report = new AuditReport(Carbon::now()->subDay(), Carbon::now());
        $section = $report->section('users');
        $this->assertArrayHasKey('api', $section['columns']);
        $api = [];
        foreach ($section['rows'] as $row) {
            $api[$row[0]] = end($row);
        }
        $this->assertSame(AuditReport::text('api_allowed'), $api['user1']);
        $this->assertSame(AuditReport::text('api_not_allowed'), $api['user2']);
        $this->assertSame(AuditReport::text('api_allowed'), $api['admin']);

        // a role group: each user with the organizations of the user, not of the one who reads
        System::api_allowed_users([]);
        System::api_allowed_role_groups([TestDefine::TESTDATA_ROLEGROUP_GENERAL]);
        System::clearCache();
        System::clearRequestSession();
        Features::reset();
        $section = (new AuditReport(Carbon::now()->subDay(), Carbon::now()))->section('users');
        $api = [];
        foreach ($section['rows'] as $row) {
            $api[$row[0]] = end($row);
        }
        // the members of the role group: user2, user3 and the organization dev (with the ones below
        // and above it: company1); company2 is not of it
        foreach (['user2' => true, 'user3' => true, 'dev0-userB' => true, 'dev1-userC' => true, 'company1-userA' => true, 'user1' => false, 'company2-userF' => false] as $login => $allowed) {
            $this->assertSame(AuditReport::text($allowed ? 'api_allowed' : 'api_not_allowed'), $api[$login], $login);
        }
    }
}
