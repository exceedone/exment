<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Actions\Import\CustomTableAction;
use Exceedone\Exment\Tests\Support\IsolatedStorageFixture;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * Contract of parent_type in the import of a child table (CustomTableAction::import).
 *
 * A child table has at most ONE configured parent table (one-to-many relation; several parents are modelled with
 * select-table / n:n pivots, which do not use parent_type). validateValue() therefore accepts only
 * `parent_type` = that parent table (or empty), and none at all for a table without a parent. Rows are validated before
 * anything is saved, so a refused file changes no data.
 *
 * ISOLATED test database only; NOT transactional. Restore the snapshot before a run.
 */
class StorageImportParentTypeTest extends FeatureTestBase
{
    use IsolatedStorageFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorageSetUp();
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

    private function login(string $login): void
    {
        $this->be(LoginUser::find($login));
        System::clearRequestSession();
    }

    private function save(CustomTable $t, array $value, ?array $parent = null): int
    {
        $m = $t->getValueModel();
        $m->setValue($value);
        if ($parent) {
            $m->parent_id = $parent[1];
            $m->parent_type = $parent[0]->table_name;
        }
        $m->save();
        return (int)$m->id;
    }

    /**
     * @param array<int, array{0: mixed, 1: mixed, 2: string, 3?: mixed}> $rows [id, parent_id, name, parent_type]
     * @param string|null $key target column of the parent_id lookup (null = parent_id is the numeric id)
     */
    private function import(CustomTable $c, array $rows, ?string $key): mixed
    {
        System::clearRequestSession();
        $sheet = [
            ['id', 'suuid', 'parent_id', 'parent_type', 'value.name'],
            ['ID', 'suuid', 'parent', 'parent type', 'name'],
        ];
        foreach ($rows as $r) {
            $sheet[] = [$r[0], '', $r[1], $r[3] ?? null, $r[2]];
        }
        $datalist = [$c->table_name => $sheet];
        if ($key) {
            $datalist[Define::SETTING_SHEET_NAME] = [
                ['table_name', 'column_name', 'target_column_name'],
                ['table', 'column', 'target'],
                [$c->table_name, 'parent_id', $key],
            ];
        }
        return (new CustomTableAction(['custom_table' => $c]))->import($datalist);
    }

    private function ok(mixed $res): bool
    {
        return is_array($res) && ($res['result'] ?? false) === true;
    }

    private function liveRows(CustomTable $c): int
    {
        return DB::table(getDBTableName($c))->whereNull('deleted_at')->count();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('combinations')]
    public function testParentTypeMustBeTheConfiguredParent(string $pm, string $cm): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $sfx = $pm[0] . $cm[0] . substr(md5((string)microtime(true)), 0, 5);
        $p = $this->newTable("hyb_pt_p_$sfx", [['label', ColumnType::TEXT, ['index_enabled' => '1']]]);
        $u = $this->newTable("hyb_pt_u_$sfx", [['label', ColumnType::TEXT, []]]);
        $c = $this->newTable("hyb_pt_c_$sfx", [['name', ColumnType::TEXT, []]]);
        CustomRelation::create([
            'parent_custom_table_id' => $p->id,
            'child_custom_table_id' => $c->id,
            'relation_type' => RelationType::ONE_TO_MANY,
        ]);
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        $p = CustomTable::getEloquent($p->table_name);
        $u = CustomTable::getEloquent($u->table_name);
        $c = CustomTable::getEloquent($c->table_name);

        // overlapping ids and labels in the parent, the unrelated table and the child
        $pid = $this->save($p, ['label' => 'in-parent']);
        $pid2 = $this->save($p, ['label' => 'shared']);
        $uid = $this->save($u, ['label' => 'in-unrelated']);
        $this->save($u, ['label' => 'shared']);
        $cid = $this->save($c, ['name' => 'existing'], [$p, $pid]);
        $cid2 = $this->save($c, ['name' => 'self-label'], [$p, $pid2]);

        if ($pm === 'physical') {
            $this->toPhysicalRead($p);
        }
        if ($cm === 'physical') {
            $this->toPhysicalRead($c);
        }
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $tag = "$pm/$cm";

        // valid parent_type: relationship created
        $before = $this->liveRows($c);
        $this->assertTrue($this->ok($this->import($c, [['', 'in-parent', 'n-valid', $p->table_name]], 'label')), "$tag: valid");
        $this->assertSame($before + 1, $this->liveRows($c));
        $this->assertSame([(string)$pid, $p->table_name], array_map('strval', $this->parentOf($c, 'n-valid')), "$tag: stored");

        // refused inputs: nothing may be written (rows of the file are validated before any save)
        $cases = [
            'unrelated table, label of that table' => [['', 'in-unrelated', 'n-unrel', $u->table_name], 'label'],
            'unrelated table, label of the parent' => [['', 'in-parent', 'n-unrel2', $u->table_name], 'label'],
            'unrelated table, numeric id' => [['', $uid, 'n-unrel3', $u->table_name], null],
            'importing table itself' => [['', 'self-label', 'n-self', $c->table_name], 'label'],
            'nonexistent table' => [['', 'in-parent', 'n-none', 'no_such_table_xyz'], 'label'],
            'numeric id, unrelated type' => [['', $pid, 'n-numtype', $u->table_name], null],
        ];
        foreach ($cases as $name => [$row, $key]) {
            $before = $this->liveRows($c);
            $res = $this->import($c, [$row], $key);
            $this->assertFalse($this->ok($res), "$tag: $name refused");
            $this->assertSame($before, $this->liveRows($c), "$tag: $name wrote nothing");
            $this->assertNull($this->parentOf($c, $row[2]), "$tag: $name no row");
        }

        // a refused row in a file with a valid row: the valid one is not saved either
        $before = $this->liveRows($c);
        $res = $this->import($c, [
            ['', 'in-parent', 'n-mix-ok', $p->table_name],
            ['', 'in-unrelated', 'n-mix-bad', $u->table_name],
        ], 'label');
        $this->assertFalse($this->ok($res), "$tag: mixed file refused");
        $this->assertSame($before, $this->liveRows($c), "$tag: mixed file wrote nothing");

        // parent_type missing / empty with a label: nothing can be looked up, the row is refused (no crash, no write)
        foreach ([null, ''] as $i => $type) {
            $before = $this->liveRows($c);
            $res = $this->import($c, [['', 'in-parent', "n-notype-$i", $type]], 'label');
            $this->assertFalse($this->ok($res), "$tag: label without parent_type refused");
            $this->assertSame($before, $this->liveRows($c), "$tag: label without parent_type wrote nothing");
        }
        // Characterized, not changed: a numeric parent_id without parent_type is accepted and stored without a type
        // (no relationship is created: CustomValue::hasParent() needs both).
        $res = $this->import($c, [['', $pid, 'n-notype-id', null]], null);
        $this->assertTrue($this->ok($res), "$tag: numeric id without type accepted (existing behavior)");
        $this->assertSame([(string)$pid, ''], array_map('strval', $this->parentOf($c, 'n-notype-id')));

        // restricted user without rights on the child table: refused, nothing written
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $before = $this->liveRows($c);
        $res = $this->import($c, [['', 'in-parent', 'n-user2', $p->table_name]], 'label');
        $this->assertFalse($this->ok($res), "$tag: user2 refused");
        $this->assertSame($before, $this->liveRows($c), "$tag: user2 wrote nothing");
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        // existing row: change to the unrelated type is refused and keeps the relation; clearing is characterized
        $res = $this->import($c, [[$cid, $pid2, 'existing', $u->table_name]], null);
        $this->assertFalse($this->ok($res), "$tag: update to unrelated type refused");
        $this->assertSame([(string)$pid, $p->table_name], array_map('strval', $this->parentOf($c, 'existing')), "$tag: relation kept");

        $res = $this->import($c, [[$cid2, $pid, 'self-label', $p->table_name]], null);
        $this->assertTrue($this->ok($res), "$tag: change to another valid parent");
        $this->assertSame([(string)$pid, $p->table_name], array_map('strval', $this->parentOf($c, 'self-label')));

        // clearing is an explicit empty parent_id and parent_type on an existing row: allowed, the relation is removed
        $res = $this->import($c, [[$cid2, '', 'self-label', '']], null);
        $this->assertTrue($this->ok($res), "$tag: clearing accepted");
        $this->assertSame([null, null], $this->parentOf($c, 'self-label'));
    }

    /** @return array{0: mixed, 1: mixed}|null */
    private function parentOf(CustomTable $c, string $name): ?array
    {
        foreach ($c->getValueModel()->withoutGlobalScopes()->get() as $m) {
            if ($m->getValue('name') === $name && is_null($m->deleted_at)) {
                return [$m->parent_id, $m->parent_type];
            }
        }
        return null;
    }
}
