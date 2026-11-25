<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;

/**
 * AuthUserOrgHelper::getOrganizationIdsForQuery reads only the links of the organizations and
 * the organizations of the user (ISS02880): the same ids, in the same order, as the whole
 * tree of the organizations (getOrganizationTreeArray + setJoinedOrganization) gave before.
 */
class OrganizationIdsForQueryTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * The ids as the whole tree gives them (the way before ISS02880).
     *
     * @return array<int, mixed>
     */
    protected function byTree(string $filterType, int $userId): array
    {
        $tree = new \ReflectionMethod(AuthUserOrgHelper::class, 'getOrganizationTreeArray');
        $joined = new \ReflectionMethod(AuthUserOrgHelper::class, 'setJoinedOrganization');
        $results = [];
        foreach ($tree->invoke(null) as $org) {
            $joined->invokeArgs(null, [&$results, $org, $filterType, $userId]);
        }
        return array_map(function ($org) {
            return $org['id'];
        }, $results);
    }

    /**
     * @return array<int, int>
     */
    protected function userIds(): array
    {
        return array_map(function ($id) {
            return intval($id);
        }, getModelName(SystemTableName::USER)::query()->pluck('id')->all());
    }

    public function testTheSameIdsAsTheTree(): void
    {
        $this->assertTrue(System::organization_available());
        $compared = 0;
        foreach ($this->userIds() as $userId) {
            foreach ([JoinedOrgFilterType::ALL, JoinedOrgFilterType::ONLY_UPPER, JoinedOrgFilterType::ONLY_DOWNER, JoinedOrgFilterType::ONLY_JOIN] as $type) {
                System::clearRequestSession();
                $expected = $this->byTree($type, $userId);
                System::clearRequestSession();
                $this->assertSame($expected, AuthUserOrgHelper::getOrganizationIdsForQuery($type, $userId), "user $userId, type $type");
                $compared += count($expected);
            }
        }
        $this->assertGreaterThan(0, $compared, 'the test data has users in organizations');
    }

    public function testADeepTreeAndALoop(): void
    {
        $orgModel = getModelName(SystemTableName::ORGANIZATION);
        $indexName = $orgModel::getParentOrgIndexName();
        $columnName = 'parent_organization';
        // a chain a > b > c > d, and a loop e <-> f
        $ids = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $org = new $orgModel();
            $org->setValue(['organization_code' => 'iss02880_' . $name, 'organization_name' => 'iss02880 ' . $name]);
            $org->save();
            $ids[$name] = intval($org->id);
        }
        foreach ([['b', 'a'], ['c', 'b'], ['d', 'c'], ['e', 'f'], ['f', 'e']] as [$child, $parent]) {
            \DB::table(getDBTableName(SystemTableName::ORGANIZATION))->where('id', $ids[$child])
                ->update(['value->' . $columnName => strval($ids[$parent])]);
        }
        $this->assertNotEmpty($indexName);

        $userId = $this->userIds()[0];
        $pivot = \Exceedone\Exment\Model\CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        foreach (['b', 'e'] as $name) {
            \DB::table($pivot)->insert(['parent_id' => $ids[$name], 'child_id' => $userId]);
        }

        foreach ([JoinedOrgFilterType::ALL, JoinedOrgFilterType::ONLY_UPPER, JoinedOrgFilterType::ONLY_DOWNER, JoinedOrgFilterType::ONLY_JOIN] as $type) {
            System::clearRequestSession();
            $expected = $this->byTree($type, $userId);
            System::clearRequestSession();
            $this->assertSame($expected, AuthUserOrgHelper::getOrganizationIdsForQuery($type, $userId), "type $type");
        }
        System::clearRequestSession();
        $all = AuthUserOrgHelper::getOrganizationIdsForQuery(JoinedOrgFilterType::ALL, $userId);
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $this->assertContains($ids[$name], $all);
        }
    }

    public function testNoUser(): void
    {
        System::clearRequestSession();
        $this->assertSame([], AuthUserOrgHelper::getOrganizationIdsForQuery(JoinedOrgFilterType::ALL, 99999999));
    }
}
