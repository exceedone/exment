<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\PhysicalTableInitializer;
use Exceedone\Exment\Services\Storage\StagingCleanup;
use Exceedone\Exment\Services\Storage\StorageRunRepository;
use Exceedone\Exment\Services\Storage\StorageTransitions;
use Exceedone\Exment\Services\Storage\StorageVerifier;
use Exceedone\Exment\Services\Storage\TableStorage;
use Exceedone\Exment\Jobs\StorageConversionJob;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With the persistent cache of Exment on (EXMENT_USE_CACHE): every change of the storage of a table and of its
 * columns is seen by the NEXT request (only the request memory is dropped between the steps, the cache store is
 * kept), for a table created directly as physical and for a table that is converted. NOT transactional (DDL):
 * isolated database only, like StoragePhysicalCreationTest.
 */
class StorageCacheVisibilityTest extends FeatureTestBase
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
        config(['exment.use_cache' => true, 'cache.default' => 'array']);
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearCache();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        config(['exment.use_cache' => false]);
        System::clearRequestSession();
        parent::tearDown();
    }

    /** the next request: the request memory is gone, the cache store is kept */
    private function nextRequest(string $name): CustomTable
    {
        System::clearRequestSession();
        return CustomTable::getEloquent($name);
    }

    private function newTable(string $name): CustomTable
    {
        CustomTable::create(['table_name' => $name, 'table_view_name' => $name, 'options' => ['search_enabled' => 1, 'all_user_editable_flg' => '1']]);
        System::clearRequestSession();
        $table = CustomTable::getEloquent($name);
        $table->createTable();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([$name => ltrim(getModelName($table, true), '\\')]);
        return $table;
    }

    private function addColumn(CustomTable $table, string $name, string $type, array $options = []): CustomColumn
    {
        return CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => $name, 'column_view_name' => $name, 'column_type' => $type, 'options' => $options]);
    }

    private function columnNames(CustomTable $table): array
    {
        return $table->custom_columns_cache->pluck('column_name')->all();
    }

    private function attestation(): DrainAttestation
    {
        return new DrainAttestation('integration-test', new \DateTimeImmutable(), array_fill_keys(DrainAttestation::REQUIRED, true), 'isolated test database; no other writer');
    }

    public function testATableCreatedAsPhysicalAndItsColumnsAreSeenByTheNextRequest(): void
    {
        $table = $this->newTable('hyb_cv_direct');
        $this->nextRequest('hyb_cv_direct'); // fills the cache with the JSON table
        $this->assertTrue($this->nextRequest('hyb_cv_direct')->getAttribute('storage_state') === StorageState::JSON);

        app(PhysicalTableInitializer::class)->initialize($table, 1);
        $seen = $this->nextRequest('hyb_cv_direct');
        $this->assertSame(StorageState::PHYSICAL_READ, $seen->getAttribute('storage_state'), 'the state published by the initializer is seen by the next request');
        $this->assertTrue(TableStorage::fromModel($seen)->readsPhysical());

        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->assertSame(['num'], $this->columnNames($this->nextRequest('hyb_cv_direct')), 'a new column is seen by the next request');
        $this->assertNotNull(CustomColumn::getEloquent('num', $this->nextRequest('hyb_cv_direct')));

        $m = $this->nextRequest('hyb_cv_direct')->getValueModel();
        $m->setValue(['num' => '4'])->save();
        $db = getDBTableName($table);
        $this->assertSame(4, (int)DB::table($db)->value(\Exceedone\Exment\Services\Storage\PhysicalNaming::stagingColumn(CustomColumn::getEloquent('num', $this->nextRequest('hyb_cv_direct')))));

        CustomColumn::getEloquent('num', $this->nextRequest('hyb_cv_direct'))->delete();
        $this->assertSame([], $this->columnNames($this->nextRequest('hyb_cv_direct')), 'a deleted column is gone for the next request');
        $this->assertNull(CustomColumn::getEloquent('num', $this->nextRequest('hyb_cv_direct')));
    }

    public function testEveryStepOfAConversionAndTheReturnToJsonIsSeenByTheNextRequest(): void
    {
        $table = $this->newTable('hyb_cv_conv');
        $this->addColumn($table, 'num', ColumnType::INTEGER);
        $this->addColumn($table, 'label', ColumnType::TEXT);
        $table = $this->nextRequest('hyb_cv_conv');
        $table->getValueModel()->setValue(['num' => '1', 'label' => 'a'])->save();
        $transitions = app(StorageTransitions::class);
        $state = function () {
            return $this->nextRequest('hyb_cv_conv')->getAttribute('storage_state');
        };
        $this->assertSame(StorageState::JSON, $state());

        app(PhysicalSchemaManager::class)->provision($table);
        $this->assertSame(StorageState::PROVISIONED, $state());
        $transitions->startDualWrite($table);
        $this->assertSame(StorageState::DUALWRITE, $state());
        sleep(1);
        $transitions->startBackfill($table, $this->attestation());
        $this->assertSame(StorageState::BACKFILLING, $state());
        app(\Exceedone\Exment\Services\Storage\StorageBackfill::class)->run($table, 500, 100);
        $transitions->startVerification($table);
        app(StorageVerifier::class)->run($table, 500, 100);
        $transitions->awaitConfirmation($table);
        $this->assertSame(StorageState::AWAITING_CONFIRMATION, $state());
        sleep(1);
        $run = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, TableStorage::fresh($table)->generation());
        $transitions->cutover($table, new \Exceedone\Exment\Services\Storage\CutoverConfirmation((int)\Exment::getUserId(), (int)$run->id, (int)$run->generation,
            \Exceedone\Exment\Services\Storage\StorageL5Report::fromRun($run)->version(), new \DateTimeImmutable()), $this->attestation());
        $seen = $this->nextRequest('hyb_cv_conv');
        $this->assertSame(StorageState::PHYSICAL_READ, $seen->getAttribute('storage_state'));
        $this->assertTrue(TableStorage::fromModel($seen)->readsPhysical(), 'the next request reads physical after the cutover');

        // a column on the converted table, then back to JSON
        $this->addColumn($table, 'extra', ColumnType::TEXT);
        $this->assertContains('extra', $this->columnNames($this->nextRequest('hyb_cv_conv')));
        CustomColumn::getEloquent('extra', $this->nextRequest('hyb_cv_conv'))->delete();
        $this->assertNotContains('extra', $this->columnNames($this->nextRequest('hyb_cv_conv')));

        $verify = app(StorageVerifier::class)->run($table, 500, 100);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify['result']);
        $transitions->rollback($table, $this->attestation(), false);
        $seen = $this->nextRequest('hyb_cv_conv');
        $this->assertSame(StorageState::DUALWRITE, $seen->getAttribute('storage_state'));
        $this->assertFalse(TableStorage::fromModel($seen)->readsPhysical(), 'the next request reads JSON after the rollback');
        $transitions->cancel($table);
        $this->assertSame(StorageState::PROVISIONED, $state());
        app(StagingCleanup::class)->startConfirmed($table, 'hyb_cv_conv');
        (new StorageConversionJob((int)$table->id, StorageConversionJob::OP_CLEANUP, TableStorage::fresh($table)->generation()))->handle();
        app(PhysicalSchemaManager::class)->deprovision($table);
        $this->assertSame(StorageState::JSON, $state());
        $this->assertFalse(Schema::hasColumn(getDBTableName($table), \Exceedone\Exment\Services\Storage\PhysicalNaming::VALUE_ORIGINAL));
    }
}
