<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * RoleGroup::getHasPermissionRoleGroup($user, $orgs) reads the ids of the groups from the table
 * of the members (ISS02880): the same groups as every group with every member, filtered in PHP,
 * gave before; and the permissions of the users stay the same.
 */
class RoleGroupsOfMembersTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * The ids the way before: every group with its members, filtered.
     *
     * @param array<int, mixed> $orgIds
     * @return array<int, int>
     */
    protected function before(int $userId, array $orgIds): array
    {
        $ids = [];
        foreach (RoleGroup::with(SystemTableName::ROLE_GROUP_USER_ORGANIZATION)->get() as $roleGroup) {
            foreach ($roleGroup->role_group_user_organizations as $member) {
                if (($member->role_group_user_org_type == SystemTableName::USER && $member->role_group_target_id == $userId)
                    || ($member->role_group_user_org_type == SystemTableName::ORGANIZATION && in_array($member->role_group_target_id, $orgIds))) {
                    $ids[] = intval($roleGroup->id);
                    break;
                }
            }
        }
        sort($ids);
        return $ids;
    }

    public function testTheSameGroupsForEveryUser(): void
    {
        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
        $checked = 0;
        foreach (LoginUser::all() as $loginUser) {
            System::clearRequestSession();
            $userId = intval($loginUser->getUserId());
            $orgIds = $loginUser->getOrganizationIdsForQuery($enum);
            $now = RoleGroup::getHasPermissionRoleGroup($userId, $orgIds)->map(function ($roleGroup) {
                return intval($roleGroup->id);
            })->sort()->values()->all();
            $this->assertSame($this->before($userId, $orgIds), $now, "user $userId");
            // the permissions come with the groups
            foreach (RoleGroup::getHasPermissionRoleGroup($userId, $orgIds) as $roleGroup) {
                $this->assertTrue($roleGroup->relationLoaded(SystemTableName::ROLE_GROUP_PERMISSION));
            }
            $checked += count($now);
        }
        $this->assertGreaterThan(0, $checked, 'the test data has users in role groups');
    }

    public function testNobody(): void
    {
        $this->assertCount(0, RoleGroup::getHasPermissionRoleGroup(null, []));
        $this->assertCount(0, RoleGroup::getHasPermissionRoleGroup(99999999, [99999999]));
    }
}
