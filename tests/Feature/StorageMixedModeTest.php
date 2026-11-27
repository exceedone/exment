<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Search\SearchService;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\CutoverConfirmation;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\StorageBackfill;
use Exceedone\Exment\Services\Storage\StorageL5Report;
use Exceedone\Exment\Services\Storage\StorageRunRepository;
use Exceedone\Exment\Services\Storage\StorageTransitions;
use Exceedone\Exment\Services\Storage\StorageVerifier;
use Exceedone\Exment\Services\Storage\TableStorage;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * Parent (select-table target) x child (select-table column) in JSON / physical storage: the four
 * combinations must give the same rows, order and display. ISOLATED test database only; NOT
 * transactional (creates tables, runs DDL), like StorageStageBIntegrationTest.
 */
class StorageMixedModeTest extends FeatureTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $c = config('database.connections.' . config('database.default'));
        $legacy = (int)$c['port'] === 3408 && $c['database'] === 'hyb_p1_test';
        if (!($legacy || \Exceedone\Exment\Tests\Support\IsolatedConversionExecutionProvider::guardsPass($this->app)) || !app()->environment('testing')) {
            $this->markTestSkipped('isolated database only');
        }
        $this->app->instance(ConversionAvailability::class, new class () implements ConversionAvailability {
            public function isAvailable(): bool
            {
                return true;
            }

            public function missing(): array
            {
                return [];
            }
        });
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    private function attestation(): DrainAttestation
    {
        return new DrainAttestation('integration-test', new \DateTimeImmutable(), array_fill_keys(DrainAttestation::REQUIRED, true), 'isolated test database');
    }

    private function newTable(string $name, array $columns): CustomTable
    {
        $table = CustomTable::create([
            'table_name' => $name,
            'table_view_name' => $name,
            'options' => ['search_enabled' => 1],
        ]);
        System::clearRequestSession();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([$name => ltrim(getModelName($table, true), '\\')]);
        foreach ($columns as [$column_name, $type, $options]) {
            CustomColumn::create([
                'custom_table_id' => $table->id,
                'column_name' => $column_name,
                'column_view_name' => $column_name,
                'column_type' => $type,
                'options' => $options,
            ]);
        }
        System::clearCache();
        return CustomTable::getEloquent($name);
    }

    private function toPhysicalRead(CustomTable $table): void
    {
        $transitions = app(StorageTransitions::class);
        app(PhysicalSchemaManager::class)->provision($table);
        $transitions->startDualWrite($table);
        sleep(1);
        $transitions->startBackfill($table, $this->attestation());
        $status = app(StorageBackfill::class)->run($table, 500, 100);
        $this->assertSame(StorageRunRepository::STATUS_COMPLETED, $status['status'], json_encode($status));
        $transitions->startVerification($table);
        $verify = app(StorageVerifier::class)->run($table, 500, 100);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify['result'], json_encode($verify));
        $transitions->awaitConfirmation($table);
        sleep(1);
        $run = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, TableStorage::fresh($table)->generation());
        $transitions->cutover($table, new CutoverConfirmation((int)\Exment::getUserId(), (int)$run->id, (int)$run->generation,
            StorageL5Report::fromRun($run)->version(), new \DateTimeImmutable()), $this->attestation());
        System::clearCache();
        $this->assertSame(StorageState::PHYSICAL_READ, TableStorage::fresh($table)->state());
    }

    /** @return array{0: CustomTable, 1: CustomTable, 2: array<string, int>, 3: array<string, int>} */
    private function build(string $parentMode, string $childMode): array
    {
        $suffix = $parentMode[0] . $childMode[0] . substr(md5((string)microtime(true)), 0, 5);
        $p = $this->newTable("hyb_mxp_$suffix", [
            ['label', ColumnType::TEXT, ['index_enabled' => '1']],
            ['num', ColumnType::INTEGER, ['index_enabled' => '1']],
            ['memo', ColumnType::TEXT, []],
        ]);
        $c = $this->newTable("hyb_mxc_$suffix", [
            ['name', ColumnType::TEXT, []],
            ['ref', ColumnType::SELECT_TABLE, ['select_target_table' => $p->id, 'index_enabled' => '1']],
            ['refm', ColumnType::SELECT_TABLE, ['select_target_table' => $p->id, 'multiple_enabled' => '1']],
        ]);

        // labels differ only by case: JSON-path (binary) and typed-column (table collation) orders can differ
        $pids = [];
        foreach (['p1' => ['Beta', '20'], 'p2' => ['alpha', '10'], 'p3' => ['Gamma', '30']] as $key => [$label, $num]) {
            $m = $p->getValueModel();
            $m->setValue(['label' => $label, 'num' => $num, 'memo' => $label])->save();
            $pids[$key] = (int)$m->id;
        }
        $cids = [];
        $rows = [
            'c1' => ['ref' => $pids['p1'], 'refm' => [$pids['p1'], $pids['p3']]],
            'c2' => ['ref' => $pids['p2'], 'refm' => [$pids['p2']]],
            'c3' => ['ref' => $pids['p3']],
            'c4' => ['ref' => $pids['p2']],
            'c5' => [],
            'c6' => [],
        ];
        foreach ($rows as $key => $value) {
            $m = $c->getValueModel();
            $m->setValue($value + ['name' => $key])->save();
            $cids[$key] = (int)$m->id;
        }
        // values only a bypass path can store (invalid / odd references); the rest of each value is as saved
        $raw = [
            'c4' => ['name' => 'c4', 'ref' => $pids['p2'], 'refm' => []],
            'c5' => ['name' => 'c5', 'refm' => [$pids['p1'] . '1', 'abc', '']],
            'c6' => ['name' => 'c6', 'ref' => 'abc', 'refm' => ['abc', $pids['p2']]],
        ];
        foreach ($raw as $key => $value) {
            DB::table(getDBTableName($c))->where('id', $cids[$key])->update(['value' => json_encode($value)]);
        }

        // restricted user: user2 may view only own rows (c1, c3, c4, c6) of both tables
        $roleGroup = new \Exceedone\Exment\Model\RoleGroup();
        $roleGroup->role_group_name = 'hyb_mx_rg_' . $suffix;
        $roleGroup->role_group_view_name = 'hyb mx ' . $suffix;
        $roleGroup->save();
        foreach ([$p, $c] as $t) {
            \Exceedone\Exment\Model\RoleGroupPermission::create([
                'role_group_id' => $roleGroup->id,
                'role_group_permission_type' => \Exceedone\Exment\Enums\RoleType::TABLE,
                'role_group_target_id' => $t->id,
                'permissions' => ['custom_value_view'],
            ]);
        }
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        \Exceedone\Exment\Model\RoleGroupUserOrganization::create([
            'role_group_id' => $roleGroup->id,
            'role_group_user_org_type' => \Exceedone\Exment\Enums\SystemTableName::USER,
            'role_group_target_id' => $user2->base_user_id,
        ]);
        foreach (['c1', 'c3', 'c4', 'c6'] as $own) {
            \Exceedone\Exment\Model\CustomValueAuthoritable::create([
                'parent_id' => $cids[$own], 'parent_type' => $c->table_name,
                'authoritable_type' => \Exceedone\Exment\Enums\Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => \Exceedone\Exment\Enums\SystemTableName::USER,
                'authoritable_target_id' => $user2->base_user_id,
            ]);
        }
        foreach ($pids as $pid) {
            \Exceedone\Exment\Model\CustomValueAuthoritable::create([
                'parent_id' => $pid, 'parent_type' => $p->table_name,
                'authoritable_type' => \Exceedone\Exment\Enums\Permission::CUSTOM_VALUE_VIEW,
                'authoritable_user_org_type' => \Exceedone\Exment\Enums\SystemTableName::USER,
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
        return [$p, $c, $pids, $cids];
    }

    private function safe(\Closure $call)
    {
        try {
            return $call();
        } catch (\Throwable $ex) {
            $where = '';
            foreach ($ex->getTrace() as $t) {
                if (isset($t['file']) && str_contains($t['file'], 'exment' . DIRECTORY_SEPARATOR . 'src')) {
                    $where .= ' @' . basename($t['file']) . ':' . ($t['line'] ?? '?');
                    if (substr_count($where, '@') >= 3) {
                        break;
                    }
                }
            }
            return 'ERROR ' . get_class($ex) . ': ' . substr(preg_replace('/\s+/', ' ', $ex->getMessage()), 0, 110) . $where;
        }
    }

    /** @return array<string, mixed> everything observed through the production paths, keyed by row key not by id */
    private function observe(CustomTable $p, CustomTable $c, array $pids, array $cids): array
    {
        System::clearCache();
        System::clearRequestSession();
        $p = CustomTable::getEloquent($p->id);
        $c = CustomTable::getEloquent($c->id);
        $ref = CustomColumn::getEloquent('ref', $c);
        $refm = CustomColumn::getEloquent('refm', $c);
        $cKey = array_flip($cids);
        $pKey = array_flip($pids);
        $dbC = getDBTableName($c);

        $keys = function ($query) use ($cKey, $dbC) {
            $ids = array_map('intval', $query->pluck("$dbC.id")->all());
            return array_map(function ($id) use ($cKey) {
                return $cKey[$id] ?? "#$id";
            }, $ids);
        };
        $sorted = function (array $a) {
            sort($a);
            return $a;
        };

        $out = [];
        $out['filter_single'] = $this->safe(function () use ($c, $ref, $pids, $keys, $sorted) {
            $q = $c->getValueQuery();
            RelationTable::setQuerySelectTable($q, $ref, $pids['p2']);
            return $sorted($keys($q));
        });
        $out['filter_in'] = $this->safe(function () use ($c, $ref, $pids, $keys, $sorted) {
            $q = $c->getValueQuery();
            RelationTable::setQuerySelectTable($q, $ref, [$pids['p1'], $pids['p3']]);
            return $sorted($keys($q));
        });
        $out['filter_multi'] = $this->safe(function () use ($c, $refm, $pids, $keys, $sorted) {
            $q = $c->getValueQuery();
            $q->whereInArrayString(\Exceedone\Exment\Model\ColumnQueryKey::index($refm)->expression(), [(string)$pids['p3']]);
            return $sorted($keys($q));
        });
        $out['join_order_parent_label'] = $this->safe(function () use ($c, $p, $keys) {
            $q = (new SearchService($c))->orderBy(CustomColumn::getEloquent('label', $p), 'asc')->query();
            return $keys($q);
        });
        $out['join_order_parent_num'] = $this->safe(function () use ($c, $p, $keys) {
            $q = (new SearchService($c))->orderBy(CustomColumn::getEloquent('num', $p), 'desc')->query();
            return $keys($q);
        });
        $out['display'] = $this->safe(function () use ($c, $cids, $pKey) {
            $res = [];
            foreach ($cids as $key => $id) {
                System::clearRequestSession();
                $m = $c->getValueModel()->find($id);
                $v = $m->getValue('ref');
                $raw = $m->value['ref'] ?? null;
                $res[$key] = [
                    'getValue' => is_object($v) ? ($pKey[(int)$v->id] ?? "#{$v->id}") : $v,
                    'raw' => is_scalar($raw) ? (string)$raw : $raw,
                ];
            }
            return $res;
        });
        $out['parent_lookup_by_label'] = $this->safe(function () use ($p, $pKey) {
            System::clearRequestSession();
            $q = $p->getValueQuery()->where('value->label', 'alpha');
            return array_map(function ($id) use ($pKey) {
                return $pKey[(int)$id] ?? "#$id";
            }, $q->pluck('id')->all());
        });
        $out['join_order_parent_memo_unindexed'] = $this->safe(function () use ($c, $p, $keys) {
            $q = (new SearchService($c))->orderBy(CustomColumn::getEloquent('memo', $p), 'asc')->query();
            return $keys($q);
        });
        $join = function (CustomColumn $col, bool $left) use ($c, $p, $cKey) {
            $rt = new RelationTable();
            $rt->searchType = SearchType::SELECT_TABLE;
            $db = getDBTableName($c);
            $q = $c->getValueQuery()->select("$db.*");
            $rt->setParentJoinSelectTable($q, $p, $col, $left);
            $ids = array_map(fn ($id) => $cKey[(int)$id] ?? "#$id", $q->pluck("$db.id")->all());
            sort($ids);
            return $ids;
        };
        $out['join_multi_rows'] = $this->safe(fn () => $join($refm, true));
        $out['inner_join_multi_rows'] = $this->safe(fn () => $join($refm, false));
        $out['join_single_rows'] = $this->safe(fn () => $join($ref, true));
        $out['inner_join_single_rows'] = $this->safe(fn () => $join($ref, false));
        $out['join_single_parent_values'] = $this->safe(function () use ($c, $p, $ref, $cKey, $pKey) {
            $rt = new RelationTable();
            $rt->searchType = SearchType::SELECT_TABLE;
            $db = getDBTableName($c);
            $q = $c->getValueQuery()->select("$db.id", $rt->tableUniqueName . '.id as pid');
            $rt->setParentJoinSelectTable($q, $p, $ref, true);
            $res = [];
            foreach ($q->get() as $r) {
                $res[] = ($cKey[(int)$r->id] ?? "#{$r->id}") . '>' . (is_null($r->pid) ? '-' : ($pKey[(int)$r->pid] ?? "#{$r->pid}"));
            }
            sort($res);
            return $res;
        });
        $out['parent_lookup_memo'] = $this->safe(function () use ($p, $pKey) {
            System::clearRequestSession();
            return array_map(fn ($id) => $pKey[(int)$id] ?? "#$id", $p->getValueQuery()->where('value->memo', 'alpha')->pluck('id')->all());
        });
        $out['parent_lookup_memo_upper'] = $this->safe(function () use ($p, $pKey) {
            System::clearRequestSession();
            return array_map(fn ($id) => $pKey[(int)$id] ?? "#$id", $p->getValueQuery()->where('value->memo', 'ALPHA')->pluck('id')->all());
        });
        $out['parent_value_json'] = $this->safe(function () use ($p, $pids) {
            System::clearRequestSession();
            $m = $p->getValueModel()->find($pids['p2']);
            return ['value' => $m->value, 'label' => $m->getValue('label'), 'num' => $m->getValue('num'), 'memo' => $m->getValue('memo')];
        });
        $out['child_value_json'] = $this->safe(function () use ($c, $cids) {
            System::clearRequestSession();
            $m = $c->getValueModel()->find($cids['c1']);
            return ['refm' => $m->value['refm'] ?? null, 'ref' => $m->value['ref'] ?? null];
        });
        $out['view_sort_label'] = $this->safe(function () use ($c, $p, $ref, $keys) {
            return $this->viewSort($c, $p, $ref, 'label', $keys);
        });
        $out['view_sort_memo'] = $this->safe(function () use ($c, $p, $ref, $keys) {
            return $this->viewSort($c, $p, $ref, 'memo', $keys);
        });
        $out['grid_header_sort_label'] = $this->safe(function () use ($c, $p, $ref, $keys) {
            return $this->gridSort($c, $p, $ref, 'label', $keys);
        });
        $out['grid_header_sort_memo'] = $this->safe(function () use ($c, $p, $ref, $keys) {
            return $this->gridSort($c, $p, $ref, 'memo', $keys);
        });
        $out['restricted_user2'] = $this->safe(function () use ($c, $p, $ref, $pids, $keys, $sorted, $cids, $pKey) {
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
            try {
                System::clearRequestSession();
                $res = [];
                $res['visible'] = $sorted($keys($c->getValueQuery()));
                $q = $c->getValueQuery();
                RelationTable::setQuerySelectTable($q, $ref, $pids['p2']);
                $res['filter_single'] = $sorted($keys($q));
                $res['order_label'] = $keys((new SearchService($c))->orderBy(CustomColumn::getEloquent('label', $p), 'asc')->query());
                $res['order_memo_json'] = $keys((new SearchService($c))->orderBy(CustomColumn::getEloquent('memo', $p), 'desc')->query());
                $res['view_sort_label'] = $this->viewSort($c, $p, $ref, 'label', $keys)['keys'];
                $res['display'] = [];
                foreach (['c1', 'c3', 'c4', 'c6'] as $k) {
                    System::clearRequestSession();
                    $row = $c->getValueModel()->find($cids[$k]);
                    $v = $row ? $row->getValue('ref') : '__hidden__';
                    $res['display'][$k] = is_object($v) ? ($pKey[(int)$v->id] ?? "#{$v->id}") : $v;
                }
                $res['hidden_row_find'] = $c->getValueModel()->find($cids['c2']) === null;
                return $res;
            } finally {
                $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
                System::clearRequestSession();
            }
        });
        return $out;
    }

    private function viewSort(CustomTable $c, CustomTable $p, CustomColumn $ref, string $column, \Closure $keys): array
    {
        $view = \Exceedone\Exment\Model\CustomView::create([
            'custom_table_id' => $c->id,
            'view_view_name' => 'mx sort ' . $column,
            'view_type' => \Exceedone\Exment\Enums\ViewType::SYSTEM,
            'view_kind_type' => \Exceedone\Exment\Enums\ViewKindType::DEFAULT,
        ]);
        $sort = new \Exceedone\Exment\Model\CustomViewSort();
        $sort->custom_view_id = $view->id;
        $sort->view_column_type = \Exceedone\Exment\Enums\ConditionType::COLUMN;
        $sort->view_column_table_id = $p->id;
        $sort->view_column_target_id = CustomColumn::getEloquent($column, $p)->id;
        $sort->sort = 1;
        $sort->priority = 1;
        $sort->setOption('view_pivot_table_id', $c->id);
        $sort->setOption('view_pivot_column_id', $ref->id);
        $sort->save();
        System::clearRequestSession();
        $view = \Exceedone\Exment\Model\CustomView::find($view->id);
        $query = $c->getValueQuery();
        $view->filterSortModel($query);
        $sql = $query->toSql();
        return ['keys' => $keys($query), 'has_order' => stripos($sql, 'order by') !== false, 'qualified' => (bool)preg_match('/order by [`\[\"][^`\]\"]+[`\]\"]\./i', $sql)];
    }

    private function gridSort(CustomTable $c, CustomTable $p, CustomColumn $ref, string $column, \Closure $keys): array
    {
        $viewColumn = new \Exceedone\Exment\Model\CustomViewColumn([
            'custom_view_id' => 0,
            'view_column_type' => \Exceedone\Exment\Enums\ConditionType::COLUMN,
            'view_column_table_id' => $p->id,
            'view_column_target_id' => CustomColumn::getEloquent($column, $p)->id,
            'options' => ['view_pivot_table_id' => $c->id, 'view_pivot_column_id' => $ref->id],
        ]);
        $query = $c->getValueQuery();
        (new SearchService($c))->setQuery($query)->addSelect()->orderByCustomViewColumn($viewColumn, 'asc');
        $sql = $query->toSql();
        return ['keys' => $keys($query), 'has_order' => stripos($sql, 'order by') !== false,         'qualified' => (bool)preg_match('/order by [`\[\"][^`\]\"]+[`\]\"]\./i', $sql)];
    }
    /**
     * Summary join (RelationTable::setSummaryChildJoinSelectTable) over a MULTIPLE select_table pivot, in the four
     * parent/child JSON-physical combinations. Expected values come from the fixture constants, not from the SQL:
     * a child counts once for every parent id listed in refm (repeated id = once: the relation is a set, the join
     * matches the child's key once per parent), 1 does not match 11, 'abc' / '' / missing match nothing, a parent
     * without children keeps NULL on LEFT and disappears on INNER; the outer query sums per parent, one row each.
     */
    public function testMultiValuePivotSummaryJoinInFourCombinations(): void
    {
        foreach ([['json', 'json'], ['json', 'physical'], ['physical', 'json'], ['physical', 'physical']] as [$pm, $cm]) {
            [$p, $c, $pids, $cids] = $this->build($pm, $cm);
            $label = "parent=$pm child=$cm";

            // c7 repeats an element; p4 has no child at all
            $m = $c->getValueModel();
            $m->setValue(['name' => 'c7', 'refm' => [$pids['p3'], $pids['p3']]])->save();
            $orphan = $p->getValueModel();
            $orphan->setValue(['label' => 'Delta', 'num' => '40', 'memo' => 'Delta'])->save();
            $pids['p4'] = (int)$orphan->id;
            System::clearCache();
            System::clearRequestSession();
            $p = CustomTable::getEloquent($p->id);
            $c = CustomTable::getEloquent($c->id);
            $refm = CustomColumn::getEloquent('refm', $c);

            // c1:[p1,p3] c2:[p2] c5:[p1+'1','abc',''] c6:['abc',p2] c7:[p3,p3]  (c3, c4 have no refm)
            $all = [$pids['p1'] => 1, $pids['p2'] => 2, $pids['p3'] => 2, $pids['p4'] => null];

            $run = function (bool $left) use (&$p, &$c, &$refm) {
                $pdb = getDBTableName($p);
                $rt = new RelationTable();
                $rt->searchType = SearchType::SUMMARY_SELECT_TABLE;
                $a = $rt->tableUniqueName;
                $rt->subQueryCallbacks = [function ($sub) use ($a) {
                    $g = DB::getQueryGrammar();
                    $sub->selectRaw('COUNT(' . $g->wrap("$a.id") . ') AS ' . $g->wrap('cnt'));
                }];
                $g = DB::getQueryGrammar();
                $q = $p->getValueQuery();
                $q->selectRaw($g->wrap("$pdb.id") . ' AS ' . $g->wrap('pid') . ', SUM(' . $g->wrap("$a.cnt") . ') AS ' . $g->wrap('cnt'))->groupBy("$pdb.id");
                $rt->setSummaryChildJoinSelectTable($q, $p, $refm, $left);
                $rows = $q->get();
                $res = [];
                foreach ($rows as $r) {
                    $res[(int)$r->pid] = $r->cnt === null ? null : (int)$r->cnt;
                }
                ksort($res);
                return [$res, $rows->count()];
            };

            [$left, $leftRows] = $run(true);
            $this->assertSame($all, $left, "$label LEFT: per-parent totals");
            $this->assertSame(4, $leftRows, "$label LEFT: one row per parent");
            [$inner, $innerRows] = $run(false);
            $this->assertSame(array_filter($all), $inner, "$label INNER: parents without children are dropped");
            $this->assertSame(3, $innerRows, "$label INNER: one row per parent");

            // restricted user: parent p2 is not visible; the totals of the visible parents are unchanged
            \Exceedone\Exment\Model\CustomValueAuthoritable::where('parent_id', $pids['p2'])->where('parent_type', $p->table_name)->delete();
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
            System::clearRequestSession();
            try {
                [$restricted] = $run(true);
            } finally {
                $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
                System::clearRequestSession();
            }
            $this->assertSame([$pids['p1'] => 1, $pids['p3'] => 2], $restricted, "$label user2: visible parents only, same totals");

            // the INDEXED multiple pivot (the form the summary screens discover): same totals through the index column
            if ($cm === 'json') {
                CustomColumn::create([
                    'custom_table_id' => $c->id, 'column_name' => 'refmi', 'column_view_name' => 'refmi', 'column_type' => ColumnType::SELECT_TABLE,
                    'options' => ['select_target_table' => $p->id, 'multiple_enabled' => '1', 'index_enabled' => '1'],
                ]);
                System::clearCache();
                System::clearRequestSession();
                $c = CustomTable::getEloquent($c->id);
                $p = CustomTable::getEloquent($p->id);
                foreach (DB::table(getDBTableName($c))->whereNull('deleted_at')->get() as $row) {
                    $v = json_decode($row->value, true);
                    if (isset($v['refm'])) {
                        $v['refmi'] = $v['refm'];
                        DB::table(getDBTableName($c))->where('id', $row->id)->update(['value' => json_encode($v)]);
                    }
                }
                $refm = CustomColumn::getEloquent('refmi', $c);
                $this->assertTrue((bool)$refm->index_enabled);
                $this->assertSame($all, $run(true)[0], "$label indexed multi pivot LEFT");
            }
        }
    }

    public function testPhysicalChildReferencingSystemUserTable(): void
    {
        $user = CustomTable::getEloquent('user');
        $this->assertTrue(\Exceedone\Exment\Services\Storage\ConversionEligibility::isSystemTable($user));
        $this->assertFalse(\Exceedone\Exment\Services\Storage\ConversionEligibility::check($user)->isEligible(), 'user table must be blocked');

        $out = [];
        foreach (['json', 'physical'] as $mode) {
            $c = $this->newTable('hyb_mxu_' . $mode[0] . substr(md5((string)microtime(true)), 0, 5), [
                ['name', ColumnType::TEXT, []],
                ['owner', ColumnType::USER, ['index_enabled' => '1']],
                ['owners', ColumnType::USER, ['multiple_enabled' => '1']],
            ]);
            $uid = (int)\Exment::getUserId();
            foreach (['a' => $uid, 'b' => null] as $name => $u) {
                $m = $c->getValueModel();
                $m->setValue(['name' => $name] + ($u ? ['owner' => $u, 'owners' => [$u]] : []))->save();
            }
            if ($mode === 'physical') {
                $this->toPhysicalRead($c);
            }
            System::clearCache();
            System::clearRequestSession();
            $c = CustomTable::getEloquent($c->id);
            $col = CustomColumn::getEloquent('owner', $c);
            $db = getDBTableName($c);
            $q = $c->getValueQuery();
            RelationTable::setQuerySelectTable($q, $col, $uid);
            $names = $q->get()->map(fn ($m) => $m->getValue('name'))->all();
            $disp = $c->getValueQuery()->get()->map(fn ($m) => $m->getValue('name') . ':' . (is_object($m->getValue('owner')) ? $m->getValue('owner')->id : 'null'))->sort()->values()->all();
            $q2 = $c->getValueQuery();
            $q2->whereInArrayString(\Exceedone\Exment\Model\ColumnQueryKey::index(CustomColumn::getEloquent('owners', $c))->expression(), [(string)$uid]);
            $out[$mode] = ['filter' => $names, 'display' => $disp, 'multi' => $q2->get()->map(fn ($m) => $m->getValue('name'))->all()];
        }
        file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'storage_mixed_user_result.json', json_encode($out));
        $this->assertSame($out['json'], $out['physical']);
        $this->assertSame(['a'], $out['json']['filter']);
    }

    public function testFourCombinationsGiveTheSameResult(): void
    {
        $results = [];
        foreach ([['json', 'json'], ['json', 'physical'], ['physical', 'json'], ['physical', 'physical']] as [$pm, $cm]) {
            [$p, $c, $pids, $cids] = $this->build($pm, $cm);
            $this->assertSame($pm === 'physical', TableStorage::fresh($p)->state() === StorageState::PHYSICAL_READ);
            $this->assertSame($cm === 'physical', TableStorage::fresh($c)->state() === StorageState::PHYSICAL_READ);
            $results["parent=$pm child=$cm"] = $this->observe($p, $c, $pids, $cids);
        }
        file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'storage_mixed_mode_result.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $baseline = $results['parent=json child=json'];
        $diffs = [];
        foreach ($results as $label => $observed) {
            foreach ($observed as $name => $value) {
                if (json_encode($value) !== json_encode($baseline[$name])) {
                    $diffs[] = "$label / $name: " . json_encode($value, JSON_UNESCAPED_UNICODE) . ' != baseline ' . json_encode($baseline[$name], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $this->assertSame([], $diffs, implode("\n", $diffs));
        $this->assertSame(['c1', 'c3', 'c4', 'c6'], $baseline['restricted_user2']['visible'] ?? null, json_encode($baseline['restricted_user2']));
        // invalid / odd references never match and never raise; INNER drops those rows, LEFT keeps them; 1 vs 11 is not a match;
        // a row with two matching elements stays two rows (no DISTINCT)
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], $baseline['inner_join_single_rows'] ?? null, json_encode($baseline['inner_join_single_rows'] ?? null));
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5', 'c6'], $baseline['join_single_rows'] ?? null);
        $this->assertSame(['c1', 'c1', 'c2', 'c6'], $baseline['inner_join_multi_rows'] ?? null, json_encode($baseline['inner_join_multi_rows'] ?? null));
        $this->assertSame(['c1', 'c1', 'c2', 'c3', 'c4', 'c5', 'c6'], $baseline['join_multi_rows'] ?? null, json_encode($baseline['join_multi_rows'] ?? null));
        $this->assertContains('c6>-', $baseline['join_single_parent_values'] ?? [], 'the invalid reference joins no parent');
        $this->assertSame('abc', $baseline['display']['c6']['raw'] ?? null, 'the original public value is not coerced');
        $this->assertTrue($baseline['restricted_user2']['hidden_row_find'] ?? false);
    }
}
