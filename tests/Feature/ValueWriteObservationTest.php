<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\ValueWriteObservation;
use Exceedone\Exment\Model\ValueWriteObserver;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * Hybrid storage P1 W6: the value writes Exment can see coming are observed once, before their
 * SQL, through their production callers - and the known blind spots stay blind.
 *
 * Observed: prepared (API update / create), saving_disabled (exment:patchdata
 * convert_select_table_column), restore_revision (POST data/{table}/{id}/compare),
 * bulk_remove_key (column deletion), bulk_set_key (exment:patchdata rmcomma).
 * Not observed (pinned): saveQuietly, a query-builder JSON update.
 */
class ValueWriteObservationTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    /**
     * A recording observer, and one timeline of observations and of the statements that wrote a
     * value table (statements are logged after they ran, observations when told).
     */
    private function recorder(): object
    {
        $recorder = new class () implements ValueWriteObserver {
            /** @var array<int, array{kind: string, db: string, id: mixed, column: ?string}> */
            public array $seen = [];
            /** @var array<int, string> "obs:<db>" or "sql:<statement>" */
            public array $timeline = [];

            public function observe(ValueWriteObservation $observation): void
            {
                $this->seen[] = [
                    'kind' => $observation->kind(),
                    'db' => $observation->dbTableName(),
                    'id' => $observation->id(),
                    'column' => $observation->columnName(),
                ];
                $this->timeline[] = 'obs:' . $observation->dbTableName();
            }
        };
        \DB::listen(function ($query) use ($recorder) {
            $recorder->timeline[] = 'sql:' . $query->sql;
        });
        app()->instance(ValueWriteObserver::class, $recorder);
        return $recorder;
    }

    /**
     * The timeline of one table as "obs" / "write": each observation must come right before the
     * write it announces.
     *
     * @return array<int, string>
     */
    private function sequence(object $rec, string $db): array
    {
        $out = [];
        foreach ($rec->timeline as $entry) {
            if ($entry === 'obs:' . $db) {
                $out[] = 'obs';
            } elseif (stripos($entry, 'sql:update ' . static::q($db)) === 0 || stripos($entry, 'sql:insert into ' . static::q($db)) === 0) {
                $out[] = 'write';
            }
        }
        return $out;
    }

    private function table(string $name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL): CustomTable
    {
        return CustomTable::getEloquent($name);
    }

    private function firstId(CustomTable $table): int
    {
        return intval(\DB::table(getDBTableName($table))->whereNull('deleted_at')->orderBy('id')->value('id'));
    }

    private function stored(CustomTable $table, int $id): array
    {
        return json_decode(\DB::table(getDBTableName($table))->where('id', $id)->value('value'), true);
    }

    // --------------------------------------------------------- single record

    public function testApiUpdateIsObservedOnceAsPreparedBeforeItsSql(): void
    {
        $table = $this->table();
        $db = getDBTableName($table);
        $id = $this->firstId($table);
        $before = $this->stored($table, $id);
        $rec = $this->recorder();

        $request = Request::create('/w6', 'PUT', ['value' => ['text' => 'w6 api']]);
        app()->instance('request', $request);
        (new ApiDataController($table, $request))->dataUpdate($request, $table->table_name, $id);

        $this->assertSame([['kind' => ValueWriteObservation::KIND_PREPARED, 'db' => $db, 'id' => $id, 'column' => null]], $this->onTable($rec, $db));
        $this->assertPluginSaveObserved($rec, $id);
        $this->assertSame(['obs', 'write'], $this->sequence($rec, $db), 'told before the UPDATE');
        $this->assertEquals(array_merge($before, ['text' => 'w6 api']), $this->stored($table, $id), 'persisted as before');
    }

    public function testCreateIsObservedWithoutAnId(): void
    {
        $table = $this->table();
        $rec = $this->recorder();
        $model = $table->getValueModel();
        $model->setValue(['text' => 'w6 create'])->save();
        $this->assertSame([['kind' => ValueWriteObservation::KIND_PREPARED, 'db' => getDBTableName($table), 'id' => null, 'column' => null]], $this->normalized($rec->seen));
        $this->assertSame(['obs', 'write'], $this->sequence($rec, getDBTableName($table)), 'told before the INSERT');
        $this->assertSame('w6 create', $this->stored($table, intval($model->id))['text']);
    }

    public function testDisabledSavingEventIsObservedAsSuchThroughThePatchCommand(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $db = getDBTableName($table);
        $id = $this->firstId($table);
        // a select_table value stored as a number, as before v3: the patch converts it to a string
        static::rawJsonWrite($db, $id, ['select_table' => 5]);
        $rec = $this->recorder();

        $this->assertSame(0, \Artisan::call('exment:patchdata', ['action' => 'convert_select_table_column']));

        $mine = array_values(array_filter($this->normalized($rec->seen), function ($s) use ($db, $id) {
            return $s['db'] === $db && $s['id'] === $id;
        }));
        $this->assertSame([['kind' => ValueWriteObservation::KIND_SAVING_DISABLED, 'db' => $db, 'id' => $id, 'column' => null]], $mine);
        $this->assertSame([], array_values(array_filter($rec->seen, function ($s) {
            return $s['kind'] !== ValueWriteObservation::KIND_SAVING_DISABLED;
        })), 'no prepare on this path');
        $saves = count(array_filter($rec->seen, function ($s) use ($db) {
            return $s['db'] === $db;
        }));
        $this->assertSame(array_merge(...array_fill(0, $saves, ['obs', 'write'])), $this->sequence($rec, $db), 'each told before its UPDATE');
        $this->assertSame('5', $this->stored($table, $id)['select_table']);
    }

    public function testRestoreRevisionIsObservedThroughTheScreen(): void
    {
        $table = $this->table();
        $id = $this->firstId($table);
        $model = $table->getValueModel($id);
        $model->setValue('text', 'w6 rev 1')->save();
        $model->setValue('text', 'w6 rev 2')->save();
        $revision = $model->revisionHistory()->get()->first(function ($r) {
            return array_get(json_decode($r->new_value, true), 'text') === 'w6 rev 1';
        });
        $this->assertNotNull($revision);
        System::clearRequestSession();
        $rec = $this->recorder();

        $this->post(admin_url("data/{$table->table_name}/{$id}/compare"), ['revision' => $revision->suuid]);

        $this->assertSame([['kind' => ValueWriteObservation::KIND_RESTORE_REVISION, 'db' => getDBTableName($table), 'id' => $id, 'column' => null]], $this->onTable($rec, getDBTableName($table)));
        $this->assertPluginSaveObserved($rec, $id);
        $this->assertSame(['obs', 'write'], $this->sequence($rec, getDBTableName($table)), 'told before the UPDATE');
        $this->assertSame('w6 rev 1', $this->stored($table, $id)['text']);
    }

    public function testQuietSavesAndQueryBuilderWritesAreNotObserved(): void
    {
        $table = $this->table();
        $db = getDBTableName($table);
        $id = $this->firstId($table);
        $rec = $this->recorder();

        $model = $table->getValueModel($id);
        $model->setValue('text', 'w6 quiet');
        $model->saveQuietly();
        static::rawJsonWrite($db, $id, ['index_text' => 'w6 raw']);

        $this->assertSame([], $rec->seen, 'blind spots: saveQuietly and query-builder writes');
        $this->assertSame('w6 quiet', $this->stored($table, $id)['text']);
        $this->assertSame('w6 raw', $this->stored($table, $id)['index_text']);
    }

    // ------------------------------------------------------------ set-based

    public function testColumnDeletionIsObservedOnceAsBulkRemoveKey(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NO_PERMISSION);
        $db = getDBTableName($table);
        $column = CustomColumn::create([
            'custom_table_id' => $table->id,
            'column_name' => 'w6_col',
            'column_view_name' => 'w6_col',
            'column_type' => ColumnType::TEXT,
            'options' => [],
        ]);
        $id = $this->firstId($table);
        static::rawJsonWrite($db, $id, ['w6_col' => 'x']);
        $rec = $this->recorder();

        CustomColumn::find($column->id)->delete();

        $this->assertSame([['kind' => ValueWriteObservation::KIND_BULK_REMOVE_KEY, 'db' => $db, 'id' => null, 'column' => 'w6_col']], $this->normalized($rec->seen));
        $this->assertSame(['obs', 'write'], $this->sequence($rec, $db), 'told before the JSON_REMOVE');
        $this->assertArrayNotHasKey('w6_col', $this->stored($table, $id));
    }

    public function testRmcommaIsObservedOncePerRowAsBulkSetKey(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NO_PERMISSION);
        $db = getDBTableName($table);
        $ids = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->limit(2)->pluck('id')->map(function ($v) {
            return intval($v);
        })->all();
        foreach ($ids as $id) {
            static::rawJsonWrite($db, $id, ['decimal' => '1,000']);
        }
        \Auth::logout();
        $rec = $this->recorder();

        $this->assertSame(0, \Artisan::call('exment:patchdata', ['action' => 'rmcomma']));

        $mine = array_values(array_filter($this->normalized($rec->seen), function ($s) use ($db) {
            return $s['db'] === $db;
        }));
        $this->assertSame(array_map(function ($id) use ($db) {
            return ['kind' => ValueWriteObservation::KIND_BULK_SET_KEY, 'db' => $db, 'id' => $id, 'column' => 'decimal'];
        }, $ids), $mine);
        $this->assertSame(['obs', 'write', 'obs', 'write'], $this->sequence($rec, $db), 'each JSON_SET told before it runs');
        foreach ($ids as $id) {
            $this->assertSame('1000', $this->stored($table, $id)['decimal']);
        }
    }

    /**
     * The observations of one table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function onTable(object $rec, string $db): array
    {
        return array_values(array_filter($this->normalized($rec->seen), function ($s) use ($db) {
            return $s['db'] === $db;
        }));
    }

    /**
     * The test plugin TestPluginEventSaved (event "saved" on custom_value_edit_all) saves the
     * custom_value_view_all record of the same id through the model: observed once as prepared,
     * before its UPDATE - a plugin write through the model is seen.
     */
    private function assertPluginSaveObserved(object $rec, int $id): void
    {
        $db = getDBTableName(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $this->assertSame([['kind' => ValueWriteObservation::KIND_PREPARED, 'db' => $db, 'id' => $id, 'column' => null]], $this->onTable($rec, $db));
        $this->assertSame(['obs', 'write'], $this->sequence($rec, $db));
    }

    /**
     * @param array<int, array<string, mixed>> $seen
     * @return array<int, array<string, mixed>>
     */
    private function normalized(array $seen): array
    {
        return array_map(function ($s) {
            $s['id'] = isset($s['id']) ? intval($s['id']) : null;
            return $s;
        }, $seen);
    }
}
