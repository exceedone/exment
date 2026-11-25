<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\ApiMemberController;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The API of the members (ISS00335): the users of an organization and the users and
 * organizations of the role groups. Its scopes, the permission of the role groups, a role
 * group of the system administrators, and the feature api_members.
 */
class ApiMemberTest extends ApiTestBase
{
    use DatabaseTransactions;

    public const SCOPES = [ApiScope::USER_ORG_READ, ApiScope::USER_ORG_WRITE, ApiScope::ROLE_GROUP_READ, ApiScope::ROLE_GROUP_WRITE];

    protected function setUp(): void
    {
        parent::setUp();
        config(['exment.features_locked' => '']);
        $this->useFeature(true);
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    protected function useFeature(bool $enabled): void
    {
        $settings = Features::settings();
        $settings[ApiMemberController::FEATURE] = ['enabled' => $enabled, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    /**
     * @param array<string, mixed> $data
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function callApi(string $token, string $method, string $path, array $data = []): \Illuminate\Testing\TestResponse
    {
        // the user of the token: the guards keep the user of the request before
        $this->app['auth']->forgetGuards();
        System::clearRequestSession();
        return $this->withHeaders(['Authorization' => "Bearer $token"])->json($method, admin_urls('api', $path), $data);
    }

    protected function userId(string $loginId): int
    {
        return intval(\Exceedone\Exment\Model\LoginUser::findOrFail($loginId)->base_user_id);
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @return array<int, int>
     */
    protected function idsOf(\Illuminate\Testing\TestResponse $response, string $key): array
    {
        $ids = array_map(function ($row) {
            return intval($row['id'] ?? 0);
        }, (array) $response->json($key));
        sort($ids);
        return $ids;
    }

    /**
     * A role group that lets its members change the members of the role groups (not a system administrator).
     */
    protected function delegatedGroup(): RoleGroup
    {
        $roleGroup = RoleGroup::create(['role_group_name' => 'api_member_test', 'role_group_view_name' => 'api member test']);
        RoleGroupPermission::create([
            'role_group_id' => $roleGroup->id,
            'role_group_permission_type' => RoleType::SYSTEM,
            'role_group_target_id' => null,
            'permissions' => [Permission::ROLE_GROUP_USER_ORGANIZATION],
        ]);
        System::clearCache();
        return $roleGroup;
    }

    /**
     * A role group that gives the system permission (the test data has none: the system
     * administrators of the test data are the setting system_admin_users).
     */
    protected function systemGroup(): RoleGroup
    {
        $roleGroup = RoleGroup::create(['role_group_name' => 'api_member_test_system', 'role_group_view_name' => 'api member test system']);
        RoleGroupPermission::create([
            'role_group_id' => $roleGroup->id,
            'role_group_permission_type' => RoleType::SYSTEM,
            'role_group_target_id' => null,
            'permissions' => [Permission::SYSTEM],
        ]);
        System::clearCache();
        $this->assertTrue(ApiMemberController::givesSystem($roleGroup));
        return $roleGroup;
    }

    public function testTheUsersOfAnOrganization(): void
    {
        $token = $this->getAdminAccessToken(static::SCOPES);
        $org = TestDefine::TESTDATA_ORGANIZATION_DEV;
        $user2 = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $before = $this->callApi($token, 'GET', "organization/$org/users")->assertOk();
        $this->assertSame(intval($org), $before->json('organization.id'));
        $this->assertNotContains($user2, $this->idsOf($before, 'users'));

        // add (twice: no double row)
        $this->callApi($token, 'POST', "organization/$org/users", ['users' => [$user2]])->assertOk();
        $after = $this->callApi($token, 'POST', "organization/$org/users", ['users' => [$user2]])->assertOk();
        $this->assertContains($user2, $this->idsOf($after, 'users'));
        $this->assertSame(count($this->idsOf($before, 'users')) + 1, count($this->idsOf($after, 'users')));
        $orgs = $this->callApi($token, 'GET', "user/$user2/organizations")->assertOk();
        $this->assertContains(intval($org), $this->idsOf($orgs, 'organizations'));

        // remove
        $removed = $this->callApi($token, 'DELETE', "organization/$org/users", ['users' => [$user2]])->assertOk();
        $this->assertSame($this->idsOf($before, 'users'), $this->idsOf($removed, 'users'));
        $this->assertNotContains(intval($org), $this->idsOf($this->callApi($token, 'GET', "user/$user2/organizations"), 'organizations'));
    }

    public function testTheMembersOfARoleGroup(): void
    {
        $token = $this->getAdminAccessToken(static::SCOPES);
        $group = TestDefine::TESTDATA_ROLEGROUP_GENERAL;
        // a user who is not in it (user2 is)
        $user2 = $this->userId(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $org = intval(TestDefine::TESTDATA_ORGANIZATION_COMPANY1);

        $list = $this->callApi($token, 'GET', 'role_group')->assertOk();
        $this->assertContains(intval($group), array_map(function ($row) {
            return intval($row['id'] ?? 0);
        }, (array) $list->json()));

        $before = $this->callApi($token, 'GET', "role_group/$group")->assertOk();
        $this->assertArrayHasKey('permissions', $before->json());

        $added = $this->callApi($token, 'POST', "role_group/$group/members", ['user' => [$user2], 'organization' => [$org]])->assertOk();
        $this->assertContains($user2, $this->idsOf($added, 'user'));
        $this->assertContains($org, $this->idsOf($added, 'organization'));
        $this->assertSame(1, RoleGroupUserOrganization::where('role_group_id', $group)->where('role_group_user_org_type', SystemTableName::USER)->where('role_group_target_id', $user2)->count());

        $removed = $this->callApi($token, 'DELETE', "role_group/$group/members", ['user' => [$user2], 'organization' => [$org]])->assertOk();
        $this->assertSame($this->idsOf($before, 'user'), $this->idsOf($removed, 'user'));
        $this->assertSame($this->idsOf($before, 'organization'), $this->idsOf($removed, 'organization'));
    }

    public function testAnAddedMemberHasThePermissionAtOnce(): void
    {
        $delegated = $this->delegatedGroup();
        $user2 = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $user2Token = $this->getUser2AccessToken(static::SCOPES);
        $this->callApi($user2Token, 'GET', 'role_group')->assertForbidden();

        $admin = $this->getAdminAccessToken(static::SCOPES);
        $this->callApi($admin, 'POST', "role_group/{$delegated->id}/members", ['user' => [$user2]])->assertOk();

        // the next request of user2 has the permission
        $this->callApi($user2Token, 'GET', 'role_group')->assertOk();
        // but not to change a role group of the system administrators
        $system = $this->systemGroup();
        $this->callApi($user2Token, 'POST', "role_group/{$system->id}/members", ['user' => [$user2]])->assertForbidden();
        $this->assertSame(0, RoleGroupUserOrganization::where('role_group_id', $system->id)->where('role_group_user_org_type', SystemTableName::USER)->where('role_group_target_id', $user2)->count());
        // a group that is not: yes
        $this->callApi($user2Token, 'POST', 'role_group/' . TestDefine::TESTDATA_ROLEGROUP_GENERAL . '/members', ['user' => [$user2]])->assertOk();
    }

    public function testRefused(): void
    {
        $org = TestDefine::TESTDATA_ORGANIZATION_DEV;
        $user2 = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2);

        // a user without the permission
        $token = $this->getUser2AccessToken(static::SCOPES);
        $this->callApi($token, 'GET', "organization/$org/users")->assertForbidden();
        $this->callApi($token, 'POST', "organization/$org/users", ['users' => [$user2]])->assertForbidden();
        $this->callApi($token, 'POST', 'role_group/' . TestDefine::TESTDATA_ROLEGROUP_GENERAL . '/members', ['user' => [$user2]])->assertForbidden();

        // a token without the scopes (the read scope does not write)
        $read = $this->getAdminAccessToken([ApiScope::USER_ORG_READ, ApiScope::ROLE_GROUP_READ, ApiScope::VALUE_WRITE]);
        $this->callApi($read, 'GET', "organization/$org/users")->assertOk();
        $this->callApi($read, 'POST', "organization/$org/users", ['users' => [$user2]])->assertForbidden();
        $this->callApi($read, 'DELETE', 'role_group/' . TestDefine::TESTDATA_ROLEGROUP_GENERAL . '/members', ['user' => [$user2]])->assertForbidden();
        $value = $this->getAdminAccessToken([ApiScope::VALUE_READ, ApiScope::VALUE_WRITE]);
        $this->callApi($value, 'GET', 'role_group')->assertForbidden();

        // the feature off
        $admin = $this->getAdminAccessToken(static::SCOPES);
        $this->useFeature(false);
        $status = $this->callApi($admin, 'GET', 'role_group')->getStatusCode();
        $this->assertContains($status, [403, 404]);
    }

    public function testWrongIds(): void
    {
        $token = $this->getAdminAccessToken(static::SCOPES);
        $org = TestDefine::TESTDATA_ORGANIZATION_DEV;
        $this->callApi($token, 'POST', "organization/$org/users", [])->assertStatus(400);
        $this->callApi($token, 'POST', "organization/$org/users", ['users' => ['x']])->assertStatus(400);
        $this->callApi($token, 'POST', "organization/$org/users", ['users' => [999999]])->assertStatus(400);
        $this->callApi($token, 'POST', 'organization/999999/users', ['users' => [1]])->assertStatus(400);
        $this->callApi($token, 'POST', 'role_group/' . TestDefine::TESTDATA_ROLEGROUP_GENERAL . '/members', [])->assertStatus(400);
        $this->callApi($token, 'GET', 'role_group/999999')->assertStatus(400);
    }
}
