<?php

namespace Exceedone\Exment\Tests\Support;

use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
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

/**
 * Fixture of the tests that create business tables and convert them to physical storage on an ISOLATED
 * database. These tests are NOT transactional (they run DDL): restore the database snapshot before each run.
 */
trait IsolatedStorageFixture
{
    /** Skips unless the isolated database guards pass; allows conversion and logs in as admin. */
    protected function isolatedStorageSetUp(): void
    {
        $c = config('database.connections.' . config('database.default'));
        $legacy = (int)$c['port'] === 3408 && $c['database'] === 'hyb_p1_test';
        if (!($legacy || IsolatedConversionExecutionProvider::guardsPass($this->app)) || !app()->environment('testing')) {
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
}
