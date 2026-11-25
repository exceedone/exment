<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Actions\Export\CustomTableAction;
use Exceedone\Exment\Services\DataImportExport\Providers\Export\DefaultTableProvider;
use Exceedone\Exment\Services\DataImportExport\Providers\Export\ExportReferencePreload;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Grid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step 6: ExportReferencePreload only warms the request cache; the exported cells must stay the ones of the per-id path
 * (ExportProbeProvider with the preload switched off is the pre-Step-6 implementation).
 */
class ExportReferencePreloadTest extends UnitTestBase
{
    use DatabaseTransactions;

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

    /**
     * Carbon cells are different instances on every conversion: compare them (and every other cell, with its type) by value.
     *
     * @param mixed $data
     * @return mixed
     */
    protected function norm($data)
    {
        return json_decode(json_encode($data), true);
    }

    protected function assertSameCells($expected, $actual, string $message = ''): void
    {
        $this->assertSame($this->norm($expected), $this->norm($actual), $message);
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

    protected function table(string $name): CustomTable
    {
        return CustomTable::getEloquent($name);
    }

    /**
     * @return array<int, string> every id of the table, whoever may see it
     */
    protected function allIds(string $tableName): array
    {
        return $this->table($tableName)->getValueModel()->newQuery()->withoutGlobalScopes()->orderBy('id')->pluck('id')
            ->map(fn($id) => (string)$id)->all();
    }

    /** the parent / child relation gets a parent_export_column_id (rolled back with the transaction) */
    protected function enableParentExport(): void
    {
        $parent = $this->table(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $relation = CustomRelation::getRelationByParentChild($parent, $child);
        $this->assertNotNull($relation);
        $relation = CustomRelation::query()->where('id', $relation->id)->firstOrFail();
        $relation->setOption('parent_export_column_id', CustomColumn::getEloquent('index_text', $parent)->id);
        $relation->save();
        $this->forgetCustomValueModels();
    }

    /**
     * A select_table / user column of the table, exporting $exportColumn of the target table (in memory only).
     */
    protected function exportColumn(CustomTable $table, string $columnName, ?string $exportColumn): CustomColumn
    {
        $column = clone CustomColumn::getEloquent($columnName, $table);
        $target = $column->select_target_table;
        $column->setOption('select_export_column_id', isset($exportColumn) ? CustomColumn::getEloquent($exportColumn, $target)->id : null);

        return $column;
    }

    /**
     * @return array<int, CustomColumn>
     */
    protected function allColumnsExportColumns(CustomTable $table, bool $withExportColumn = true): array
    {
        $columns = [];
        foreach ($table->custom_columns_cache as $column) {
            $name = $column->column_name;
            $exportColumn = $withExportColumn ? match ($name) {
                'user' => 'user_code',
                'organization' => 'organization_code',
                default => 'index_text',
            } : null;
            $columns[] = in_array($name, ['select_table', 'select_table_2', 'select_table_multiple', 'user', 'organization'], true)
                ? $this->exportColumn($table, $name, $exportColumn)
                : $column;
        }

        return $columns;
    }

    /**
     * @param array<int, CustomColumn>|null $columns
     */
    protected function provider(CustomTable $table, bool $preload, ?array $columns = null, ?string $class = null): ExportProbeProvider
    {
        $class = $class ?? ExportProbeProvider::class;
        $provider = new $class(['custom_table' => $table]);
        $provider->preload = $preload;
        $provider->columns = $columns;

        return $provider;
    }

    /**
     * Records of the all columns table whose references are patched to exercise every kind of value.
     */
    protected function patchedRecords(): Collection
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $records = getModelName($table)::get();
        $this->assertGreaterThan(10, $records->count());

        $viewAll = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $edit = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $users = $this->allIds('user');
        $odd = ['999999', 'abc', '01', '1.0', '-3', '0', ' 5', ''];

        return $records->values()->map(function ($record, $i) use ($viewAll, $edit, $users, $odd) {
            $value = $record->value;
            $value['select_table'] = $i % 7 === 0 ? $odd[$i % count($odd)] : (int)$viewAll[$i % count($viewAll)];
            $value['select_table_2'] = $i % 5 === 0 ? null : (int)$edit[($i * 3) % count($edit)];
            $value['select_table_multiple'] = [(int)$viewAll[$i % count($viewAll)], (int)$viewAll[($i + 2) % count($viewAll)], 999998];
            $value['user'] = (int)$users[$i % count($users)];
            $record->value = $value;

            return $record;
        });
    }

    /**
     * @param array<int, CustomColumn> $columns
     * @return array<int, mixed>
     */
    protected function defines(array $columns): array
    {
        return [['id', 'suuid', 'parent_id', 'parent_type'], $columns, ['created_at', 'updated_at', 'deleted_at']];
    }

    public function testBodiesAreTheOnesOfThePerIdPathForEveryUserWithFewerQueries()
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $columns = $this->allColumnsExportColumns($table);
        $records = $this->patchedRecords();
        $digests = [];

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));

            $this->clearModelCache();
            $this->queries = 0;
            $expected = $this->provider($table, false, $columns)->bodies($records);
            $legacyQueries = $this->queries;

            $this->clearModelCache();
            $this->queries = 0;
            $actual = $this->provider($table, true, $columns)->bodies($records);
            $newQueries = $this->queries;

            $this->assertSameCells($expected, $actual, "user $userId");
            $this->assertLessThan($legacyQueries, $newQueries, "user $userId: $newQueries vs $legacyQueries queries");
            $digests[$userId] = md5(json_encode($actual));
        }

        // the permission scope reached the export: not every user gets the same cells
        $this->assertGreaterThan(1, count(array_unique($digests)));
    }

    public function testParentIdsAreResolvedLikeThePerIdPathForEveryUser()
    {
        $this->enableParentExport();
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $records = getModelName($child)::get();
        $this->assertGreaterThan(5, $records->count());
        $this->assertGreaterThan(2, count(array_unique($records->pluck('parent_id')->all())));

        // an id that is missing and an id of another parent type stay untouched
        $missing = clone $records->first();
        $missing->parent_id = 999999;
        $records->push($missing);
        $other = clone $records->first();
        $other->parent_type = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;
        $records->push($other);

        $converted = [];
        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));

            $this->clearModelCache();
            $this->queries = 0;
            $expected = $this->provider($child, false)->bodies($records);
            $legacyQueries = $this->queries;

            $this->clearModelCache();
            $this->queries = 0;
            $actual = $this->provider($child, true)->bodies($records);
            $newQueries = $this->queries;

            $this->assertSameCells($expected, $actual, "user $userId");
            $this->assertLessThanOrEqual($legacyQueries, $newQueries);
            $converted[$userId] = array_column($actual, 2);
            $this->assertSame(999999, array_column($actual, 2)[count($actual) - 2], 'a missing parent keeps its id');
        }

        // a visible parent is exported through the export column, an invisible one keeps its id
        $this->assertGreaterThan(1, count(array_unique(array_map('json_encode', $converted))));
        $this->assertNotSame($records->pluck('parent_id')->all(), $converted[TestDefine::TESTDATA_USER_LOGINID_ADMIN]);
    }

    public function testNothingIsRequestedWithoutAnExportColumnOrParentExportColumn()
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $records = $this->patchedRecords();

        $defines = $this->defines($this->allColumnsExportColumns($table, false));
        $this->clearModelCache();
        $this->queries = 0;
        $this->assertSame(0, ExportReferencePreload::preload($table, $records, $defines));
        $this->assertSame(0, $this->queries, 'no select_export_column_id: the values are exported as ids, no lookup');

        // a child table whose relation has no parent_export_column_id: no parent lookup
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $childRecords = getModelName($child)::get();
        $this->assertGreaterThan(0, $childRecords->count());
        $this->assertSame(0, ExportReferencePreload::preload($child, $childRecords, $this->defines([])));
        // the first call reads the relation metadata; a repeated call must not touch the database at all
        $this->queries = 0;
        $this->assertSame(0, ExportReferencePreload::preload($child, $childRecords, $this->defines([])));
        $this->assertSame(0, $this->queries);

        $this->enableParentExport();
        $this->assertGreaterThan(0, ExportReferencePreload::preload($child, $childRecords, $this->defines([])));
        // a window without a parent_id column asks for nothing even when the relation exports one
        $this->assertSame(0, ExportReferencePreload::preload($child, $childRecords, [['id', 'suuid'], [], []]));
    }

    public function testBatchAndWindowBoundariesDoNotChangeTheOutput()
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $columns = $this->allColumnsExportColumns($table);
        $records = $this->patchedRecords();

        $this->clearModelCache();
        $expected = $this->provider($table, false, $columns)->bodies($records);

        // tiny batches: one query per batch
        $this->clearModelCache();
        $this->queries = 0;
        $requested = TinyBatchExportPreload::preload($table, $records, $this->defines($columns));
        $this->assertGreaterThan(3, $requested);
        $this->assertGreaterThanOrEqual((int)ceil($requested / TinyBatchExportPreload::BATCH_SIZE), $this->queries);
        $this->assertLessThanOrEqual($requested, $this->queries);

        // windows of 1 row, of 4 rows and the default one (more rows than the table has)
        foreach ([OneRowWindowExportProvider::class => 1, SmallWindowExportProvider::class => 4, ExportProbeProvider::class => 1000] as $class => $window) {
            $this->clearModelCache();
            $this->queries = 0;
            $actual = $this->provider($table, true, $columns, $class)->bodies($records);
            $this->assertSameCells($expected, $actual, "window $window");
            $this->assertLessThanOrEqual(10 * (int)ceil($records->count() / $window) + 5, $this->queries, "window $window");
        }
    }

    public function testOnlyVisibleCanonicalIdsAreCachedAndTheClassKeepsNoState()
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $defines = $this->defines($this->allColumnsExportColumns($table));
        $records = $this->patchedRecords();

        $this->assertSame([], (new \ReflectionClass(ExportReferencePreload::class))->getStaticProperties());

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $viewable = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT)->getValueModel()->newQuery()->pluck('id')->map(fn($i) => (string)$i)->all();
        $all = $this->allIds(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->assertNotEmpty(array_diff($all, $viewable), 'the user cannot see every record of the table');

        $this->clearModelCache();
        ExportReferencePreload::preload($table, $records, $defines);
        $key = fn(string $name, $id) => sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $name, $id);
        foreach (array_diff($all, $viewable) as $id) {
            $this->assertFalse(System::hasRequestSession($key(TestDefine::TESTDATA_TABLE_NAME_EDIT, $id)), "invisible id $id is not cached");
        }
        foreach (['999999', 'abc', '01', '1.0', '-3', '0', ' 5'] as $odd) {
            foreach ([TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, TestDefine::TESTDATA_TABLE_NAME_EDIT] as $name) {
                $this->assertFalse(System::hasRequestSession($key($name, $odd)), "$name $odd");
            }
        }
    }

    public function testExportActionDatalistIsIdenticalToThePreStep6OneForEveryUser()
    {
        $this->enableParentExport();
        $parent = $this->table(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $classname = getModelName($parent);
        $digests = [];

        foreach (static::USERS as $userId) {
            $this->be(LoginUser::find($userId));
            $out = [];
            foreach ([true, false] as $preload) {
                $this->clearModelCache();
                $grid = new Grid(new $classname());
                $grid->model()->usePaginate(false);
                $action = new ExportProbeAction(['custom_table' => $parent, 'grid' => $grid, 'add_setting' => true, 'add_relation' => true]);
                $action->preload = $preload;
                $out[$preload ? 'new' : 'legacy'] = $action->datalist();
            }

            $this->assertSameCells($out['legacy'], $out['new'], "user $userId");
            $this->assertGreaterThan(1, count($out['new']), 'parent and child sheets');
            $digests[$userId] = md5(json_encode($out['new']));
        }

        $this->assertGreaterThan(1, count(array_unique($digests)), 'permissions change the exported rows');
    }
}

/** the pre-Step-6 behaviour when $preload is false */
class ExportProbeProvider extends DefaultTableProvider
{
    /** @var bool */
    public $preload = true;
    /** @var array<int, mixed>|null */
    public $columns;

    protected function getColumnDefines()
    {
        $defines = parent::getColumnDefines();
        if (isset($this->columns)) {
            $defines[1] = $this->columns;
        }

        return $defines;
    }

    protected function preloadReferenceValues($records, $columnDefines)
    {
        if ($this->preload) {
            parent::preloadReferenceValues($records, $columnDefines);
        }
    }

    /**
     * @param iterable<mixed> $records
     * @return array<int, array<int, mixed>>
     */
    public function bodies($records): array
    {
        return $this->getBodies($records, $this->getColumnDefines());
    }
}

class SmallWindowExportProvider extends ExportProbeProvider
{
    protected const PRELOAD_WINDOW = 4;
}

class OneRowWindowExportProvider extends ExportProbeProvider
{
    protected const PRELOAD_WINDOW = 1;
}

class TinyBatchExportPreload extends ExportReferencePreload
{
    public const BATCH_SIZE = 3;
}

class ExportProbeAction extends CustomTableAction
{
    /** @var bool */
    public $preload = true;

    /** @return array<int, array{name: string, outputs: mixed}> */
    public function datalist()
    {
        $providers = [$this->newProvider(['custom_table' => $this->custom_table, 'grid' => $this->grid])];
        foreach ($this->relations as $relation) {
            $providers[] = $this->newProvider([
                'custom_table' => $relation->child_custom_table,
                'grid' => $this->grid,
                'parent_table' => $this->custom_table->table_name,
            ]);
        }

        $datalist = [];
        foreach ($providers as $provider) {
            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        }

        return $datalist;
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function newProvider(array $args): ExportProbeProvider
    {
        $provider = new ExportProbeProvider($args);
        $provider->preload = $this->preload;

        return $provider;
    }
}
