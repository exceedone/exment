<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\DefaultTableProvider;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\ImportSelectTablePreload;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * Step 4: ImportSelectTablePreload only warms the request cache; every observable answer must stay the one of the per-id path.
 */
class ImportSelectTablePreloadTest extends UnitTestBase
{
    use DatabaseTransactions;

    /** admin sees everything; the dev users only see part of custom_value_edit / custom_value_view / child_table */
    protected const USERS = [
        TestDefine::TESTDATA_USER_LOGINID_ADMIN,
        TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
        TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
        TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD,
    ];

    /** @var int */
    protected $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        DB::listen(function () {
            $this->queries++;
        });
    }

    protected function table(string $name = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST): CustomTable
    {
        return CustomTable::getEloquent($name);
    }

    /**
     * Forget the cached custom value models of the request only (what System::clearRequestSessionPrefix('custom_value_value.')
     * does in the v7.0.2 cache commit, which is not part of this tree). The metadata caches stay, so the query counts
     * below count the lookups of the preload and not a reload of the metadata.
     */
    protected function forgetCustomValueModels(): void
    {
        $property = new \ReflectionProperty(System::class, 'requestSession');
        $property->setAccessible(true);
        $session = $property->getValue();
        foreach (array_keys($session) as $key) {
            if (is_string($key) && str_starts_with($key, 'custom_value_value.')) {
                unset($session[$key]);
            }
        }
        $property->setValue(null, $session);
    }

    protected function clearModelCache(): void
    {
        $this->forgetCustomValueModels();
    }

    /**
     * @return array<int, string> every id of the table, whoever may see it
     */
    protected function allIds(string $tableName): array
    {
        return CustomTable::getEloquent($tableName)->getValueModel()->newQuery()->withoutGlobalScopes()->orderBy('id')->pluck('id')
            ->map(fn($id) => (string)$id)->all();
    }

    /**
     * Rows as DefaultTableProvider::getDataObject() returns them, for the select_table / user / organization columns of the table.
     *
     * @return array<int, array{data: array<string, mixed>, model: mixed}>
     */
    protected function rowsFor(CustomTable $table): array
    {
        $viewAll = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $edit = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $users = $this->allIds('user');
        $organizations = $this->allIds('organization');
        $this->assertNotEmpty($viewAll);
        $this->assertNotEmpty($edit);

        $pick = fn(array $list, int $i, int $n = 1) => $list[($i * 7 + $n) % count($list)];
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[$i + 2] = ['data' => [
                'value.select_table' => $pick($viewAll, $i),
                'value.select_table_2' => $i % 9 === 0 ? '999999' : $pick($edit, $i),
                'value.select_table_multiple' => [$pick($viewAll, $i), $pick($viewAll, $i, 3), '999998'],
                'value.user' => $pick($users, $i),
                'value.organization' => $organizations ? $pick($organizations, $i) : null,
            ], 'model' => $table->getValueModel()];
        }
        return $rows;
    }

    /**
     * @param array<int, string|int> $ids
     * @return array<string, array|null> id => attributes of the cached model, null when the per-id path finds nothing
     */
    protected function answers(CustomTable $target, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $model = $target->getValueModel($id);
            $out[(string)$id] = $model ? $model->getAttributes() : null;
        }
        return $out;
    }

    public function testPreloadedAnswersAreTheOnesOfThePerIdPathForEveryUser()
    {
        $table = $this->table();
        $rows = $this->rowsFor($table);
        $visibleCounts = [];

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $targets = [
                TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL => $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL),
                TestDefine::TESTDATA_TABLE_NAME_EDIT => array_merge($this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT), ['999999']),
                'user' => $this->allIds('user'),
            ];

            $this->clearModelCache();
            $expected = [];
            foreach ($targets as $name => $ids) {
                $expected[$name] = $this->answers($this->table($name), $ids);
            }

            $this->clearModelCache();
            $this->queries = 0;
            $requested = ImportSelectTablePreload::preload($table, $rows);
            $preloadQueries = $this->queries;
            $this->assertGreaterThan(0, $requested);
            $this->assertLessThanOrEqual(12, $preloadQueries, "user $userId: batches, not one query per id");

            foreach ($targets as $name => $ids) {
                $this->assertEquals($expected[$name], $this->answers($this->table($name), $ids), "user $userId, table $name");
            }
            $visibleCounts[$userId] = count(array_filter($expected[TestDefine::TESTDATA_TABLE_NAME_EDIT]));
        }

        // the permission scope reached the preload: not every user sees the same records
        $this->assertGreaterThan(1, count(array_unique($visibleCounts)), json_encode($visibleCounts));
    }

    public function testQueryCountDropsAndAlreadyCachedModelsAreKept()
    {
        $table = $this->table();
        $rows = $this->rowsFor($table);
        $viewAll = $this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $ids = array_values(array_unique(array_map(fn($r) => (string)$r['data']['value.select_table'], $rows)));
        $this->assertGreaterThan(5, count($ids));

        $this->clearModelCache();
        $this->queries = 0;
        $this->answers($viewAll, $ids);
        $perId = $this->queries;

        $this->clearModelCache();
        $keep = $viewAll->getValueModel($ids[0]);
        ImportSelectTablePreload::preload($table, $rows);
        $this->assertSame($keep, $viewAll->getValueModel($ids[0]), 'an id that is already cached is not replaced');

        $this->queries = 0;
        $this->answers($viewAll, $ids);
        $this->assertSame(0, $this->queries, "per-id queries before: $perId");
        $this->assertGreaterThanOrEqual(count($ids), $perId);
    }

    public function testBatchSizeAndCapBoundTheLookupAndTheRestTakesThePerIdPath()
    {
        $table = $this->table();
        $rows = $this->rowsFor($table);
        $viewAll = $this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $ids = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);

        $this->clearModelCache();
        $expected = $this->answers($viewAll, $ids);

        $this->clearModelCache();
        $this->queries = 0;
        $requested = ImportSelectTablePreloadTiny::preload($table, $rows);
        // select_table + select_table_multiple of view_all, edit, user, organization: each target table is capped at 4 ids / 3 per query
        $this->assertLessThanOrEqual(4 * 4, $requested);
        $this->assertLessThanOrEqual(8 * 2, $this->queries);
        $this->assertEquals($expected, $this->answers($viewAll, $ids), 'ids above the cap are found by the original path');
    }

    public function testValuesThatAreNotPlainPositiveIntegersAreNotPreloaded()
    {
        $table = $this->table();
        $row = fn($v) => ['data' => ['value.select_table' => $v], 'model' => $table->getValueModel()];

        $this->queries = 0;
        $this->clearModelCache();
        // " 5" is trimmed by stringToArray() exactly like the rule does, so it is the plain id 5 (the only id requested here)
        $requested = ImportSelectTablePreload::preload($table, [
            $row('007'), $row('5.0'), $row(' 5'), $row(-1), $row(1.5), $row(true), $row(null), $row([]), $row('abc'), $row('0'), $row(0),
            ['data' => null], ['model' => null], $row('1e3'), $row('99999999999999999999'),
        ]);
        $this->assertSame(1, $requested);
        $this->assertLessThanOrEqual(1, $this->queries);

        // plain ids, as list / comma separated string / int
        $ids = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $this->clearModelCache();
        $this->assertSame(3, ImportSelectTablePreload::preload($table, [$row((int)$ids[0]), $row("{$ids[1]}, {$ids[2]}"), $row([$ids[0], 'x'])]));
    }

    protected function runValidation(string $provider, CustomTable $table, array $rows): array
    {
        $this->clearModelCache();
        $this->queries = 0;
        $p = new $provider(['custom_table' => $table, 'primary_key' => 'id', 'filter' => null]);
        [$ok, $errors] = $p->validateImportData($rows);
        return ['success' => count($ok), 'errors' => $errors, 'queries' => $this->queries];
    }

    public function testValidationErrorsAreIdenticalWithAndWithoutThePreload()
    {
        $table = $this->table();
        $rows = $this->rowsFor($table);
        $rows[] = ['data' => ['value.select_table' => 'abc', 'value.select_table_2' => '007', 'value.select_table_multiple' => ['x']], 'model' => $table->getValueModel()];
        $rows[] = ['data' => ['value.select_table' => null], 'model' => $table->getValueModel()];

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $before = $this->runValidation(NoPreloadTableProvider::class, $table, $rows);
            $after = $this->runValidation(DefaultTableProvider::class, $table, $rows);

            $this->assertSame($before['errors'], $after['errors'], "user $userId");
            $this->assertSame($before['success'], $after['success']);
            $this->assertLessThan($before['queries'], $after['queries'], "user $userId queries");
            $this->assertNotEmpty($after['errors'], 'the data contains missing / invisible references');
        }
    }

    /**
     * @param array<int, string> $viewAll
     * @param array<int, string> $edit
     * @param array<int, string> $users
     */
    protected function limitRows(CustomTable $table, array $viewAll, array $edit, array $users, int $nViewAll, int $nEdit, int $nUsers): array
    {
        $rows = [];
        $n = max($nViewAll, $nEdit, $nUsers);
        for ($i = 0; $i < $n; $i++) {
            $data = [];
            if ($i < $nViewAll) {
                $data['value.select_table'] = $viewAll[$i];
            }
            if ($i < $nEdit) {
                $data['value.select_table_2'] = $edit[$i];
            }
            if ($i < $nUsers) {
                $data['value.user'] = $users[$i];
            }
            $rows[$i + 2] = ['data' => $data, 'model' => $table->getValueModel()];
        }
        // the same ids again: distinct ids are counted once
        return array_merge($rows, $rows);
    }

    public function testCapIsPerTargetTableAndBelowAtAboveTheLimitBehavesAsDocumented()
    {
        $table = $this->table();
        $viewAll = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $edit = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $users = $this->allIds('user');
        $cap = ImportSelectTablePreloadTiny::MAX_PRELOADED;
        foreach ([$viewAll, $edit, $users] as $list) {
            $this->assertGreaterThan($cap + 1, count($list));
        }

        // [distinct ids per target table] => [ids requested per target table]
        foreach ([
            [$cap - 1, $cap - 1, $cap - 1],
            [$cap, $cap, $cap],
            [$cap + 1, $cap + 1, $cap + 1],
            [$cap + 3, 2, $cap],
            [0, $cap + 2, 1],
        ] as [$nViewAll, $nEdit, $nUsers]) {
            $rows = $this->limitRows($table, $viewAll, $edit, $users, $nViewAll, $nEdit, $nUsers);
            $this->clearModelCache();
            $this->queries = 0;
            $requested = ImportSelectTablePreloadTiny::preload($table, $rows);
            $label = "$nViewAll/$nEdit/$nUsers";

            $expected = [TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL => min($cap, $nViewAll), TestDefine::TESTDATA_TABLE_NAME_EDIT => min($cap, $nEdit), 'user' => min($cap, $nUsers)];
            $this->assertSame(array_sum($expected), $requested, $label);
            $batches = array_sum(array_map(fn($n) => (int)ceil($n / ImportSelectTablePreloadTiny::BATCH_SIZE), $expected));
            $this->assertLessThanOrEqual($batches, $this->queries, "$label: one query per batch, never per id");

            // the first MAX ids of a table are cached (when visible), the ones above the cap are left to the per-id path
            foreach ([TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL => $viewAll, TestDefine::TESTDATA_TABLE_NAME_EDIT => $edit, 'user' => $users] as $name => $list) {
                foreach (array_slice($list, 0, $expected[$name]) as $id) {
                    $this->assertTrue(System::hasRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $name, $id)), "$label: $name#$id preloaded");
                }
                foreach (array_slice($list, $expected[$name], 3) as $id) {
                    $this->assertFalse(System::hasRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $name, $id)), "$label: $name#$id above the cap");
                }
            }
        }
    }

    public function testErrorsAreIdenticalWhenTheCapIsExceededForSeveralTargetTables()
    {
        $table = $this->table();
        $viewAll = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $edit = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $users = $this->allIds('user');
        $cap = ImportSelectTablePreloadTiny::MAX_PRELOADED;
        $rows = $this->limitRows($table, $viewAll, $edit, $users, $cap + 3, $cap + 3, $cap + 3);
        $rows[] = ['data' => ['value.select_table' => '999999', 'value.select_table_2' => '999998', 'value.user' => '999997'], 'model' => $table->getValueModel()];

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $before = $this->runValidation(NoPreloadTableProvider::class, $table, $rows);
            $capped = $this->runValidation(TinyCapTableProvider::class, $table, $rows);
            $full = $this->runValidation(DefaultTableProvider::class, $table, $rows);
            $this->assertSame($before['errors'], $capped['errors'], "user $userId, capped");
            $this->assertSame($before['errors'], $full['errors'], "user $userId, default");
            $this->assertSame($before['success'], $capped['success']);
            $this->assertNotEmpty($before['errors'], 'missing ids must still be reported');
        }
    }

    public function testNothingIsKeptByTheClassAndInvisibleOrMissingIdsAreNotCached()
    {
        $this->assertSame([], (new \ReflectionClass(ImportSelectTablePreload::class))->getStaticProperties(), 'no state outside the request cache');

        $table = $this->table();
        $editTable = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $edit = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $ids = array_merge($edit, ['999999']);
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[$i + 2] = ['data' => ['value.select_table_2' => $id], 'model' => $table->getValueModel()];
        }

        $invisibleSeen = false;
        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $this->clearModelCache();
            $visible = $editTable->getValueQuery()->whereIn('id', $edit)->pluck('id')->map(fn($v) => (string)$v)->all();
            ImportSelectTablePreload::preload($table, $rows);
            foreach ($ids as $id) {
                $cached = System::hasRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $editTable->table_name, $id));
                $this->assertSame(in_array((string)$id, $visible, true), $cached, "user $userId id $id: only what the user may see is cached");
                $invisibleSeen = $invisibleSeen || !in_array((string)$id, $visible, true);
            }
        }
        $this->assertTrue($invisibleSeen, 'the data set contains ids that are not visible to some user');
    }

    public function testChunkAndUserSequencesInOneProcessAnswerLikeTheOriginalPath()
    {
        $table = $this->table();
        $rows = array_values($this->rowsFor($table));
        $chunks = array_chunk($rows, 7, true);

        // the request cache is not cleared between users / chunks on purpose: the per-id path does not clear it either
        $run = function (string $provider) use ($table, $chunks) {
            $this->clearModelCache();
            $out = [];
            foreach (static::USERS as $userId) {
                $this->be(LoginUser::find($userId));
                foreach ($chunks as $c => $chunk) {
                    $p = new $provider(['custom_table' => $table, 'primary_key' => 'id', 'filter' => null]);
                    [$ok, $errors] = $p->validateImportData($chunk);
                    $out["$userId/$c"] = ['success' => count($ok), 'errors' => $errors];
                }
            }
            // and back to the first user
            $this->be(LoginUser::find(static::USERS[0]));
            return $out;
        };

        $this->assertSame($run(NoPreloadTableProvider::class), $run(DefaultTableProvider::class));
    }

    public function testSelectTargetViewFilterStillAppliesToEveryRow()
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_SELECT);
        $children = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $parents = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $this->assertNotEmpty($children);

        $rows = [];
        foreach ($children as $i => $id) {
            $rows[$i + 2] = ['data' => [
                'value.child' => $id,
                'value.child_view' => $id,
                'value.child_ajax_view' => $id,
                'value.parent' => $parents[$i % count($parents)],
                'value.parent_multi' => [$parents[$i % count($parents)], $parents[($i + 1) % count($parents)]],
            ], 'model' => $table->getValueModel()];
        }

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $before = $this->runValidation(NoPreloadTableProvider::class, $table, $rows);
            $after = $this->runValidation(DefaultTableProvider::class, $table, $rows);
            $this->assertSame($before['errors'], $after['errors'], "user $userId");
            $this->assertSame($before['success'], $after['success']);
            $this->assertLessThanOrEqual($before['queries'], $after['queries']);
        }
    }
}

class ImportSelectTablePreloadTiny extends ImportSelectTablePreload
{
    public const BATCH_SIZE = 3;

    public const MAX_PRELOADED = 4;
}

class TinyCapTableProvider extends DefaultTableProvider
{
    protected function preloadSelectTableValues($dataObjects)
    {
        ImportSelectTablePreloadTiny::preload($this->custom_table, $dataObjects);
    }
}

class NoPreloadTableProvider extends DefaultTableProvider
{
    protected function preloadSelectTableValues($dataObjects)
    {
    }
}
