<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;

/**
 * The organizations over and under an organization are read from the parents of all
 * organizations, once for the request (GitHub #1346), and are the ones the relations give.
 */
class OrganizationIdsTest extends UnitTestBase
{
    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterTypes(): array
    {
        return [
            'all' => JoinedOrgFilterType::ALL,
            'upper' => JoinedOrgFilterType::ONLY_UPPER,
            'downer' => JoinedOrgFilterType::ONLY_DOWNER,
            'join' => JoinedOrgFilterType::ONLY_JOIN,
        ];
    }

    /**
     * @return void
     */
    public function testTheIdsAreTheOnesOfTheRelations()
    {
        $organizations = getModelName(SystemTableName::ORGANIZATION)::query()->orderBy('id')->get();
        $this->assertGreaterThan(1, $organizations->count(), 'the test data has organizations');

        $withFamily = 0;
        foreach ($organizations as $organization) {
            foreach ($this->filterTypes() as $name => $filterType) {
                // as before: the children and the parents by the relations, and the organization
                $expected = collect();
                if (JoinedOrgFilterType::isGetUpper($filterType)) {
                    $expected = $expected->merge($organization->all_children_organizations());
                }
                if (JoinedOrgFilterType::isGetDowner($filterType)) {
                    $expected = $expected->merge($organization->all_parent_organizations());
                }
                $expected->push($organization);
                $expected = $expected->map(function ($org) {
                    return $org->id;
                })->sort()->unique()->values()->toArray();
                if (count($expected) > 1) {
                    $withFamily++;
                }

                System::clearRequestSession();
                $this->assertSame(
                    $expected,
                    array_values($organization->getOrganizationIdsForQuery($filterType)),
                    "organization {$organization->id}, filter {$name}"
                );
            }
        }
        $this->assertGreaterThan(0, $withFamily, 'the test data has an organization with a parent or a child');
    }

    /**
     * @return void
     */
    public function testAllOrganizationsCostOneQuery()
    {
        $organizations = getModelName(SystemTableName::ORGANIZATION)::query()->orderBy('id')->get();
        $this->assertGreaterThan(1, $organizations->count());
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        // one organization, with nothing kept (the definitions of the table are read too)
        System::clearRequestSession();
        $organizations->first()->getOrganizationIdsForQuery(JoinedOrgFilterType::ALL);
        $forOne = $queries;

        // all of them, with nothing kept: not more queries than for one
        System::clearRequestSession();
        $queries = 0;
        foreach ($organizations as $organization) {
            $organization->getOrganizationIdsForQuery(JoinedOrgFilterType::ALL);
        }
        $forAll = $queries;
        $this->assertLessThanOrEqual($forOne, $forAll, 'not a query for each organization');
    }

    /**
     * @return void
     */
    public function testAMovedOrganizationIsReadAgain()
    {
        $model = getModelName(SystemTableName::ORGANIZATION);
        $child = $model::query()->whereNotNull('value->parent_organization')->orderBy('id')->first();
        $this->assertNotNull($child, 'the test data has an organization with a parent');
        $parentId = intval(array_get($child->value, 'parent_organization'));
        $parent = $model::find($parentId);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            System::clearRequestSession();
            $this->assertContains($child->id, $parent->getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_UPPER));

            $child->setValue('parent_organization', null);
            $child->save();
            System::clearCache();

            $this->assertNotContains($child->id, $parent->getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_UPPER));
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
            System::clearCache();
        }
    }
}
