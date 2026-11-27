<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Actions\Import\CustomTableAction;
use Exceedone\Exment\Tests\Support\IsolatedStorageFixture;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * Parent (select-table target, import parent) x child (referencing, importing) in JSON / physical storage:
 *  - deleting a record that another record references is refused through the real delete callers (web
 *    single / bulk, web API single / bulk), also when the referencing record - or the child of the deleted
 *    record that another table references - is hidden from the deleting user;
 *  - the import resolves parent_id against the parent table (ParentItem::getImportValue through
 *    CustomTableAction::import), not against the importing table, with the scope of the importing user.
 *
 * ISOLATED test database only; NOT transactional (creates tables, runs DDL). Restore the snapshot before a run.
 */
class StorageReferenceIntegrityTest extends FeatureTestBase
{
    use IsolatedStorageFixture;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorageSetUp();
    }

    /** @return array<int, mixed> */
    private function build(string $parentMode, string $childMode): array
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $sfx = $parentMode[0] . $childMode[0] . substr(md5((string)microtime(true)), 0, 5);
        $p = $this->newTable("hyb_rip_$sfx", [
            ['label', ColumnType::TEXT, ['index_enabled' => '1']],
        ]);
        $c = $this->newTable("hyb_ric_$sfx", [
            ['name', ColumnType::TEXT, []],
            ['label', ColumnType::TEXT, []],
            ['ref', ColumnType::SELECT_TABLE, ['select_target_table' => $p->id]],
            ['refm', ColumnType::SELECT_TABLE, ['select_target_table' => $p->id, 'multiple_enabled' => '1']],
        ]);
        $d = $this->newTable("hyb_rid_$sfx", [
            ['cref', ColumnType::SELECT_TABLE, ['select_target_table' => $c->id]],
        ]);
        CustomRelation::create([
            'parent_custom_table_id' => $p->id,
            'child_custom_table_id' => $c->id,
            'relation_type' => RelationType::ONE_TO_MANY,
        ]);
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        $p = CustomTable::getEloquent($p->table_name);
        $c = CustomTable::getEloquent($c->table_name);
        $d = CustomTable::getEloquent($d->table_name);

        $save = function (CustomTable $t, array $value, ?array $parent = null) {
            $m = $t->getValueModel();
            $m->setValue($value);
            if ($parent) {
                $m->parent_id = $parent[1];
                $m->parent_type = $parent[0]->table_name;
            }
            $m->save();
            return (int)$m->id;
        };

        // parent ids and child ids overlap on purpose; the labels differ between the tables
        $pid = [];
        foreach (['p1' => 'L-1', 'p2' => 'L-2', 'p3' => 'shared', 'gone' => 'gone', 'free' => 'free', 'free2' => 'free2', 'p7' => 'L-7'] as $k => $label) {
            $pid[$k] = $save($p, ['label' => $label]);
        }
        $p->getValueModel()->withoutGlobalScopes()->find($pid['gone'])->delete();

        $cid = [];
        $cid['c1'] = $save($c, ['name' => 'c1', 'label' => 'shared', 'ref' => $pid['p3']]);
        $cid['c2'] = $save($c, ['name' => 'c2-hidden', 'label' => 'only-child', 'ref' => $pid['p1']]);
        $cid['c3'] = $save($c, ['name' => 'c3', 'label' => 'L-1']);
        $cid['c4'] = $save($c, ['name' => 'c4-hidden', 'label' => 'c4', 'refm' => [$pid['p2'], $pid['p3']]]);
        $cid['c5'] = $save($c, ['name' => 'c5-hidden-child', 'label' => 'c5'], [$p, $pid['p7']]);
        $did = $save($d, ['cref' => $cid['c5']]);

        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $rg = new RoleGroup();
        $rg->role_group_name = "hyb_ri_rg_$sfx";
        $rg->role_group_view_name = "hyb ri $sfx";
        $rg->save();
        foreach ([$p, $c] as $t) {
            RoleGroupPermission::create([
                'role_group_id' => $rg->id,
                'role_group_permission_type' => RoleType::TABLE,
                'role_group_target_id' => $t->id,
                'permissions' => [Permission::CUSTOM_VALUE_VIEW, Permission::CUSTOM_VALUE_EDIT],
            ]);
        }
        RoleGroupUserOrganization::create([
            'role_group_id' => $rg->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $user2->base_user_id,
        ]);
        foreach (['p1', 'p2', 'free', 'free2', 'p7'] as $k) {
            foreach ([Permission::CUSTOM_VALUE_VIEW, Permission::CUSTOM_VALUE_EDIT] as $perm) {
                CustomValueAuthoritable::create([
                    'parent_id' => $pid[$k], 'parent_type' => $p->table_name,
                    'authoritable_type' => $perm,
                    'authoritable_user_org_type' => SystemTableName::USER,
                    'authoritable_target_id' => $user2->base_user_id,
                ]);
            }
        }
        foreach (['c1', 'c3'] as $k) {
            CustomValueAuthoritable::create([
                'parent_id' => $cid[$k], 'parent_type' => $c->table_name,
                'authoritable_type' => Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => SystemTableName::USER,
                'authoritable_target_id' => $user2->base_user_id,
            ]);
        }
        System::clearCache();

        if ($parentMode === 'physical') {
            $this->toPhysicalRead($p);
        }
        if ($childMode === 'physical') {
            $this->toPhysicalRead($c);
        }
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
        $pid['late'] = $save(CustomTable::getEloquent($p->table_name), ['label' => 'late-parent']);
        return [CustomTable::getEloquent($p->table_name), CustomTable::getEloquent($c->table_name), $d, $pid, $cid, $did];
    }

    private function login(string $login): void
    {
        $this->be(LoginUser::find($login));
        System::clearRequestSession();
    }

    private function alive(CustomTable $t, int $id): bool
    {
        return DB::table(getDBTableName($t))->where('id', $id)->whereNull('deleted_at')->exists();
    }

    private function assertNoDetail($response, array $hiddenNames): void
    {
        $body = $response->getContent();
        foreach ($hiddenNames as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
    }

    private function importChild(CustomTable $c, CustomTable $p, array $labels): mixed
    {
        System::clearRequestSession();
        $rows = [
            ['id', 'suuid', 'parent_id', 'parent_type', 'value.name'],
            ['ID', 'suuid', 'parent', 'parent type', 'name'],
        ];
        foreach ($labels as $i => $label) {
            $rows[] = ['', '', $label, $p->table_name, "imp-$i"];
        }
        $datalist = [
            $c->table_name => $rows,
            Define::SETTING_SHEET_NAME => [
                ['table_name', 'column_name', 'target_column_name'],
                ['table', 'column', 'target'],
                [$c->table_name, 'parent_id', 'label'],
            ],
        ];
        return (new CustomTableAction(['custom_table' => $c]))->import($datalist);
    }

    private function importedParent(CustomTable $c, string $name): ?int
    {
        $row = $c->getValueModel()->withoutGlobalScopes()->get()->first(function ($m) use ($name) {
            return $m->getValue('name') === $name && is_null($m->deleted_at);
        });
        return $row ? (int)$row->parent_id : null;
    }

    private function isOk(mixed $res): bool
    {
        return is_array($res) && ($res['result'] ?? false) === true;
    }

    /** @return array<string, array<int, string>> */
    public static function combinations(): array
    {
        return [
            'json/json' => ['json', 'json'],
            'json/physical' => ['json', 'physical'],
            'physical/json' => ['physical', 'json'],
            'physical/physical' => ['physical', 'physical'],
        ];
    }

    /** One combination per test: the routed controller keeps the table of the first request within a process. */
    #[\PHPUnit\Framework\Attributes\DataProvider('combinations')]
    public function testCombination(string $pm, string $cm): void
    {
        $this->runCombination($pm, $cm);
    }

    private function runCombination(string $pm, string $cm): void
    {
        [$p, $c, $d, $pid, $cid, $did] = $this->build($pm, $cm);
        $tag = "$pm/$cm";
        $hidden = ['c2-hidden', 'c4-hidden', 'c5-hidden-child'];
        $refMsg = exmtrans('custom_value.help.reference_error');
        $pt = $p->table_name;

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNull($c->getValueModel($cid['c2']), "$tag: c2 hidden from user2");

        $r = $this->delete(admin_urls('data', $pt, $pid['p1']), [], static::AJAX);
        $r->assertOk()->assertJson(['status' => false, 'message' => $refMsg]);
        $this->assertNoDetail($r, $hidden);
        $this->assertTrue($this->alive($p, $pid['p1']), "$tag: p1 kept");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('data', $pt, $pid['p2']), [], static::AJAX)
            ->assertOk()->assertJson(['status' => false, 'message' => $refMsg]);
        $this->assertTrue($this->alive($p, $pid['p2']), "$tag: p2 kept (multi value)");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('data', $pt, $pid['free'] . ',' . $pid['p1']), [], static::AJAX)
            ->assertOk()->assertJson(['status' => false, 'message' => $refMsg]);
        $this->assertTrue($this->alive($p, $pid['free']) && $this->alive($p, $pid['p1']), "$tag: bulk kept both");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $r = $this->delete(admin_urls('data', $pt, $pid['p7']), [], static::AJAX);
        $r->assertOk()->assertJson(['status' => false, 'message' => $refMsg]);
        $this->assertNoDetail($r, $hidden);
        $this->assertTrue($this->alive($p, $pid['p7']) && $this->alive($c, $cid['c5']), "$tag: p7 and child kept");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $r = $this->delete(admin_urls('webapi', 'data', $pt, $pid['p1']), [], static::AJAX);
        $r->assertStatus(400);
        $this->assertNoDetail($r, $hidden);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $r = $this->delete(admin_urls('webapi', 'data', $pt, $pid['free'] . ',' . $pid['p2']), [], static::AJAX);
        $r->assertStatus(400);
        $this->assertNoDetail($r, $hidden);
        $this->assertTrue($this->alive($p, $pid['free']) && $this->alive($p, $pid['p2']), "$tag: API bulk kept both");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('data', $pt, $pid['free2']), [], static::AJAX)->assertOk()->assertJson(['status' => true]);
        $this->assertFalse($this->alive($p, $pid['free2']), "$tag: unreferenced row deleted");

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->delete(admin_urls('data', $pt, $pid['p3']), [], static::AJAX)
            ->assertOk()->assertJson(['status' => false, 'message' => $refMsg]);
        $this->assertTrue($this->alive($p, $pid['p3']), "$tag: p3 kept");

        // import: parent_id by label resolved against the PARENT table
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $res = $this->importChild($c, $p, ['L-1', 'shared', 'late-parent']);
        $this->assertTrue($this->isOk($res), "$tag: import ok");
        $this->assertSame($pid['p1'], $this->importedParent($c, 'imp-0'), "$tag: L-1 -> p1");
        $this->assertSame($pid['p3'], $this->importedParent($c, 'imp-1'), "$tag: shared -> p3");
        $this->assertSame($pid['late'], $this->importedParent($c, 'imp-2'), "$tag: late parent");

        foreach (['only-child', 'gone', 'nothing'] as $miss) {
            $res = $this->importChild($c, $p, [$miss]);
            $this->assertFalse($this->isOk($res), "$tag: '$miss' refused");
        }

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertFalse($this->isOk($this->importChild($c, $p, ['shared'])), "$tag: hidden parent not found");
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertTrue($this->isOk($this->importChild($c, $p, ['L-1'])), "$tag: visible parent ok");
    }
}
