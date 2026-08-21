<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Role group list: "user count" / "organization count" columns.
 *
 * role_group_user_organizations rows must be KEPT while the target user / organization
 * is only soft-deleted (they disappear only on permanent delete), but the counts shown
 * on the role group list must exclude soft-deleted targets.
 *
 * This test creates its own role group / users / organizations and rolls everything back,
 * so it does not depend on `exment:inittest` data.
 */
class RoleGroupUserCountTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // soft delete is the default; make sure the env does not force hard delete.
        \Config::set('exment.delete_force_custom_value', false);
    }

    // ------------------------------------------------------------------ //
    //  User count                                                         //
    // ------------------------------------------------------------------ //

    /**
     * 3 users assigned, 1 soft-deleted
     * -> list shows 2, but the assignment row of the soft-deleted user is kept.
     */
    public function testUserCountExcludesSoftDeletedUser(): void
    {
        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 3);

        $this->assertSame('3', $this->getUserCountOnList($roleGroup));

        $users[0]->delete();
        $this->assertTrue($users[0]->trashed(), 'user should be soft deleted');
        $this->assertNull(
            CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->find($users[0]->id),
            'soft deleted user should be hidden by default scope'
        );
        $this->assertNotNull(
            CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->withTrashed()->find($users[0]->id),
            'user should still exist as trashed'
        );

        // count on list excludes the soft-deleted user
        $this->assertSame('2', $this->getUserCountOnList($roleGroup));

        // ...but the assignment data is kept until the user is deleted permanently
        $this->assertSame(3, $this->countAssignments($roleGroup, SystemTableName::USER));
    }

    /**
     * soft-deleted user restored -> counted again (no data was lost).
     */
    public function testUserCountRestoredAfterUserRestore(): void
    {
        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 3);

        $users[0]->delete();
        $this->assertSame('2', $this->getUserCountOnList($roleGroup));

        CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->withTrashed()->find($users[0]->id)->restore();

        $this->assertSame('3', $this->getUserCountOnList($roleGroup));
        $this->assertSame(3, $this->countAssignments($roleGroup, SystemTableName::USER));
    }

    /**
     * permanently deleted user -> assignment row removed and not counted (existing behaviour).
     */
    public function testUserCountExcludesForceDeletedUser(): void
    {
        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 3);

        $users[0]->forceDelete();

        $this->assertSame('2', $this->getUserCountOnList($roleGroup));
        $this->assertSame(2, $this->countAssignments($roleGroup, SystemTableName::USER));
    }

    /**
     * Assignment row whose user id no longer exists at all (e.g. removed by import / direct SQL)
     * must not be counted either.
     */
    public function testUserCountExcludesMissingUser(): void
    {
        $roleGroup = $this->createRoleGroup();
        $this->assignUsers($roleGroup, 2);

        $missingId = (int) \DB::table(getDBTableName(SystemTableName::USER))->max('id') + 100000;
        RoleGroupUserOrganization::create([
            'role_group_id' => $roleGroup->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $missingId,
        ]);

        $this->assertSame(3, $this->countAssignments($roleGroup, SystemTableName::USER));
        $this->assertSame('2', $this->getUserCountOnList($roleGroup));
    }

    // ------------------------------------------------------------------ //
    //  Organization count                                                 //
    // ------------------------------------------------------------------ //

    /**
     * Same rule for organizations: soft-deleted organization is kept in the table but not counted.
     */
    public function testOrganizationCountExcludesSoftDeletedOrganization(): void
    {
        if (!System::organization_available()) {
            $this->markTestSkipped('organization is not available in this environment.');
        }

        $roleGroup = $this->createRoleGroup();
        $orgs = $this->assignOrganizations($roleGroup, 2);

        $this->assertSame('2', $this->getOrganizationCountOnList($roleGroup));

        $orgs[0]->delete();

        $this->assertSame('1', $this->getOrganizationCountOnList($roleGroup));
        $this->assertSame(2, $this->countAssignments($roleGroup, SystemTableName::ORGANIZATION));
    }

    /**
     * Soft-deleting a user must not affect the organization count and vice versa.
     */
    public function testUserAndOrganizationCountsAreIndependent(): void
    {
        if (!System::organization_available()) {
            $this->markTestSkipped('organization is not available in this environment.');
        }

        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 2);
        $orgs = $this->assignOrganizations($roleGroup, 2);

        $users[0]->delete();

        $this->assertSame('1', $this->getUserCountOnList($roleGroup));
        $this->assertSame('2', $this->getOrganizationCountOnList($roleGroup));

        $orgs[0]->delete();

        $this->assertSame('1', $this->getUserCountOnList($roleGroup));
        $this->assertSame('1', $this->getOrganizationCountOnList($roleGroup));
    }

    // ------------------------------------------------------------------ //
    //  Display / pagination / export regression                           //
    // ------------------------------------------------------------------ //

    /**
     * Role group without any assignment shows "0", not blank.
     */
    public function testCountShowsZeroWhenNoAssignment(): void
    {
        $roleGroup = $this->createRoleGroup();

        $this->assertSame('0', $this->getUserCountOnList($roleGroup));
        if (System::organization_available()) {
            $this->assertSame('0', $this->getOrganizationCountOnList($roleGroup));
        }
    }

    /**
     * Filtered list, 2nd page (ExtendedBuilder::paginate + withCount + where):
     * every row must still show the correct count (alive user only).
     */
    public function testListSecondPageWithFilterKeepsCorrectCount(): void
    {
        $prefix = 'rgpage_' . short_uuid() . '_';
        $alive = $this->createUser();
        $deleted = $this->createUser();

        $perPage = 20;
        for ($i = 1; $i <= $perPage + 1; $i++) {
            $roleGroup = RoleGroup::create([
                'role_group_name' => $prefix . sprintf('%02d', $i),
                'role_group_view_name' => $prefix . $i,
                'role_group_order' => $i,
            ]);
            $this->assign($roleGroup, SystemTableName::USER, $alive->id);
            $this->assign($roleGroup, SystemTableName::USER, $deleted->id);
        }
        $deleted->delete();

        $page1 = $this->getListCells(['role_group_name' => $prefix, 'per_page' => $perPage, 'page' => 1], exmtrans('role_group.users_count'));
        $page2 = $this->getListCells(['role_group_name' => $prefix, 'per_page' => $perPage, 'page' => 2], exmtrans('role_group.users_count'));

        $this->assertCount($perPage, $page1, 'page 1 must be full');
        $this->assertCount(1, $page2, 'page 2 must contain the remaining role group (was empty when bindings were shifted)');
        foreach (array_merge($page1, $page2) as $cell) {
            $this->assertStringContainsString($prefix, $cell['row']);
            $this->assertSame('1', $cell['value'], 'soft-deleted user must not be counted: ' . $cell['row']);
        }
    }

    /**
     * CSV / Excel export of the role group list uses the same grid model (chunk).
     * Both chunk branches (pagination enabled / disabled as in handleExportRequest) must work
     * with the count columns queued on the grid model.
     */
    public function testExportProvidersWorkWithCountColumns(): void
    {
        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 2);
        $users[0]->delete();

        foreach ([false, true] as $disablePagination) {
            $grid = $this->buildRoleGroupGrid();
            if ($disablePagination) {
                $grid->disablePagination();
            }

            $rows = (new \Exceedone\Exment\Services\DataImportExport\Providers\Export\RoleGroupProvider(['grid' => $grid]))->data();
            $names = collect($rows)->slice(2)->pluck(1)->all(); // skip 2 header rows, column 1 = role_group_name
            $this->assertContains($roleGroup->role_group_name, $names, 'role group must be exported (disablePagination=' . var_export($disablePagination, true) . ')');

            $grid = $this->buildRoleGroupGrid();
            if ($disablePagination) {
                $grid->disablePagination();
            }
            $rows = (new \Exceedone\Exment\Services\DataImportExport\Providers\Export\RoleGroupUserOrganizationProvider(['grid' => $grid]))->data();
            $targets = collect($rows)->slice(2)
                ->filter(fn ($row) => (int) $row[0] === (int) $roleGroup->id && $row[1] === SystemTableName::USER)
                ->pluck(2)->map(fn ($id) => (int) $id)->all();
            // export keeps the assignment data as-is (soft-deleted user included, as the row still exists)
            $this->assertEqualsCanonicalizing([$users[0]->id, $users[1]->id], $targets);
        }
    }

    // ------------------------------------------------------------------ //
    //  Model scope                                                        //
    // ------------------------------------------------------------------ //

    /**
     * RoleGroupUserOrganization::whereTargetNotDeleted() is the query used by the list.
     */
    public function testScopeWhereTargetNotDeleted(): void
    {
        $roleGroup = $this->createRoleGroup();
        $users = $this->assignUsers($roleGroup, 3);

        $query = function () use ($roleGroup) {
            return RoleGroupUserOrganization::where('role_group_id', $roleGroup->id)
                ->whereTargetNotDeleted(SystemTableName::USER);
        };

        $this->assertSame(3, $query()->count());

        $users[0]->delete();
        $this->assertSame(2, $query()->count());
        $this->assertEqualsCanonicalizing(
            [$users[1]->id, $users[2]->id],
            $query()->pluck('role_group_target_id')->map(fn ($id) => (int) $id)->all()
        );

        // the scope does not touch rows of other types
        if (System::organization_available()) {
            $this->assignOrganizations($roleGroup, 1);
            $this->assertSame(2, $query()->count());
            $this->assertSame(
                1,
                RoleGroupUserOrganization::where('role_group_id', $roleGroup->id)
                    ->whereTargetNotDeleted(SystemTableName::ORGANIZATION)->count()
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    protected function createRoleGroup(): RoleGroup
    {
        $name = 'rgcount_' . short_uuid();

        return RoleGroup::create([
            'role_group_name' => $name,
            'role_group_view_name' => $name,
            'role_group_order' => 0,
        ]);
    }

    protected function createUser(): CustomValue
    {
        $code = 'rgcount_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        return $user;
    }

    protected function createOrganization(): CustomValue
    {
        $code = 'rgcount_' . short_uuid();

        $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel();
        $org->setValue([
            'organization_code' => $code,
            'organization_name' => $code,
        ]);
        $org->saved_notify(false);
        $org->save();

        return $org;
    }

    /**
     * @return CustomValue[]
     */
    protected function assignUsers(RoleGroup $roleGroup, int $count): array
    {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->createUser();
            $this->assign($roleGroup, SystemTableName::USER, $user->id);
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @return CustomValue[]
     */
    protected function assignOrganizations(RoleGroup $roleGroup, int $count): array
    {
        $orgs = [];
        for ($i = 0; $i < $count; $i++) {
            $org = $this->createOrganization();
            $this->assign($roleGroup, SystemTableName::ORGANIZATION, $org->id);
            $orgs[] = $org;
        }

        return $orgs;
    }

    protected function assign(RoleGroup $roleGroup, string $type, $targetId): void
    {
        RoleGroupUserOrganization::create([
            'role_group_id' => $roleGroup->id,
            'role_group_user_org_type' => $type,
            'role_group_target_id' => $targetId,
        ]);
    }

    protected function countAssignments(RoleGroup $roleGroup, string $type): int
    {
        return RoleGroupUserOrganization::where('role_group_id', $roleGroup->id)
            ->where('role_group_user_org_type', $type)
            ->count();
    }

    protected function getUserCountOnList(RoleGroup $roleGroup): ?string
    {
        return $this->getListCell($roleGroup, exmtrans('role_group.users_count'));
    }

    protected function getOrganizationCountOnList(RoleGroup $roleGroup): ?string
    {
        return $this->getListCell($roleGroup, exmtrans('role_group.organizations_count'));
    }

    /**
     * GET the role group list (filtered to the given role group) and return the text of the cell
     * under the column whose header label is $headerLabel, in the row of that role group.
     */
    protected function getListCell(RoleGroup $roleGroup, string $headerLabel): ?string
    {
        $cells = collect($this->getListCells(['role_group_name' => $roleGroup->role_group_name], $headerLabel))
            ->filter(fn ($cell) => mb_strpos($cell['row'], $roleGroup->role_group_name) !== false)
            ->values();
        $this->assertCount(1, $cells, "role group {$roleGroup->role_group_name} should be listed exactly once");

        return $cells[0]['value'];
    }

    /**
     * GET the role group list with $query and return, for every body row,
     * ['row' => whole row text, 'value' => text of the cell under the column whose header label is $headerLabel].
     * (header and body cells are rendered from the same visible column list, so indexes align)
     *
     * @return array<int, array{row: string, value: string}>
     */
    protected function getListCells(array $query, string $headerLabel): array
    {
        $response = $this->get(admin_urls_query('role_group', $query));
        $response->assertStatus(200);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $response->getContent());
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // header index of the target column
        $headers = $xpath->query('//table//thead/tr/th');
        $this->assertNotFalse($headers);
        $columnIndex = null;
        foreach ($headers as $index => $th) {
            if (mb_strpos(trim($th->textContent), $headerLabel) !== false) {
                $columnIndex = $index;
                break;
            }
        }
        $this->assertNotNull($columnIndex, "column '{$headerLabel}' should exist on the list");

        $result = [];
        $rows = $xpath->query('//table//tbody/tr');
        $this->assertNotFalse($rows);
        foreach ($rows as $row) {
            $cells = $xpath->query('td', $row);
            $this->assertNotFalse($cells);
            if ($cells->length <= $columnIndex) {
                continue; // e.g. "no data" row
            }
            $result[] = [
                'row' => preg_replace('/\s+/', ' ', trim($row->textContent)),
                'value' => trim($cells->item($columnIndex)->textContent),
            ];
        }

        return $result;
    }

    /**
     * Build the grid exactly as RoleGroupController::index() does (grid() is protected).
     */
    protected function buildRoleGroupGrid(): \ExmentAdminCore\Admin\Grid
    {
        $controller = app(\Exceedone\Exment\Controllers\RoleGroupController::class);
        $method = new \ReflectionMethod($controller, 'grid');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }
}
