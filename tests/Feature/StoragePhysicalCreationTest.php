<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Jobs\StorageConversionJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\PhysicalColumnLifecycle;
use Exceedone\Exment\Services\Storage\PhysicalNaming;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\PhysicalTableInitializer;
use Exceedone\Exment\Services\Storage\StagingCleanup;
use Exceedone\Exment\Services\Storage\StorageRunRepository;
use Exceedone\Exment\Services\Storage\StorageTransitions;
use Exceedone\Exment\Services\Storage\StorageVerifier;
use Exceedone\Exment\Services\Storage\TableStorage;
use Exceedone\Exment\Services\Storage\UnavailableConversion;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business table created directly as physical and the column lifecycle of a physical table
 * (02 §16), on the ISOLATED test database only. NOT transactional (DDL): run it on a database restored
 * from the Stage B snapshot, like StorageStageBIntegrationTest. Execution is enabled by the test binding
 * of ConversionAvailability; production keeps UnavailableConversion.
 */
class StoragePhysicalCreationTest extends FeatureTestBase
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

    // ------------------------------------------------------------------ helpers

    private function newTable(string $name, array $options = []): CustomTable
    {
        CustomTable::create([
            'table_name' => $name,
            'table_view_name' => $name,
            'options' => array_merge(['search_enabled' => 1, 'all_user_editable_flg' => '1'], $options),
        ]);
        System::clearRequestSession();
        $table = CustomTable::getEloquent($name);
        $table->createTable();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([$name => ltrim(getModelName($table, true), '\\')]);
        return $table;
    }

    private function addColumn(CustomTable $table, string $name, string $type, array $options = []): CustomColumn
    {
        $column = CustomColumn::create([
            'custom_table_id' => $table->id,
            'column_name' => $name,
            'column_view_name' => $name,
            'column_type' => $type,
            'options' => $options,
        ]);
        System::clearCache();
        return $column;
    }

    private function fresh(string $name): CustomTable
    {
        System::clearCache();
        System::clearRequestSession();
        return CustomTable::getEloquent($name);
    }

    private function storage(CustomTable $table): TableStorage
    {
        return TableStorage::fresh($table);
    }

    private function pcol(CustomTable $table, string $column): string
    {
        return PhysicalNaming::stagingColumn(CustomColumn::getEloquent($column, $table));
    }

    private function initializer(): PhysicalTableInitializer
    {
        return app(PhysicalTableInitializer::class);
    }

    private function refused(\Closure $call, string $expect = ''): string
    {
        try {
            $call();
        } catch (StorageConversionException $ex) {
            if ($expect !== '') {
                $this->assertStringContainsString($expect, $ex->getMessage());
            }
            return $ex->getMessage();
        }
        $this->fail('expected a refusal' . ($expect ? ": $expect" : ''));
    }

    private function attestation(): DrainAttestation
    {
        return new DrainAttestation('integration-test', new \DateTimeImmutable(), array_fill_keys(DrainAttestation::REQUIRED, true), 'isolated test database; no other writer');
    }

    /** The claim of the initializer, committed, without the DDL: what an interrupted initialization leaves behind. */
    private function stuckInitializing(CustomTable $table): void
    {
        DB::transaction(function () use ($table) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            $this->assertNotNull($locked->transitionTo(StorageState::INITIALIZING, StorageMode::JSON, true));
        });
        System::clearCache();
    }

    private function auditOperations(CustomTable $table): array
    {
        return DB::table('custom_table_storage_transitions')->where('custom_table_id', $table->id)->orderBy('id')->get()
            ->map(function ($r) {
                return (string)(json_decode((string)$r->attestation, true)['operation'] ?? ($r->from_state . '>' . $r->to_state));
            })->all();
    }

    // ------------------------------------------------------------------ creation

    public function testCreateAsPhysicalFirstColumnsAndFirstRecord(): void
    {
        $table = $this->newTable('hyb_pc_basic');
        $db = getDBTableName($table);
        $this->assertTrue($this->storage($table)->isJson(), 'a new table is JSON until it is initialized');
        $this->assertFalse(Schema::hasColumn($db, PhysicalNaming::VALUE_ORIGINAL));

        $next = $this->initializer()->initialize($table, 1);
        $this->assertSame([StorageState::PHYSICAL_READ, StorageMode::PHYSICAL, 2], [$next->state(), $next->mode(), $next->generation()], 'claim and publication each fence a generation');
        $this->assertTrue(Schema::hasColumn($db, PhysicalNaming::VALUE_ORIGINAL), 'value_original exists once physical_read is published');
        $this->assertSame(['initialize_physical', 'initialize_published'], $this->auditOperations($table));
        // a request that did not run the transition sees it through the cached model
        $this->assertTrue(TableStorage::fromModel($this->fresh('hyb_pc_basic'))->readsPhysical());

        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $this->addColumn($table, 'day', ColumnType::DATE);
        $this->addColumn($table, 'amount', ColumnType::DECIMAL, ['decimal_digit' => '2']);
        $this->addColumn($table, 'tags', ColumnType::SELECT, ['select_item' => "a\nb\nc", 'multiple_enabled' => '1']);
        $table = $this->fresh('hyb_pc_basic');
        foreach (['num', 'label', 'day', 'amount'] as $name) {
            $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, $name)), "typed column of $name");
        }
        $this->assertFalse(Schema::hasColumn($db, PhysicalNaming::STAGING_PREFIX . CustomColumn::getEloquent('tags', $table)->suuid), 'a multi-valued column stays in JSON');

        $m = $table->getValueModel();
        $m->setValue(['num' => '12', 'label' => 'Alpha', 'day' => '2024-01-02', 'amount' => '1.50', 'tags' => ['a', 'b']])->save();
        $row = (array)DB::table($db)->where('id', $m->id)->first();
        $this->assertSame(12, (int)$row[$this->pcol($table, 'num')]);
        $this->assertSame('Alpha', (string)$row[$this->pcol($table, 'label')]);
        $json = json_decode((string)$row['value'], true);
        $this->assertSame('Alpha', $json['label'], 'the JSON shadow is complete');
        $this->assertSame(['a', 'b'], $json['tags']);
        $this->assertSame('Alpha', $table->getValueModel()->find($m->id)->getValue('label'));
        $this->assertSame([], json_decode((string)$row[PhysicalNaming::VALUE_ORIGINAL] ?: '[]', true), 'nothing needs value_original');
    }

    public function testNonEmptyTableIsRefusedIncludingSoftDeletedRowsAndLeavesNothing(): void
    {
        $table = $this->newTable('hyb_pc_nonempty');
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_nonempty');
        $m = $table->getValueModel();
        $m->setValue(['label' => 'x'])->save();
        $m->delete(); // soft deleted: still a row
        $before = $this->storage($table)->toArray();
        $this->refused(function () use ($table) {
            $this->initializer()->initialize($table, 1);
        }, 'already has records');
        $this->assertSame($before, $this->storage($table)->toArray(), 'the claim was rolled back: state and generation unchanged');
        $this->assertSame([], $this->auditOperations($table));
        $this->assertFalse(Schema::hasColumn(getDBTableName($table), PhysicalNaming::VALUE_ORIGINAL), 'no DDL ran');
    }

    public function testSystemTablesAndUnavailabilityStayJson(): void
    {
        $user = CustomTable::getEloquent('user');
        $this->refused(function () use ($user) {
            $this->initializer()->initialize($user, 1);
        }, 'cannot be created as physical');
        $this->assertTrue($this->storage($user)->isJson());

        $this->app->instance(ConversionAvailability::class, new UnavailableConversion());
        $this->assertFalse(app(PhysicalTableInitializer::class)->isOffered(), 'production: the choice is not offered');
        $table = $this->newTable('hyb_pc_unavail');
        $this->refused(function () use ($table) {
            app(PhysicalTableInitializer::class)->initialize($table, 1);
        }, 'not available');
        $this->assertTrue($this->storage($table)->isJson());
    }

    // ------------------------------------------------------------------ interrupted initialization

    public function testInitializingBlocksDataAccessAllowsAdministrationAndResumesAfterPartialDdl(): void
    {
        $table = $this->newTable('hyb_pc_stuck');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_stuck');
        $db = getDBTableName($table);

        $this->stuckInitializing($table);
        $table = $this->fresh('hyb_pc_stuck');
        $this->assertSame(StorageState::INITIALIZING, $this->storage($table)->state());

        // application data access is closed: writes (gate) and reads (scope)
        $this->refused(function () use ($table) {
            $table->getValueModel()->setValue(['label' => 'x'])->save();
        }, 'write refused');
        $this->refused(function () use ($table) {
            $table->getValueModel()->query()->count();
        }, 'data access refused');
        // administration works: the column list is readable, and columns cannot change meanwhile
        $this->assertCount(2, $table->custom_columns_cache);
        $this->refused(function () use ($table) {
            $this->addColumn($table, 'extra', ColumnType::TEXT);
        }, 'column create refused');

        // a partial DDL: value_original and ONE of the two typed columns exist
        $manager = app(PhysicalSchemaManager::class);
        $manager->ensureValueOriginal($table);
        $manager->addColumnDdl($table, CustomColumn::getEloquent('num', $table));
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, 'num')));
        $this->assertFalse(Schema::hasColumn($db, $this->pcol($table, 'label')));

        // retry resumes from the live schema (idempotent) and publishes
        $next = $this->initializer()->initialize($table, 1);
        $this->assertSame([StorageState::PHYSICAL_READ, StorageMode::PHYSICAL], [$next->state(), $next->mode()]);
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, 'label')));
        $table = $this->fresh('hyb_pc_stuck');
        $table->getValueModel()->setValue(['num' => '7', 'label' => 'ok'])->save();
        $this->assertSame(1, $table->getValueModel()->query()->count());
        // the resume did not claim again
        $this->assertSame(1, count(array_keys($this->auditOperations($table), 'initialize_published')));
    }

    public function testBlockerRefusesBeforeAnythingIsClaimed(): void
    {
        $table = $this->newTable('hyb_pc_blocked');
        $column = $this->addColumn($table, 'num', ColumnType::INTEGER);
        $table = $this->fresh('hyb_pc_blocked');
        $db = getDBTableName($table);
        // the name of the typed column is taken by something else (a varchar): never altered, a blocker
        $name = PhysicalNaming::STAGING_PREFIX . $column->suuid;
        Schema::table($db, function ($blueprint) use ($name) {
            $blueprint->string($name, 10)->nullable();
        });
        System::clearCache();
        $before = $this->storage($table)->toArray();
        $this->refused(function () use ($table) {
            $this->initializer()->initialize($table, 1);
        }, 'cannot be created as physical');
        $this->assertSame($before, $this->storage($table)->toArray(), 'nothing was claimed');
        Schema::table($db, function ($blueprint) use ($name) {
            $blueprint->dropColumn($name);
        });
        System::clearCache();
        $this->assertSame(StorageState::PHYSICAL_READ, $this->initializer()->initialize($this->fresh('hyb_pc_blocked'), 1)->state());
    }

    public function testCancelWhileInitializing(): void
    {
        $table = $this->newTable('hyb_pc_cancel2');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $table = $this->fresh('hyb_pc_cancel2');
        $db = getDBTableName($table);
        $this->stuckInitializing($table);
        $table = $this->fresh('hyb_pc_cancel2');
        app(PhysicalSchemaManager::class)->ensureValueOriginal($table);
        app(PhysicalSchemaManager::class)->addColumnDdl($table, CustomColumn::getEloquent('num', $table));

        // cancel is only for a table in `initializing`
        $other = $this->newTable('hyb_pc_cancel_json');
        $this->refused(function () use ($other) {
            $this->initializer()->cancel($other, 1);
        }, 'needs state initializing');

        $next = $this->initializer()->cancel($table, 1);
        $this->assertSame([StorageState::JSON, StorageMode::JSON], [$next->state(), $next->mode()]);
        $this->assertFalse(Schema::hasColumn($db, PhysicalNaming::VALUE_ORIGINAL));
        $this->assertFalse(Schema::hasColumn($db, $this->pcol($table, 'num')));
        $this->assertContains('initialize_cancelled', $this->auditOperations($table));
        // plain JSON again: data and column changes work as before
        $table = $this->fresh('hyb_pc_cancel2');
        $table->getValueModel()->setValue(['num' => '3'])->save();
        $this->addColumn($table, 'more', ColumnType::TEXT);
        $this->assertFalse(Schema::hasColumn($db, PhysicalNaming::STAGING_PREFIX . CustomColumn::getEloquent('more', $this->fresh('hyb_pc_cancel2'))->suuid));
    }

    // ------------------------------------------------------------------ column lifecycle

    private function physicalTable(string $name, array $tableOptions = []): CustomTable
    {
        $table = $this->newTable($name, $tableOptions);
        $this->initializer()->initialize($table, 1);
        return $this->fresh($name);
    }

    public function testColumnUpdateRefusalsMutateNothing(): void
    {
        $table = $this->physicalTable('hyb_pc_update');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'kind', ColumnType::SELECT, ['select_item' => "a\nb"]);
        $table = $this->fresh('hyb_pc_update');
        $db = getDBTableName($table);
        $columns = DB::table($db)->get()->count();
        $schemaBefore = Schema::getColumnListing($db);

        // a label needs no DDL
        $column = CustomColumn::getEloquent('num', $table);
        $column->column_view_name = 'Number';
        $column->save();
        $this->assertSame('Number', DB::table('custom_columns')->where('id', $column->id)->value('column_view_name'));

        // the type cannot change (the screen does not offer it; a template / API must not either)
        $column = CustomColumn::getEloquent('num', $this->fresh('hyb_pc_update'));
        $column->column_type = ColumnType::TEXT;
        $this->refused(function () use ($column) {
            $column->save();
        }, 'column_type cannot change');
        $this->assertSame(ColumnType::INTEGER, DB::table('custom_columns')->where('id', $column->id)->value('column_type'));
        // nor the name
        $column = CustomColumn::getEloquent('num', $this->fresh('hyb_pc_update'));
        $column->column_name = 'renamed';
        $this->refused(function () use ($column) {
            $column->save();
        }, 'column_name cannot change');
        $this->assertSame('num', DB::table('custom_columns')->where('id', $column->id)->value('column_name'));
        $this->assertSame($schemaBefore, Schema::getColumnListing($db), 'no DDL ran for a refused change');
        $this->assertSame($columns, DB::table($db)->count());
    }

    public function testSingleMultiSwitchNeedsAnEmptyColumnAndIsExplicit(): void
    {
        $table = $this->physicalTable('hyb_pc_multi');
        $this->addColumn($table, 'kind', ColumnType::SELECT, ['select_item' => "a\nb\nc"]);
        $table = $this->fresh('hyb_pc_multi');
        $db = getDBTableName($table);
        $suuid = CustomColumn::getEloquent('kind', $table)->suuid;
        $pcol = PhysicalNaming::STAGING_PREFIX . $suuid;
        $this->assertTrue(Schema::hasColumn($db, $pcol));

        $m = $table->getValueModel();
        $m->setValue(['kind' => 'a'])->save();

        // single -> multi with a value in a row: refused before anything is mutated
        $column = CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'));
        $column->setOption('multiple_enabled', '1');
        $this->refused(function () use ($column) {
            $column->save();
        }, 'holds values');
        $this->assertTrue(Schema::hasColumn($db, $pcol), 'the typed column is still there');
        $this->assertFalse(CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'))->isMultipleEnabled(), 'the metadata did not change');

        // a soft-deleted row still holds the value: still refused (raw check)
        $m->delete();
        $column = CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'));
        $column->setOption('multiple_enabled', '1');
        $this->refused(function () use ($column) {
            $column->save();
        }, 'holds values');

        // the column is empty: allowed, the typed column is dropped
        DB::table($db)->delete();
        $column = CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'));
        $column->setOption('multiple_enabled', '1');
        $column->save();
        System::clearCache();
        $this->assertTrue(CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'))->isMultipleEnabled());
        $this->assertFalse(Schema::hasColumn($db, $pcol), 'multi-valued: JSON only');

        // multi -> single (empty again): the typed column is added back
        $column = CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'));
        $column->setOption('multiple_enabled', '0');
        $column->save();
        System::clearCache();
        $this->assertFalse(CustomColumn::getEloquent('kind', $this->fresh('hyb_pc_multi'))->isMultipleEnabled());
        $this->assertTrue(Schema::hasColumn($db, $pcol));
        $table = $this->fresh('hyb_pc_multi');
        $table->getValueModel()->setValue(['kind' => 'b'])->save();
        $this->assertSame('b', (string)DB::table($db)->value($pcol));
    }

    public function testColumnDeleteDropsTheTypedColumnAndTheJsonKey(): void
    {
        $table = $this->physicalTable('hyb_pc_delete');
        $this->addColumn($table, 'num', ColumnType::INTEGER, ['index_enabled' => '1']);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_delete');
        $db = getDBTableName($table);
        $num = $this->pcol($table, 'num');
        $m = $table->getValueModel();
        $m->setValue(['num' => '5', 'label' => 'keep'])->save();
        $gone = $table->getValueModel();
        $gone->setValue(['num' => '9', 'label' => 'soft'])->save();
        $gone->delete();
        $this->assertTrue(Schema::hasColumn($db, $num));

        CustomColumn::getEloquent('num', $table)->delete();
        System::clearCache();
        $this->assertFalse(Schema::hasColumn($db, $num), 'the typed column is dropped');
        foreach (DB::table($db)->get() as $row) {
            $this->assertArrayNotHasKey('num', json_decode((string)$row->value, true), "row {$row->id}: JSON key removed, soft-deleted rows included");
        }
        $this->assertSame('keep', json_decode((string)DB::table($db)->where('id', $m->id)->value('value'), true)['label']);
        // the table still works
        $table = $this->fresh('hyb_pc_delete');
        $table->getValueModel()->setValue(['label' => 'next'])->save();
        $this->assertSame(3, DB::table($db)->count());
    }

    public function testReconcileRepairsAnInterruptedColumnStep(): void
    {
        $table = $this->physicalTable('hyb_pc_reconcile');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_reconcile');
        $db = getDBTableName($table);
        $label = $this->pcol($table, 'label');

        // the typed column of a defined column is missing (a lost ADD) and an unowned one is left over (a lost DROP)
        Schema::table($db, function ($blueprint) use ($label) {
            $blueprint->dropColumn($label);
        });
        Schema::table($db, function ($blueprint) {
            $blueprint->integer('pcol_orphan1')->nullable();
        });
        System::clearCache();
        $this->assertSame(['pcol_orphan1'], app(PhysicalSchemaManager::class)->orphanStagingColumns($table));

        $result = app(PhysicalColumnLifecycle::class)->reconcile($table, 1);
        $this->assertSame([$label], $result['added']);
        $this->assertSame(['pcol_orphan1'], $result['dropped']);
        $this->assertTrue(Schema::hasColumn($db, $label));
        $this->assertFalse(Schema::hasColumn($db, 'pcol_orphan1'));
        $this->assertContains('reconcile_schema', $this->auditOperations($table));
        // idempotent
        $again = app(PhysicalColumnLifecycle::class)->reconcile($table, 1);
        $this->assertSame([[], []], [$again['added'], $again['dropped']]);
    }

    public function testColumnChangesAreRefusedOutsidePhysicalRead(): void
    {
        // a JSON table: unchanged behaviour (virtual index column, no typed column)
        $json = $this->newTable('hyb_pc_json_col');
        $column = $this->addColumn($json, 'num', ColumnType::INTEGER, ['index_enabled' => '1']);
        $this->assertFalse(Schema::hasColumn(getDBTableName($json), PhysicalNaming::STAGING_PREFIX . $column->suuid));
        $this->assertTrue(Schema::hasColumn(getDBTableName($json), $column->getIndexColumnName(false)), 'the generated index column of a JSON table');

        // a table in the conversion (dual-write) still refuses
        $conv = $this->newTable('hyb_pc_conv_col');
        $this->addColumn($conv, 'num', ColumnType::INTEGER);
        $conv = $this->fresh('hyb_pc_conv_col');
        app(PhysicalSchemaManager::class)->provision($conv);
        app(StorageTransitions::class)->startDualWrite($conv);
        $this->refused(function () use ($conv) {
            $this->addColumn($conv, 'extra', ColumnType::TEXT);
        }, 'column create refused');
    }

    public function testRestrictedUserSeesTheSameRowsAsOnAJsonTable(): void
    {
        $physical = $this->physicalTable('hyb_pc_perm_p', ['all_user_editable_flg' => '0', 'all_user_viewable_flg' => '0', 'all_user_accessable_flg' => '0']);
        $json = $this->newTable('hyb_pc_perm_j', ['all_user_editable_flg' => '0', 'all_user_viewable_flg' => '0', 'all_user_accessable_flg' => '0']);
        foreach ([$physical, $json] as $t) {
            $this->addColumn($t, 'label', ColumnType::TEXT);
        }
        $counts = [];
        foreach (['hyb_pc_perm_p', 'hyb_pc_perm_j'] as $name) {
            $t = $this->fresh($name);
            $t->getValueModel()->setValue(['label' => 'a'])->save();
            $t->getValueModel()->setValue(['label' => 'b'])->save();
        }
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER1] as $login) {
            $this->be(LoginUser::find($login));
            System::clearRequestSession();
            foreach (['hyb_pc_perm_p', 'hyb_pc_perm_j'] as $name) {
                $counts[$login][$name] = $this->fresh($name)->getValueModel()->query()->count();
            }
            $this->assertSame($counts[$login]['hyb_pc_perm_j'], $counts[$login]['hyb_pc_perm_p'], "$login sees the same on physical and JSON");
        }
        $this->assertSame(2, $counts[TestDefine::TESTDATA_USER_LOGINID_ADMIN]['hyb_pc_perm_p']);
        // whatever the fixture grants to the second user, a physical table grants exactly the same as a JSON twin (asserted above)
    }

    // ------------------------------------------------------------------ copy and template

    public function testCopyIsSchemaOnlyAndInitializesAsPhysicalOnRequest(): void
    {
        $src = $this->newTable('hyb_pc_copy_src');
        $this->addColumn($src, 'num', ColumnType::INTEGER);
        $this->addColumn($src, 'tags', ColumnType::SELECT, ['select_item' => "a\nb", 'multiple_enabled' => '1']);
        $src = $this->fresh('hyb_pc_copy_src');
        $src->getValueModel()->setValue(['num' => '1'])->save();

        $result = $src->copyTable(['table_name' => 'hyb_pc_copy_dst', 'table_view_name' => 'copy'], false, false);
        $this->assertTrue($result['result']);
        $dst = $this->fresh('hyb_pc_copy_dst');
        $dstDb = getDBTableName($dst);
        $this->assertTrue($this->storage($dst)->isJson(), 'a copy starts as JSON');
        $this->assertSame(0, DB::table($dstDb)->count(), 'schema only: no record is copied');
        $this->assertCount(2, $dst->custom_columns_cache);

        $this->initializer()->initialize($dst, 1);
        $dst = $this->fresh('hyb_pc_copy_dst');
        $this->assertSame(StorageState::PHYSICAL_READ, $this->storage($dst)->state());
        $this->assertTrue(Schema::hasColumn($dstDb, $this->pcol($dst, 'num')));
        $this->assertTrue($this->storage($this->fresh('hyb_pc_copy_src'))->isJson(), 'the source keeps its mode');
        $dst->getValueModel()->setValue(['num' => '2'])->save();
        $this->assertSame(2, (int)DB::table($dstDb)->value($this->pcol($dst, 'num')));
    }

    public function testCopyOfATableWithoutColumnsHasItsValueTableCreatedBeforeInitialization(): void
    {
        $src = $this->newTable('hyb_pc_copy_empty_src');
        $result = $this->fresh('hyb_pc_copy_empty_src')->copyTable(['table_name' => 'hyb_pc_copy_empty_dst', 'table_view_name' => 'copy'], false, false);
        $this->assertTrue($result['result']);
        $dst = $this->fresh('hyb_pc_copy_empty_dst');
        $dst->createTable(); // what the controller does before initializing
        $this->assertSame(StorageState::PHYSICAL_READ, $this->initializer()->initialize($dst, 1)->state());
    }

    /** @return array<string, mixed> */
    private function templateJson(string $table, array $columns): array
    {
        return ['custom_tables' => [[
            'table_name' => $table,
            'table_view_name' => $table,
            'description' => '',
            'options' => ['search_enabled' => 1],
            'custom_columns' => array_map(function ($c) {
                return ['column_name' => $c[0], 'column_view_name' => $c[0], 'column_type' => $c[1], 'options' => $c[2] ?? []];
            }, $columns),
        ]]];
    }

    public function testTemplateImportCreatesPhysicalTablesBeforeAnyDataAndLeavesExistingTablesAlone(): void
    {
        $importer = (new \Exceedone\Exment\Services\TemplateImportExport\TemplateImporter())->setStorageMode(StorageMode::PHYSICAL);
        $importer->import($this->templateJson('hyb_pc_tpl_new', [['a', ColumnType::TEXT], ['n', ColumnType::INTEGER]]));
        $new = $this->fresh('hyb_pc_tpl_new');
        $db = getDBTableName($new);
        $this->assertSame([StorageState::PHYSICAL_READ, StorageMode::PHYSICAL], [$this->storage($new)->state(), $this->storage($new)->mode()]);
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($new, 'n')));
        $this->assertTrue(Schema::hasColumn($db, PhysicalNaming::VALUE_ORIGINAL), 'the schema is ready before data can be loaded');

        // the same template again as an UPDATE: the existing table keeps its mode; a new column goes through the lifecycle
        $importer->import($this->templateJson('hyb_pc_tpl_new', [['a', ColumnType::TEXT], ['n', ColumnType::INTEGER], ['added', ColumnType::DATE]]), false, true);
        $new = $this->fresh('hyb_pc_tpl_new');
        $this->assertSame(StorageState::PHYSICAL_READ, $this->storage($new)->state());
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($new, 'added')), 'an update added the typed column');

        // JSON import (the default) is unchanged
        (new \Exceedone\Exment\Services\TemplateImportExport\TemplateImporter())->import($this->templateJson('hyb_pc_tpl_json', [['a', ColumnType::TEXT]]));
        $json = $this->fresh('hyb_pc_tpl_json');
        $this->assertTrue($this->storage($json)->isJson());
        $this->assertFalse(Schema::hasColumn(getDBTableName($json), PhysicalNaming::VALUE_ORIGINAL));
    }

    public function testTemplateImportUpdateNeverChangesTheTypeOfAnExistingColumnOfAPhysicalTable(): void
    {
        $importer = (new \Exceedone\Exment\Services\TemplateImportExport\TemplateImporter())->setStorageMode(StorageMode::PHYSICAL);
        $importer->import($this->templateJson('hyb_pc_tpl_chg', [['n', ColumnType::INTEGER]]));
        $table = $this->fresh('hyb_pc_tpl_chg');
        $db = getDBTableName($table);
        $schema = Schema::getColumnListing($db);
        // the update either ignores the type of an existing column or is refused; it never reaches the schema
        try {
            (new \Exceedone\Exment\Services\TemplateImportExport\TemplateImporter())->import($this->templateJson('hyb_pc_tpl_chg', [['n', ColumnType::TEXT]]), false, true);
        } catch (StorageConversionException $ex) {
            $this->assertStringContainsString('column_type cannot change', $ex->getMessage());
        }
        $this->assertSame($schema, Schema::getColumnListing($db), 'no DDL');
        $this->assertSame(ColumnType::INTEGER, CustomColumn::getEloquent('n', $this->fresh('hyb_pc_tpl_chg'))->column_type);
        $this->assertSame(StorageState::PHYSICAL_READ, $this->storage($this->fresh('hyb_pc_tpl_chg'))->state());
    }

    // ------------------------------------------------------------------ return to JSON

    public function testReturnToJsonOfADirectlyCreatedTableNeedsAPassedVerificationAndKeepsTheSteps(): void
    {
        $table = $this->physicalTable('hyb_pc_back');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_back');
        $db = getDBTableName($table);
        foreach ([1, 2, 3] as $i) {
            $table->getValueModel()->setValue(['num' => (string)$i, 'label' => "r$i"])->save();
        }
        $jsonBefore = DB::table($db)->orderBy('id')->pluck('value', 'id')->all();
        $transitions = app(StorageTransitions::class);

        // no conversion history: the JSON shadow must first be validated (verification runs in physical_read)
        $this->refused(function () use ($transitions, $table) {
            $transitions->rollback($table, $this->attestation(), false);
        }, 'no passed reconciliation');
        $verify = app(StorageVerifier::class)->run($table, 500, 100);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify['result'], json_encode($verify));

        $transitions->rollback($table, $this->attestation(), false);
        System::clearCache();
        $this->assertSame([StorageState::DUALWRITE, StorageMode::JSON], [$this->storage($table)->state(), $this->storage($table)->mode()], 'rollback only changes the read source');
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, 'num')), 'rollback does not drop columns');
        $this->assertSame($jsonBefore, DB::table($db)->orderBy('id')->pluck('value', 'id')->all());

        // cancel -> cleanup -> deprovision are three separate operations
        $transitions->cancel($table);
        $this->assertSame(StorageState::PROVISIONED, $this->storage($table)->state());
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, 'num')));
        $cleanup = app(StagingCleanup::class);
        $cleanup->startConfirmed($table, 'hyb_pc_back');
        (new StorageConversionJob((int)$table->id, StorageConversionJob::OP_CLEANUP, $this->storage($table)->generation()))->handle();
        $this->assertSame(0, DB::table($db)->whereNotNull($this->pcol($table, 'num'))->count());
        $this->assertTrue(Schema::hasColumn($db, $this->pcol($table, 'num')), 'cleanup empties, it does not drop');
        app(PhysicalSchemaManager::class)->deprovision($table);
        System::clearCache();
        $this->assertTrue($this->storage($table)->isJson());
        $this->assertFalse(Schema::hasColumn($db, $this->pcol($table, 'num')));
        $this->assertSame($jsonBefore, DB::table($db)->orderBy('id')->pluck('value', 'id')->all(), 'the JSON is untouched by the whole return');
        $this->assertSame('r2', $this->fresh('hyb_pc_back')->getValueModel()->find(DB::table($db)->orderBy('id')->skip(1)->value('id'))->getValue('label'));
    }

    public function testWriterOfAConcurrentTransitionWaitsForTheExclusiveClaim(): void
    {
        // the claim holds the EXCLUSIVE storage lock before it looks at the rows: a write that started under
        // the previous state finished before; one that starts after sees `initializing`. Both outcomes are
        // exercised here without a second connection: after the claim a write is refused (gate), before it a
        // row blocks the initialization.
        $table = $this->newTable('hyb_pc_order');
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->fresh('hyb_pc_order');
        $table->getValueModel()->setValue(['label' => 'before'])->save();
        $this->refused(function () use ($table) {
            $this->initializer()->initialize($table, 1);
        }, 'already has records');
        DB::table(getDBTableName($table))->delete();
        $this->assertSame(StorageState::PHYSICAL_READ, $this->initializer()->initialize($this->fresh('hyb_pc_order'), 1)->state());
    }

    public function testTheMenuOfTheConversionPageIsOnlyForSystemAdministratorsWhereItIsOffered(): void
    {
        $item = ['menu_type' => 'system', 'menu_name' => 'storage_conversion', 'menu_target' => 'storage_conversion', 'uri' => 'storage_conversion'];
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);

        System::clearRequestSession();
        $this->assertTrue(\Exceedone\Exment\Services\MenuVisibility::forUser($admin)->isVisible($item), 'system administrator, conversion offered');
        System::clearRequestSession();
        $this->assertFalse(\Exceedone\Exment\Services\MenuVisibility::forUser($user)->isVisible($item), 'a user without the system permission never sees it');

        // production: the page is not offered, so the menu is not shown (the page itself says it is unavailable)
        $this->app->instance(ConversionAvailability::class, new UnavailableConversion());
        System::clearRequestSession();
        $this->assertFalse(\Exceedone\Exment\Services\MenuVisibility::forUser($admin)->isVisible($item), 'conversion unavailable');
    }

    public function testTheConversionPageStillRefusesAUserWithoutTheSystemPermission(): void
    {
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertFalse($user->hasPermission(\Exceedone\Exment\Enums\Permission::SYSTEM));
        $this->be($user);
        System::clearRequestSession();
        $this->assertFalse($user->allPermissions()->contains(function ($permission) {
            return $permission->shouldPassEndpoint('storage_conversion');
        }));
    }
}
