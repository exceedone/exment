<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Jobs\StorageConversionJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\ValueBulkWriter;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\ConversionEligibility;
use Exceedone\Exment\Services\Storage\CutoverConfirmation;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\PhysicalNaming;
use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;
use Exceedone\Exment\Services\Storage\Projection;
use Exceedone\Exment\Services\Storage\StagingCleanup;
use Exceedone\Exment\Services\Storage\StorageBackfill;
use Exceedone\Exment\Services\Storage\StorageL5Report;
use Exceedone\Exment\Services\Storage\StorageRunRepository;
use Exceedone\Exment\Services\Storage\StorageTransitions;
use Exceedone\Exment\Services\Storage\TableStorage;
use Exceedone\Exment\Services\Storage\UnavailableConversion;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B integration (blocks 1-5) on the ISOLATED test database only. NOT transactional: it
 * creates tables, runs DDL and commits - run it on a database restored from the Stage B snapshot
 * (C:\hyb_p1_work\snap_stageb.sql), never on a shared database. Execution is enabled only by the
 * test binding of ConversionAvailability below; production keeps UnavailableConversion.
 */
class StorageStageBIntegrationTest extends FeatureTestBase
{
    use \Exceedone\Exment\Tests\Support\DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $c = config('database.connections.' . config('database.default'));
        // the original MariaDB host, or any host whose isolation guards pass (loopback, *test* database,
        // expected port: IsolatedConversionExecutionProvider) - e.g. the MySQL 8 host
        $legacy = (int)$c['port'] === 3408 && $c['database'] === 'hyb_p1_test';
        if (!($legacy || \Exceedone\Exment\Tests\Support\IsolatedConversionExecutionProvider::guardsPass($this->app)) || !app()->environment('testing')) {
            $this->markTestSkipped('isolated database only');
        }
        $this->enableExecution();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    private function enableExecution(): void
    {
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
    }

    // ------------------------------------------------------------------ fixture

    private function createFixtureTable(string $name): CustomTable
    {
        $table = CustomTable::create([
            'table_name' => $name,
            'table_view_name' => $name,
            'options' => ['search_enabled' => 1, 'all_user_editable_flg' => '1'],
        ]);
        System::clearRequestSession();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([$name => ltrim(getModelName($table, true), '\\')]);
        $ref = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $columns = [
            ['num', ColumnType::INTEGER, ['index_enabled' => '1']],
            ['amount', ColumnType::DECIMAL, ['decimal_digit' => '2']],
            ['day', ColumnType::DATE, ['index_enabled' => '1']],
            ['stamp', ColumnType::DATETIME, []],
            ['clock', ColumnType::TIME, []],
            ['label', ColumnType::TEXT, ['index_enabled' => '1']],
            ['kind', ColumnType::SELECT, ['select_item' => "a\nb\nc"]],
            ['tags', ColumnType::SELECT, ['select_item' => "a\nb\nc", 'multiple_enabled' => '1']],
            ['flag', ColumnType::YESNO, []],
            ['ref', ColumnType::SELECT_TABLE, ['select_target_table' => $ref->id, 'index_enabled' => '1']],
        ];
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

    /** @return array<string, int> row key => id */
    private function seedRows(CustomTable $table): array
    {
        $ids = [];
        $new = function (array $value) use ($table) {
            $m = $table->getValueModel();
            $m->setValue($value)->save();
            return (int)$m->id;
        };
        $ids['normal'] = $new(['num' => '12', 'amount' => '1.50', 'day' => '2024-01-02', 'stamp' => '2024-01-02 03:04:05',
            'clock' => '10:11:12', 'label' => 'Alpha', 'kind' => 'a', 'tags' => ['a', 'b'], 'flag' => '1', 'ref' => '1']);
        $ids['sparse'] = $new(['label' => 'Sparse']);
        $ids['deleted'] = $new(['num' => '5', 'label' => 'Gone']);
        $ids['odd'] = $new(['label' => 'odd']);
        $ids['odd2'] = $new(['label' => 'odd2']);
        $ids['extra'] = $new(['label' => 'extra']);

        $db = getDBTableName($table);
        // values only bypass paths can store (02 §11.1 V-5, V-7): written raw, as such paths would
        DB::table($db)->where('id', $ids['odd'])->update(['value' => json_encode([
            'num' => 'abc', 'amount' => '3774.0', 'label' => 5, 'flag' => 1, 'ref' => '',
            'day' => ['date' => '2022-04-14 00:00:00.000000', 'timezone_type' => 3, 'timezone' => 'Asia/Tokyo'],
        ])]);
        DB::table($db)->where('id', $ids['odd2'])->update(['value' => json_encode([
            'num' => 12, 'amount' => '1.123456789', 'stamp' => '2025-02-30 00:00:00', 'label' => null, 'clock' => '25:00:00',
        ])]);
        DB::table($db)->where('id', $ids['extra'])->update(['value' => json_encode([
            'num' => '-0', 'label' => 'extra', 'zz_extra' => 'keep', 'tags' => ['c'],
        ])]);
        $table->getValueModel()->find($ids['deleted'])->delete();
        return $ids;
    }

    private function model(CustomTable $table, int $id): CustomValue
    {
        System::clearRequestSession();
        return $table->getValueModel()->withTrashed()->find($id);
    }

    /**
     * The row's typed columns and value_original equal the projection of its stored JSON
     * ($only: the columns a single-key write projects; their value_original entries only).
     *
     * @param array<int, string>|null $only column names
     */
    private function assertProjected(CustomTable $table, int $id, string $label = '', ?array $only = null): void
    {
        $row = (array)DB::table(getDBTableName($table))->where('id', $id)->first();
        $projection = Projection::of($table, Projection::decode($row['value']));
        if ($only !== null) {
            $original = Projection::decode($row[PhysicalNaming::VALUE_ORIGINAL]) ?? [];
            foreach ($only as $name) {
                $pcol = PhysicalNaming::stagingColumn(CustomColumn::getEloquent($name, $table));
                $typed = $projection->typed()[$pcol];
                $this->assertTrue($typed === null ? $row[$pcol] === null : static::dbText($row[$pcol]) === (string)$typed, "$label row $id $pcol");
                $this->assertSame(json_encode(static::keySorted($projection->original()[$name] ?? null)), json_encode(static::keySorted($original[$name] ?? null)), "$label row $id value_original[$name]");
            }
            return;
        }
        foreach ($projection->typed() as $pcol => $typed) {
            $this->assertTrue(
                $typed === null ? $row[$pcol] === null : static::dbText($row[$pcol]) === static::dbForm($typed, $row[$pcol]),
                "$label row $id $pcol: db=" . var_export($row[$pcol], true) . ' expected=' . var_export($typed, true)
            );
        }
        $original = Projection::decode($row[PhysicalNaming::VALUE_ORIGINAL]) ?? [];
        $this->assertSame(json_encode(static::keySorted($projection->original())), json_encode(static::keySorted($original)), "$label row $id value_original");
    }

    /**
     * JSON objects compared without key order: MySQL 8 stores a JSON column normalised (keys
     * reordered); MariaDB keeps the text. The product compares value_original per key.
     */
    private static function keySorted($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        return array_map([static::class, 'keySorted'], $value);
    }

    /**
     * A typed cell as text, without the fractional zeros SQL Server returns for datetime / time
     * ('2024-01-02 03:04:05.000', '10:11:12.0000000'); other drivers return the value as is.
     */
    private static function dbText($db): string
    {
        $text = (string)$db;
        if (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d|\d\d:\d\d:\d\d)\.0+$/', $text, $m)) {
            return $m[1];
        }
        return $text;
    }

    /** The DB's text form of a typed value (decimal scale 8). */
    private static function dbForm($typed, $db): string
    {
        if (is_string($typed) && preg_match('/^-?\d+\.\d{8}$/', $typed)) {
            return $typed;
        }
        return (string)$typed;
    }

    private function storage(CustomTable $table): TableStorage
    {
        return TableStorage::fresh($table);
    }

    private function attestation(): DrainAttestation
    {
        return new DrainAttestation('integration-test', new \DateTimeImmutable(), array_fill_keys(DrainAttestation::REQUIRED, true), 'isolated test database; no other writer');
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

    private function runJob(CustomTable $table, string $op, ?int $generation = null): void
    {
        (new StorageConversionJob((int)$table->id, $op, $generation ?? $this->storage($table)->generation()))->handle();
    }

    // ------------------------------------------------------------------ lifecycle

    public function testFullLifecycle(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_life');
        $ids = $this->seedRows($table);
        $db = getDBTableName($table);
        $manager = app(PhysicalSchemaManager::class);
        $transitions = app(StorageTransitions::class);

        // 1. JSON table unchanged after the migrations
        $this->assertTrue($this->storage($table)->isJson());
        $this->assertSame('Alpha', $this->model($table, $ids['normal'])->getValue('label'));
        $eligibility = ConversionEligibility::check($table, $manager->plan($table));
        $this->assertTrue($eligibility->isEligible(), json_encode($eligibility->blockers()));

        // 2. provision, interrupted and resumed
        $manager->provision($table);
        $this->assertSame(StorageState::PROVISIONED, $this->storage($table)->state());
        $num = PhysicalNaming::stagingColumn(CustomColumn::getEloquent('num', $table));
        Schema::table($db, function ($b) use ($num) {
            $b->dropColumn($num);
        }); // a provisioning interrupted before this column
        System::clearCache();
        $this->assertCount(1, $manager->plan($table)->toAdd());
        $manager->provision($table);
        $this->assertCount(0, $manager->plan($table)->toAdd());
        $this->assertSame(1, DB::table('custom_table_storage_transitions')->where('custom_table_id', $table->id)->where('to_state', StorageState::PROVISIONED)->count(), 'one provision transition');

        // provisioned: JSON only
        $m = $this->model($table, $ids['normal']);
        $m->setValue('label', 'Alpha2')->save();
        $this->assertNull(DB::table($db)->where('id', $ids['normal'])->value($num), 'provisioned does not dual-write');

        // dual-write
        $transitions->startDualWrite($table);
        $this->assertSame(1, $this->storage($table)->generation());
        $m = $this->model($table, $ids['normal']);
        $m->setValue('num', '13')->save();
        $this->assertProjected($table, $ids['normal'], 'save');
        $q = $this->model($table, $ids['sparse']);
        $q->setValue('num', '42');
        $q->saveQuietly();
        $this->assertProjected($table, $ids['sparse'], 'saveQuietly');
        ValueBulkWriter::setKey($db, $ids['odd'], 'amount', '9.10');
        $this->assertProjected($table, $ids['odd'], 'setKey', ['amount']);
        $e = $this->model($table, $ids['extra']);
        $e->disable_saving_event(true)->setValue('num', '99')->save();
        $this->assertProjected($table, $ids['extra'], 'disable_saving_event');
        $revisions = $this->model($table, $ids['normal'])->revisionHistory()->orderBy('id')->get();
        $this->assertGreaterThan(0, $revisions->count(), 'the fixture keeps revisions');
        $r = $this->model($table, $ids['normal']);
        $r->setRevision($revisions->first()->suuid)->restore_revision()->save();
        $this->assertProjected($table, $ids['normal'], 'restore_revision');
        $this->model($table, $ids['normal'])->setValue('num', '13')->save();

        // backfill refused without a later attestation, then backfill with a conflicting writer
        sleep(1);
        $transitions->startBackfill($table, $this->attestation());
        $this->assertSame(StorageState::BACKFILLING, $this->storage($table)->state());

        // an interrupted batch rolls back completely
        $failing = new class (app(ConversionAvailability::class)) extends StorageBackfill {
            protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array
            {
                parent::processRows($table, $rows, $counts, $retry);
                throw new \RuntimeException('interrupted');
            }
        };
        $runId = $failing->start($table);
        try {
            $failing->step($table, $runId, 2);
        } catch (\RuntimeException $ex) {
        }
        $run = DB::table(StorageRunRepository::RUNS)->find($runId);
        $this->assertSame(0, (int)$run->checkpoint_id, 'checkpoint unchanged after an interrupted batch');
        $this->assertNull(DB::table($db)->where('id', $ids['odd2'])->value($num), 'batch writes rolled back');

        // a dual-write lands between the read and the compare-and-set of one row
        $racing = new class (app(ConversionAvailability::class)) extends StorageBackfill {
            public $raced = false;
            public $raceId;
            public $raceTable;

            protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array
            {
                if (!$this->raced && !$retry && $rows->isNotEmpty()) {
                    $this->raced = true;
                    // a row of THIS batch, already read by the runner: its compare-and-set must fail
                    $this->raceId = (int)$rows->first()->id;
                    $m = $this->raceTable->getValueModel()->withTrashed()->find($this->raceId);
                    $m->setValue('label', 'raced')->save();
                }
                return parent::processRows($table, $rows, $counts, $retry);
            }
        };
        // (not odd2: its stored '25:00:00' makes any normal save fail in Time::saving - existing behaviour)
        $racing->raceId = $ids['extra'];
        $racing->raceTable = $table;
        // restricted user: the run still covers every row, soft-deleted and invisible ones included
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $status = $racing->run($table, 2, 100);
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
        $this->assertSame(StorageRunRepository::STATUS_COMPLETED, $status['status'], json_encode($status));
        $this->assertSame(0, $status['pending']);
        $this->assertGreaterThanOrEqual(1, $status['counts']['pending_added'], 'the raced row was recorded pending');
        $this->assertSame($status['counts']['pending_added'], $status['counts']['pending_resolved']);
        $this->assertSame(count($ids), $status['counts']['rows'], 'every row, soft-deleted included');
        foreach ($ids as $key => $id) {
            $this->assertProjected($table, $id, "backfill $key");
        }

        // duplicate / stale jobs change nothing
        $before = DB::table(StorageRunRepository::RUNS)->find($status['run_id']);
        $this->runJob($table, StorageConversionJob::OP_BACKFILL);
        $this->runJob($table, StorageConversionJob::OP_BACKFILL, 0);
        $after = DB::table(StorageRunRepository::RUNS)->find($status['run_id']);
        $this->assertSame($before->counts, $after->counts);

        // verification: a corrupted typed value fails it; prerequisites refuse moving on
        $transitions->startVerification($table);
        DB::table($db)->where('id', $ids['normal'])->update([$num => 777]);
        $this->runJob($table, StorageConversionJob::OP_VERIFY);
        $verify = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, 1);
        $this->assertSame(StorageRunRepository::RESULT_FAILED, $verify->result);
        $this->refused(function () use ($transitions, $table) {
            $transitions->awaitConfirmation($table);
        }, 'no passed verification');
        $this->refused(function () use ($transitions, $table) {
            $transitions->cutover($table, new CutoverConfirmation((int)\Exment::getUserId(), 1, 1, 'x', new \DateTimeImmutable()), $this->attestation());
        }, 'transition to physical_read refused');
        $this->model($table, $ids['normal'])->setValue('num', '14')->save(); // dual-write repairs the row
        $this->runJob($table, StorageConversionJob::OP_VERIFY);
        $verify = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, 1);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify->result, json_encode(StorageRunRepository::counts($verify)));
        $transitions->awaitConfirmation($table);

        // L5 report and cutover
        $report = StorageL5Report::fromRun($verify);
        $l5 = $report->counts();
        $this->assertSame(1, $l5['num']['invalid'] ?? null, json_encode($l5));
        $this->assertSame(1, $l5['day']['carbon'] ?? null);
        $this->assertSame(1, $l5['label']['json_type'] ?? null);
        $this->assertArrayNotHasKey('tags', $l5, 'multi-valued columns are not projected');
        sleep(1);
        $admin = (int)\Exment::getUserId();
        $this->refused(function () use ($transitions, $table, $admin, $verify) {
            $transitions->cutover($table, new CutoverConfirmation($admin, (int)$verify->id, 1, 'stale-version', new \DateTimeImmutable()), $this->attestation());
        }, 'does not match');
        $user2 = (int)LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2)->base_user_id;
        $this->refused(function () use ($transitions, $table, $user2, $verify, $report) {
            $transitions->cutover($table, new CutoverConfirmation($user2, (int)$verify->id, 1, $report->version(), new \DateTimeImmutable()), $this->attestation());
        }, 'not a System Administrator');
        $this->refused(function () use ($transitions, $table, $admin, $verify, $report) {
            $incomplete = new DrainAttestation('x', new \DateTimeImmutable(), [], 'x');
            $transitions->cutover($table, new CutoverConfirmation($admin, (int)$verify->id, 1, $report->version(), new \DateTimeImmutable()), $incomplete);
        }, 'attestation incomplete');
        $transitions->cutover($table, new CutoverConfirmation($admin, (int)$verify->id, 1, $report->version(), new \DateTimeImmutable()), $this->attestation());
        $after = $this->storage($table);
        $this->assertSame([StorageState::PHYSICAL_READ, StorageMode::PHYSICAL, 2], [$after->state(), $after->mode(), $after->generation()]);

        // physical reads: routed keys, same rows as the JSON semantics, public value == JSON
        System::clearCache();
        $table = CustomTable::getEloquent('hyb_stageb_life');
        $numColumn = CustomColumn::getEloquent('num', $table);
        $this->assertSame($num, $numColumn->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_GENERAL));
        $this->assertSame($num, $numColumn->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_SORT));
        $this->assertNotSame($num, $numColumn->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_NULL_CHECK), 'null check stays on the shadow (D-3)');
        $this->assertNotSame(PhysicalNaming::stagingColumn(CustomColumn::getEloquent('label', $table)), CustomColumn::getEloquent('label', $table)->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_GENERAL), 'strings stay on the shadow (D-6)');
        $this->assertStringStartsNotWith(PhysicalNaming::STAGING_PREFIX, CustomColumn::getEloquent('tags', $table)->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_GENERAL), 'multi-valued stays JSON');
        $routed = $table->getValueModel()->query()->where($numColumn->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_GENERAL), 14)->pluck('id')->all();
        $this->assertSame([$ids['normal']], array_map('intval', $routed));

        foreach ($ids as $key => $id) {
            $model = $this->model($table, $id);
            $json = json_decode((string)DB::table($db)->where('id', $id)->value('value'), true);
            $this->assertSame(static::nullless($json), static::nullless($model->value), "public value $key");
            $array = $model->toArray();
            $this->assertArrayNotHasKey(PhysicalNaming::VALUE_ORIGINAL, $array);
            $this->assertArrayNotHasKey($num, $array);
        }
        // the screens' API output (session webapi) carries the public value, no internal column
        foreach (['normal', 'odd', 'extra'] as $key) {
            System::clearRequestSession();
            $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->get(admin_url("webapi/data/{$table->table_name}/{$ids[$key]}"));
            $response->assertStatus(200);
            $body = $response->json();
            $json = json_decode((string)DB::table($db)->where('id', $ids[$key])->value('value'), true);
            $this->assertSame(static::nullless($json), static::nullless($body['value'] ?? null), "api $key");
            $this->assertArrayNotHasKey(PhysicalNaming::VALUE_ORIGINAL, $body);
            $this->assertArrayNotHasKey($num, $body);
        }
        // a revision written in physical_read restores through the projection too
        $history = $this->model($table, $ids['normal'])->revisionHistory()->orderByDesc('id')->get();
        $this->model($table, $ids['normal'])->setRevision($history->last()->suuid)->restore_revision()->save();
        $this->assertProjected($table, $ids['normal'], 'physical restore_revision');

        $partial = $table->getValueModel()->query()->select(['id', 'value'])->find($ids['odd']);
        $this->assertSame(static::nullless(json_decode((string)DB::table($db)->where('id', $ids['odd'])->value('value'), true)), static::nullless($partial->value), 'partial select reads the shadow');

        // writes in physical_read keep the shadow complete
        $m = $this->model($table, $ids['normal']);
        $m->setValue('num', '15')->save();
        $this->assertProjected($table, $ids['normal'], 'physical save');
        $q = $this->model($table, $ids['sparse']);
        $q->setValue('label', 'quiet');
        $q->saveQuietly();
        $this->assertProjected($table, $ids['sparse'], 'physical saveQuietly');
        ValueBulkWriter::setKey($db, $ids['odd'], 'num', '8');
        $this->assertProjected($table, $ids['odd'], 'physical setKey');
        $this->assertSame('8', $this->model($table, $ids['odd'])->value['num']);

        // fast rollback: refused without reconciliation, then after a passed verification
        $this->refused(function () use ($transitions, $table) {
            $transitions->rollback($table, $this->attestation(), false);
        }, 'no passed reconciliation');
        $this->runJob($table, StorageConversionJob::OP_VERIFY);
        $reconcile = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, 2);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $reconcile->result);
        $transitions->rollback($table, $this->attestation(), false);
        $after = $this->storage($table);
        $this->assertSame([StorageState::DUALWRITE, StorageMode::JSON, 3], [$after->state(), $after->mode(), $after->generation()]);
        $this->assertTrue(Schema::hasColumn($db, $num), 'rollback does not drop columns');

        // availability denied: nothing runs
        $this->app->instance(ConversionAvailability::class, new UnavailableConversion());
        $this->refused(function () use ($table) {
            app(StorageTransitions::class)->cancel($table);
        }, 'not available');
        $this->enableExecution();

        // the lifecycle log
        $log = DB::table('custom_table_storage_transitions')->where('custom_table_id', $table->id)->orderBy('id')->pluck('to_state')->all();
        $this->assertSame([StorageState::PROVISIONED, StorageState::DUALWRITE, StorageState::BACKFILLING, StorageState::VERIFYING,
            StorageState::AWAITING_CONFIRMATION, StorageState::PHYSICAL_READ, StorageState::DUALWRITE], $log);
    }

    /** null entries are absent (V-6); compare with keys sorted. */
    private static function nullless($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $value = array_filter($value, function ($v) {
            return $v !== null;
        });
        ksort($value);
        return $value;
    }

    // ------------------------------------------------------------------ cancel / cleanup

    /**
     * Integer values beyond the signed INT range on a physical BIGINT column: filter and sort return the right
     * RECORDS (checked on the rows, not on SQL text). On SQL Server the JSON-era expression CAST(.. AS int)
     * overflows (error 8115) as soon as such a value is read - the JSON table keeps that behaviour; the physical
     * table no longer casts a BIGINT column (intentional overflow bug fix, 02 §15.21).
     */
    public function testBigintBeyondIntRangeFiltersAndSortsByRecord(): void
    {
        $table = CustomTable::create(['table_name' => 'hyb_stageb_bigint', 'table_view_name' => 'hyb_stageb_bigint', 'options' => ['search_enabled' => 1, 'all_user_editable_flg' => '1']]);
        System::clearCache();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(['hyb_stageb_bigint' => ltrim(getModelName($table, true), '\\')]);
        $big = CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => 'big', 'column_view_name' => 'big', 'column_type' => ColumnType::INTEGER, 'options' => ['index_enabled' => '1']]);
        System::clearCache();
        $table = CustomTable::getEloquent('hyb_stageb_bigint');
        $values = ['-9000000000000', '-2147483649', '-2147483648', '-1', '0', '5', '2147483647', '2147483648', '9000000000000', null];
        $ids = [];
        foreach ($values as $v) {
            $m = $table->getValueModel();
            $m->setValue($v === null ? ['big' => null] : ['big' => $v])->save();
            $ids[(string)($v ?? 'null')] = (int)$m->id;
        }
        $expect = function (callable $keep, bool $desc = false) use ($values, $ids) {
            $kept = array_values(array_filter($values, function ($v) use ($keep) {
                return $v !== null && $keep((int)$v);
            }));
            usort($kept, function ($a, $b) use ($desc) {
                return $desc ? (int)$b <=> (int)$a : (int)$a <=> (int)$b;
            });
            return array_map(function ($v) use ($ids) {
                return $ids[$v];
            }, $kept);
        };
        $cases = [
            'gt 0' => [FilterOption::NUMBER_GT, '0', function ($v) { return $v > 0; }],
            'gte 2147483647' => [FilterOption::NUMBER_GTE, '2147483647', function ($v) { return $v >= 2147483647; }],
            'gt 2147483647' => [FilterOption::NUMBER_GT, '2147483647', function ($v) { return $v > 2147483647; }],
            'lt -2147483648' => [FilterOption::NUMBER_LT, '-2147483648', function ($v) { return $v < -2147483648; }],
            'lte -2147483648' => [FilterOption::NUMBER_LTE, '-2147483648', function ($v) { return $v <= -2147483648; }],
            'gt -1e13' => [FilterOption::NUMBER_GT, '-10000000000000', function ($v) { return $v > -10000000000000; }],
        ];
        $run = function (string $label, $cond, string $text, int $sortDir) use ($table) {
            $view = \Exceedone\Exment\Model\CustomView::create(['custom_table_id' => $table->id, 'view_view_name' => 'bigint ' . substr(md5($label . $sortDir), 0, 8),
                'view_type' => \Exceedone\Exment\Enums\ViewType::SYSTEM, 'view_kind_type' => \Exceedone\Exment\Enums\ViewKindType::DEFAULT]);
            $f = new \Exceedone\Exment\Model\CustomViewFilter();
            $f->custom_view_id = $view->id;
            $f->view_column_type = \Exceedone\Exment\Enums\ConditionType::COLUMN;
            $f->view_column_table_id = $table->id;
            $f->view_column_target_id = CustomColumn::getEloquent('big', $table)->id;
            $f->view_filter_condition = $cond;
            $f->view_filter_condition_value_text = $text;
            $f->save();
            $s = new \Exceedone\Exment\Model\CustomViewSort();
            $s->custom_view_id = $view->id;
            $s->view_column_type = \Exceedone\Exment\Enums\ConditionType::COLUMN;
            $s->view_column_table_id = $table->id;
            $s->view_column_target_id = CustomColumn::getEloquent('big', $table)->id;
            $s->sort = $sortDir;
            $s->priority = 1;
            $s->save();
            System::clearCache();
            $q = CustomTable::getEloquent($table->id)->getValueQuery();
            \Exceedone\Exment\Model\CustomView::find($view->id)->filterSortModel($q);
            return ['sql' => $q->toSql(), 'ids' => array_map('intval', $q->pluck('id')->all())];
        };

        // JSON table: unchanged behaviour. SQL Server raises the overflow when it reads a value beyond INT;
        // the other drivers' cast is 64 bit
        if (PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            try {
                $run('json overflow', FilterOption::NUMBER_GT, '0', 1);
                $this->fail('expected the JSON-table CAST(.. AS int) overflow on SQL Server');
            } catch (\Illuminate\Database\QueryException $ex) {
                // the JSON text is converted to int: "overflowed an int column" (22003); a bigint column cast: 8115
                $this->assertMatchesRegularExpression('/overflow/i', $ex->getMessage(), 'arithmetic overflow, as before');
            }
        } else {
            $this->assertSame($expect(function ($v) { return $v > 0; }), $run('json', FilterOption::NUMBER_GT, '0', 1)['ids']);
        }

        $this->toPhysicalRead($table);
        $this->assertSame(StorageState::PHYSICAL_READ, $this->storage($table)->state());
        foreach ($cases as $label => [$cond, $text, $keep]) {
            foreach ([1, -1] as $dir) {
                $r = $run($label, $cond, $text, $dir);
                $this->assertStringNotContainsString('CAST(', $r['sql'], "$label: no cast on the physical bigint column");
                $this->assertSame($expect($keep, $dir === -1), $r['ids'], "$label sort " . ($dir === 1 ? 'asc' : 'desc') . ': same records in the integer order');
            }
        }
        // membership independent of order, and NULL never matches a filter
        $r = $run('all', FilterOption::NUMBER_GT, '-10000000000000', 1);
        $this->assertNotContains($ids['null'], $r['ids']);
        $this->assertCount(count($values) - 1, $r['ids']);
        // the JSON shadow keeps the exact values
        $this->assertSame('9000000000000', (string)$this->model($table, $ids['9000000000000'])->value['big']);
    }

    /**
     * Set-based JSON writes on a table in dual-write, on every driver: removing a key (column deletion path,
     * ValueBulkWriter::removeKey) removes it from the JSON, the typed column becomes NULL and its
     * value_original entry goes; the refusals that apply to a converted table stay explicit and write nothing.
     * (SQL Server gained removeKey with the JSON_MODIFY grammar, 02 §15.21.)
     */
    public function testBulkRemoveKeyAndRefusalsOnAConvertedTable(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_bulk');
        $ids = $this->seedRows($table);
        $db = getDBTableName($table);
        app(PhysicalSchemaManager::class)->provision($table);
        app(StorageTransitions::class)->startDualWrite($table);
        $num = PhysicalNaming::stagingColumn(CustomColumn::getEloquent('num', $table));
        // rows written before dual-write are projected by the backfill; a save in dual-write projects at once
        $this->model($table, $ids['normal'])->setValue('num', '13')->save();
        $this->assertNotNull(DB::table($db)->where('id', $ids['normal'])->value($num), 'precondition: the typed column holds the value');

        // removeKey over every row (soft-deleted and hidden rows included: the base query is used)
        ValueBulkWriter::removeKey($table->getValueModel()->newQuery(), 'num');
        foreach (DB::table($db)->get() as $row) {
            $this->assertArrayNotHasKey('num', json_decode((string)$row->value, true) ?? [], "row {$row->id}: key removed from the JSON");
            $this->assertNull($row->$num, "row {$row->id}: typed column NULL");
            $original = Projection::decode($row->{PhysicalNaming::VALUE_ORIGINAL}) ?? [];
            $this->assertArrayNotHasKey('num', $original, "row {$row->id}: no value_original entry");
        }
        // unrelated keys untouched
        $this->assertSame('Alpha', json_decode((string)DB::table($db)->where('id', $ids['normal'])->value('value'), true)['label']);
        $this->assertProjected($table, $ids['normal'], 'after removeKey');

        // column deletion on a converted table stays refused by the guard (every driver), nothing changes
        $before = DB::table($db)->orderBy('id')->pluck('value', 'id')->all();
        $this->refused(function () use ($table) {
            CustomColumn::getEloquent('label', $table)->delete();
        });
        $this->assertSame($before, DB::table($db)->orderBy('id')->pluck('value', 'id')->all(), 'the refused deletion wrote nothing');
    }

    /**
     * Removal of a JSON key (ValueBulkWriter::removeKey), on every driver, on a plain JSON table: a key that is
     * missing is left alone, an explicit JSON null is removed with the rest, unrelated keys and unselected rows
     * stay byte for byte, and a key that needs quoting in the JSON path ("q-1 x") is addressed correctly.
     * Rows are chosen by the query given (no scopes: soft-deleted rows included).
     */
    public function testJsonKeyRemovalCasesOnAPlainTable(): void
    {
        $table = CustomTable::create(['table_name' => 'hyb_stageb_rmkey', 'table_view_name' => 'hyb_stageb_rmkey', 'options' => ['search_enabled' => 1, 'all_user_editable_flg' => '1']]);
        System::clearCache();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(['hyb_stageb_rmkey' => ltrim(getModelName($table, true), '\\')]);
        foreach ([['label', ColumnType::TEXT], ['num', ColumnType::INTEGER]] as [$name, $type]) {
            CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => $name, 'column_view_name' => $name, 'column_type' => $type, 'options' => []]);
        }
        System::clearCache();
        $table = CustomTable::getEloquent('hyb_stageb_rmkey');
        $db = getDBTableName($table);
        $rows = [
            'set' => ['label' => 'a', 'num' => '1'],
            'null' => ['label' => null, 'num' => '2'],
            'missing' => ['num' => '3'],
            'trashed' => ['label' => 'd', 'num' => '4'],
            'odd' => ['q-1 x' => 'v', 'label' => 'e', 'num' => '5'],
        ];
        $reset = function () use ($db, $rows) {
            DB::table($db)->delete();
            $ids = [];
            foreach ($rows as $name => $value) {
                $ids[$name] = (int)DB::table($db)->insertGetId(['value' => json_encode($value), 'suuid' => substr(md5($name . microtime(true)), 0, 20),
                    'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $name === 'trashed' ? now() : null]);
            }
            return $ids;
        };
        $stored = function (array $ids) use ($db) {
            $out = [];
            foreach ($ids as $name => $id) {
                $out[$name] = json_decode((string)DB::table($db)->where('id', $id)->value('value'), true);
            }
            return $out;
        };
        $expectedWithout = function (string $key, array $only = null) use ($rows) {
            $out = $rows;
            foreach ($out as $name => $value) {
                if (($only === null || in_array($name, $only, true))) {
                    unset($out[$name][$key]);
                }
            }
            return $out;
        };

        // 1. every row (base query: soft-deleted included): missing key untouched, explicit null removed, unrelated keys kept
        $ids = $reset();
        ValueBulkWriter::removeKey($table->getValueModel()->newQuery(), 'label');
        $this->assertEquals($expectedWithout('label'), $stored($ids), 'label removed everywhere, nothing else changed');
        $this->assertSame(json_encode($rows['missing']), json_encode($stored($ids)['missing']), 'a row without the key is as it was');

        // 2. a key that needs quoting in the JSON path
        $ids = $reset();
        ValueBulkWriter::removeKey($table->getValueModel()->newQuery(), 'q-1 x');
        $this->assertEquals($expectedWithout('q-1 x'), $stored($ids), 'the quoted key is removed from its row only');
        $this->assertSame('e', $stored($ids)['odd']['label'], 'a neighbouring key of the same row is kept');

        // 3. rows chosen by the query: the JSON-not-null selection RefreshDataService uses
        $ids = $reset();
        ValueBulkWriter::removeKey($table->getValueModel()->newQuery()->withTrashed()->whereNotNull('value->label'), 'label');
        // an explicit JSON null is not "not null" on any driver (MySQL / MariaDB: json_type != 'NULL' is part of the
        // JSON not-null condition; SQL Server: json_value() is NULL for it): that row is not selected and keeps its null
        $selected = ['set', 'trashed', 'odd'];
        $this->assertEquals($expectedWithout('label', $selected), $stored($ids), 'only the selected rows lose the key (' . implode(',', $selected) . ')');
        $this->assertSame(json_encode($rows['missing']), json_encode($stored($ids)['missing']), 'an unselected row is byte for byte as it was');
    }

    /**
     * On a table in dual-write: a key that is not a safe JSON path key is refused before anything is written,
     * and column create / update / delete stay refused (every driver) with nothing changed.
     */
    public function testProhibitedChangesOnAConvertedTableWriteNothing(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_prohibited');
        $this->seedRows($table);
        $db = getDBTableName($table);
        app(PhysicalSchemaManager::class)->provision($table);
        app(StorageTransitions::class)->startDualWrite($table);
        $values = DB::table($db)->orderBy('id')->pluck('value', 'id')->all();
        $columns = DB::table('custom_columns')->where('custom_table_id', $table->id)->count();

        $this->refused(function () use ($table) {
            ValueBulkWriter::removeKey($table->getValueModel()->newQuery(), 'bad key"; drop');
        }, 'not a safe JSON path key');
        $this->refused(function () use ($table) {
            $column = CustomColumn::getEloquent('label', $table);
            $column->column_view_name = 'renamed';
            $column->save();
        }, 'column update refused');
        $this->refused(function () use ($table) {
            CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => 'extra_col', 'column_view_name' => 'x', 'column_type' => ColumnType::TEXT, 'options' => []]);
        }, 'column create refused');
        $this->refused(function () use ($table) {
            CustomColumn::getEloquent('num', $table)->delete();
        }, 'column delete refused');

        $this->assertSame($values, DB::table($db)->orderBy('id')->pluck('value', 'id')->all(), 'no row changed');
        $this->assertSame($columns, DB::table('custom_columns')->where('custom_table_id', $table->id)->count(), 'no column added or removed');
        $this->assertSame(StorageState::DUALWRITE, $this->storage($table)->state());
    }

    /**
     * SQL Server: setKey (JSON_MODIFY) keeps JSON types - string stays a string, number a number, array an
     * array, null is JSON null (not a removed key) - and projects in dual-write.
     */
    public function testSqlServerSetKeyKeepsJsonTypes(): void
    {
        if (PhysicalSchemaManager::driverFamily() !== 'sqlsrv') {
            $this->markTestSkipped('SQL Server JSON_MODIFY path only; the other drivers use the query-builder JSON update');
        }
        $table = $this->createFixtureTable('hyb_stageb_sqlsrv');
        $ids = $this->seedRows($table);
        $db = getDBTableName($table);
        app(PhysicalSchemaManager::class)->provision($table);
        app(StorageTransitions::class)->startDualWrite($table);

        ValueBulkWriter::setKey($db, $ids['odd'], 'num', '8');
        $this->assertSame('8', json_decode((string)DB::table($db)->where('id', $ids['odd'])->value('value'), true)['num'], 'a string stays a JSON string');
        $this->assertProjected($table, $ids['odd'], 'sqlsrv setKey string', ['num']);
        ValueBulkWriter::setKey($db, $ids['odd'], 'flag', 1);
        $this->assertSame(1, json_decode((string)DB::table($db)->where('id', $ids['odd'])->value('value'), true)['flag'], 'a number stays a JSON number');
        ValueBulkWriter::setKey($db, $ids['odd'], 'tags', ['a', 'b']);
        $this->assertSame(['a', 'b'], json_decode((string)DB::table($db)->where('id', $ids['odd'])->value('value'), true)['tags'], 'an array is a JSON array');
        ValueBulkWriter::setKey($db, $ids['odd'], 'label', null);
        $decoded = json_decode((string)DB::table($db)->where('id', $ids['odd'])->value('value'), true);
        $this->assertArrayHasKey('label', $decoded, 'null is written as JSON null, the key is not removed');
        $this->assertNull($decoded['label']);
    }

    public function testCancelCleanupDeprovision(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_clean');
        $ids = $this->seedRows($table);
        $db = getDBTableName($table);
        $manager = app(PhysicalSchemaManager::class);
        $transitions = app(StorageTransitions::class);
        $manager->provision($table);
        $transitions->startDualWrite($table);
        foreach ($ids as $id) {
            // the supported set-based path (a normal save of odd2 fails in Time::saving - existing behaviour)
            ValueBulkWriter::setKey($db, $id, 'num', '3');
        }
        $jsonBefore = DB::table($db)->orderBy('id')->pluck('value', 'id')->all();
        $num = PhysicalNaming::stagingColumn(CustomColumn::getEloquent('num', $table));
        $this->assertSame(count($ids), DB::table($db)->whereNotNull($num)->count());

        $transitions->cancel($table);
        $this->assertSame([StorageState::PROVISIONED, 2], [$this->storage($table)->state(), $this->storage($table)->generation()]);
        $this->refused(function () use ($manager, $table) {
            $manager->deprovision($table);
        }, 'holds values');
        $cleanup = app(StagingCleanup::class);
        $this->refused(function () use ($cleanup, $table) {
            $cleanup->startConfirmed($table, 'wrong name');
        }, 'does not match');
        $runId = $cleanup->startConfirmed($table, 'hyb_stageb_clean');
        $this->refused(function () use ($transitions, $table) {
            $transitions->startDualWrite($table);
        }, 'staging cleanup');
        $cleanup->step($table, $runId, 2); // interrupted after one batch
        $this->assertGreaterThan(0, DB::table($db)->whereNotNull($num)->count());
        $this->runJob($table, StorageConversionJob::OP_CLEANUP); // resumed by the queue job
        $this->assertSame(StorageRunRepository::STATUS_COMPLETED, DB::table(StorageRunRepository::RUNS)->find($runId)->status);
        $this->assertSame(0, DB::table($db)->whereNotNull($num)->count());
        $this->assertSame(0, DB::table($db)->whereNotNull(PhysicalNaming::VALUE_ORIGINAL)->count());
        $this->assertSame($jsonBefore, DB::table($db)->orderBy('id')->pluck('value', 'id')->all(), 'JSON untouched');

        $manager->deprovision($table);
        System::clearCache();
        $this->assertTrue($this->storage($table)->isJson());
        $this->assertFalse(Schema::hasColumn($db, $num));
        $this->assertFalse(Schema::hasColumn($db, PhysicalNaming::VALUE_ORIGINAL));
        $this->assertSame('Alpha', $this->model($table, $ids['normal'])->getValue('label'));
    }

    // ------------------------------------------------------------------ to physical_read

    /** Provision -> dual-write -> backfill -> verify -> confirm -> cutover, with the services. */
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
        $verify = app(\Exceedone\Exment\Services\Storage\StorageVerifier::class)->run($table, 500, 100);
        $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify['result'], json_encode($verify));
        $transitions->awaitConfirmation($table);
        sleep(1);
        $run = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, $this->storage($table)->generation());
        $transitions->cutover($table, new CutoverConfirmation((int)\Exment::getUserId(), (int)$run->id, (int)$run->generation,
            StorageL5Report::fromRun($run)->version(), new \DateTimeImmutable()), $this->attestation());
        System::clearCache();
        $this->assertSame(StorageState::PHYSICAL_READ, $this->storage($table)->state());
    }

    private function indexNames(string $db): array
    {
        return array_map(function ($i) {
            return (string)$i['name'];
        }, Schema::getIndexes($db));
    }

    /** @return array<string, string> column => status */
    private function indexPlanStatus(CustomTable $table): array
    {
        $out = [];
        foreach (app(\Exceedone\Exment\Services\Storage\StorageAutoIndexer::class)->plan($table)['candidates'] as $c) {
            $out[$c['column']] = $c['status'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ automatic indexes

    public function testAutoIndexes(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_idx');
        $this->seedRows($table);
        $db = getDBTableName($table);
        $this->toPhysicalRead($table);
        $indexer = app(\Exceedone\Exment\Services\Storage\StorageAutoIndexer::class);
        $pcol = function ($name) use ($table) {
            return PhysicalNaming::stagingColumn(CustomColumn::getEloquent($name, $table));
        };
        $idx = function ($name) use ($table) {
            return PhysicalNaming::stagingIndex(CustomColumn::getEloquent($name, $table));
        };

        // default budget null: disabled
        $this->assertNull(config('exment.storage_auto_index_budget'));
        $this->assertFalse($indexer->plan($table)['enabled']);
        $this->refused(function () use ($indexer, $table) {
            $indexer->runOnce($table);
        }, 'no configured index budget');

        // explicit TEST budget only
        config(['exment.storage_auto_index_budget' => 64]);
        if (PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
            // SQL Server: ONLINE index builds exist only on some editions (EngineEdition 3/5/8). Elsewhere
            // (Express, LocalDB) the job is refused unless the offline build is allowed explicitly.
            $edition = (int)DB::selectOne("SELECT CAST(SERVERPROPERTY('EngineEdition') AS int) AS e")->e;
            if (!in_array($edition, [3, 5, 8], true)) {
                $this->assertFalse(config('exment.storage_auto_index_sqlsrv_allow_offline'), 'offline index builds are off by default');
                $r = $indexer->runOnce($table);
                $this->assertSame(['skipped', 'sqlsrv_online_index_unavailable'], [$r['outcome'], $r['reason']], 'refused without ONLINE or explicit offline permission');
                config(['exment.storage_auto_index_sqlsrv_allow_offline' => true]);
            }
        }
        $this->assertSame(['num' => 'candidate', 'day' => 'candidate', 'label' => 'excluded_not_routed', 'ref' => 'candidate'], $this->indexPlanStatus($table));

        // a manual index whose first column is the typed column is equivalent: kept, not duplicated
        Schema::table($db, function ($b) use ($pcol) {
            $b->index([$pcol('day')], 'manual_day_idx');
        });
        $this->assertSame('equivalent_exists', $this->indexPlanStatus($table)['day']);

        $before = count($this->indexNames($db));
        $r = $indexer->runOnce($table);
        $this->assertSame(['created', $idx('num')], [$r['outcome'], $r['index_name']], json_encode($r));
        $this->assertSame(1, $r['remaining']);
        $r = $indexer->runOnce($table);
        $this->assertSame(['created', $idx('ref')], [$r['outcome'], $r['index_name']]);
        $r = $indexer->runOnce($table);
        $this->assertSame(['skipped', 'nothing_to_create'], [$r['outcome'], $r['reason']]);
        $names = $this->indexNames($db);
        $this->assertSame($before + 2, count($names), 'exactly two indexes created');
        $this->assertContains('manual_day_idx', $names, 'manual index preserved');
        $this->assertNotContains($idx('day'), $names, 'no duplicate of the manual index');

        // budget counts every secondary index: exhausted
        Schema::table($db, function ($b) use ($idx) {
            $b->dropIndex($idx('ref'));
        });
        $existing = $indexer->plan($table)['existing'];
        config(['exment.storage_auto_index_budget' => $existing]);
        $r = $indexer->runOnce($table);
        $this->assertSame(['skipped', 'budget_exhausted'], [$r['outcome'], $r['reason']]);
        $this->assertNotContains($idx('ref'), $this->indexNames($db));
        config(['exment.storage_auto_index_budget' => 64]);

        // interrupted DDL that completed: a running claim + the index -> reconciled as created
        Schema::table($db, function ($b) use ($pcol, $idx) {
            $b->index([$pcol('ref')], $idx('ref'));
        });
        $claim = StorageRunRepository::create((int)$table->id, 'index', $this->storage($table)->generation(), null);
        StorageRunRepository::update($claim, ['counts' => ['index_name' => $idx('ref'), 'column' => 'ref', 'staging_column' => $pcol('ref')]]);
        $r = $indexer->runOnce($table);
        $this->assertSame('skipped', $r['outcome']);
        $settled = DB::table(StorageRunRepository::RUNS)->find($claim);
        $this->assertSame(['completed', 'created'], [$settled->status, $settled->result]);
        $this->assertSame('reconciled', json_decode($settled->counts, true)['reason']);

        // a claim whose worker may still be running: in progress until the timeout, then abandoned
        Schema::table($db, function ($b) use ($idx) {
            $b->dropIndex($idx('ref'));
        });
        $claim = StorageRunRepository::create((int)$table->id, 'index', $this->storage($table)->generation(), null);
        StorageRunRepository::update($claim, ['counts' => ['index_name' => $idx('ref'), 'column' => 'ref', 'staging_column' => $pcol('ref')]]);
        $r = $indexer->runOnce($table);
        $this->assertSame(['skipped', 'claim_in_progress'], [$r['outcome'], $r['reason']]);
        DB::table(StorageRunRepository::RUNS)->where('id', $claim)->update(['started_at' => now()->subHours(2)]);
        $r = $indexer->runOnce($table);
        $this->assertSame(['created', $idx('ref')], [$r['outcome'], $r['index_name']]);
        $this->assertSame('abandoned', json_decode(DB::table(StorageRunRepository::RUNS)->find($claim)->counts, true)['reason']);

        // state change: after a rollback nothing is created; existing indexes stay
        $this->runJob($table, StorageConversionJob::OP_VERIFY);
        app(StorageTransitions::class)->rollback($table, $this->attestation(), false);
        Schema::table($db, function ($b) use ($idx) {
            $b->dropIndex($idx('ref'));
        });
        $r = $indexer->runOnce($table);
        $this->assertSame(['skipped', 'state_not_physical_read'], [$r['outcome'], $r['reason']]);
        $this->runJob($table, StorageConversionJob::OP_INDEX); // a job of the old generation: nothing
        $this->assertNotContains($idx('ref'), $this->indexNames($db));
        $this->assertContains($idx('num'), $this->indexNames($db));
        $this->assertContains('manual_day_idx', $this->indexNames($db));
    }

    /** Leaves hyb_stageb_idxdup in physical_read, for the two-process index worker probe. */
    public function testPrepareIndexWorkers(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_idxdup');
        $this->seedRows($table);
        $this->toPhysicalRead($table);
    }

    /** Leaves hyb_stageb_part1 / part2 in json, for the partial provisioning probe. */
    public function testPreparePartialProvision(): void
    {
        foreach (['hyb_stageb_part1', 'hyb_stageb_part2'] as $name) {
            $table = $this->createFixtureTable($name);
            $table->getValueModel()->setValue(['num' => '1', 'label' => 'p'])->save();
        }
        $this->assertTrue(true);
    }

    /** Leaves hyb_stageb_queue in backfilling without a run, for the queue:work probe. */
    public function testPrepareQueueBackfill(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_queue');
        $this->seedRows($table);
        app(PhysicalSchemaManager::class)->provision($table);
        app(StorageTransitions::class)->startDualWrite($table);
        sleep(1);
        app(StorageTransitions::class)->startBackfill($table, $this->attestation());
        $this->assertSame(StorageState::BACKFILLING, $this->storage($table)->state());
    }

    // ------------------------------------------------------------------ export / restricted user

    /**
     * custom_value_edit (seeded data; user2 sees a subset) converted to physical_read: the export
     * rows and user2's filtered, sorted view query and lazy grid rows are the same as on JSON.
     */
    /**
     * The Kanban board of a converted table. KanbanGrid::selectStoredColumns() (upstream 7.2.5) replaces `table.*` by the stored columns
     * (no index columns `column_*`); here the staging columns (pcol_*, value_original) are left out as well, so a converted table is read as
     * cheaply as a JSON one. A row read without them gives the JSON shadow, which is the public value: the cards and the statistics must be
     * the same as before the conversion.
     */
    public function testKanbanBoardOfAConvertedTableIsTheSameAsOnJson(): void
    {
        $this->enableExecution();
        $table = $this->createFixtureTable('hyb_stageb_kanban');
        $this->seedRows($table);
        $kind = CustomColumn::getEloquent('kind', $table);
        $view = \Exceedone\Exment\Model\CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'stageb kanban',
            'view_type' => \Exceedone\Exment\Enums\ViewType::SYSTEM,
            'view_kind_type' => \Exceedone\Exment\Enums\ViewKindType::KANBAN,
            'options' => ['kanban_col_count' => 200, 'kanban_group_column_id' => $kind->id],
        ]);

        $board = function () use ($table, $view) {
            System::clearRequestSession();
            System::clearCache();
            $grid = new \Exceedone\Exment\DataItems\Grid\KanbanGrid(
                CustomTable::getEloquent($table->id),
                \Exceedone\Exment\Model\CustomView::withoutGlobalScopes()->find($view->id)
            );
            $cards = [];
            foreach (['a', 'b', 'c', \Exceedone\Exment\DataItems\Grid\KanbanGrid::EMPTY_KEY] as $key) {
                $result = $grid->moreCards($key, 0);
                $cards[$key] = json_decode(json_encode($result['cards']), true);
            }
            return ['cards' => $cards, 'stats' => json_decode(json_encode($grid->columnStats()['flat']), true)];
        };

        $before = $board();
        $this->assertNotEmpty(array_filter($before['cards']), 'precondition: the board has cards');

        $this->toPhysicalRead($table);

        // the board reads a converted table as cheaply as a JSON one: its select list has neither the index columns nor the staging
        // columns, and is the select list of the same table before the conversion
        $db = getDBTableName($table);
        $is_board_select = function ($q) use ($db) {
            return stripos($q['query'], 'from ' . static::q($db)) !== false && stripos($q['query'], 'order by') !== false && stripos($q['query'], 'limit') !== false;
        };
        $select_list = function ($log) use ($is_board_select) {
            $lists = [];
            foreach (array_filter($log, $is_board_select) as $q) {
                $lists[] = substr($q['query'], 0, stripos($q['query'], ' from '));
            }
            return array_values(array_unique($lists));
        };
        $pcol = PhysicalNaming::stagingColumn(CustomColumn::getEloquent('num', $table));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $after = $board();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }
        $lists = $select_list($log);
        $this->assertNotEmpty($lists, 'precondition: the board queries were captured');
        foreach ($lists as $list) {
            $this->assertStringNotContainsString($pcol, $list, 'no staging column in the select list');
            $this->assertStringNotContainsString(PhysicalNaming::VALUE_ORIGINAL, $list);
            $this->assertStringNotContainsString('`column_', $list, 'no index column in the select list');
        }
        $this->assertCount(1, $lists, 'one select list for every board query');
        $this->assertSame($before['cards'], $after['cards'], 'the cards of every board column');
        $this->assertSame($before['stats'], $after['stats'], 'the statistics of the board');
    }
    /**
     * With the persistent cache on (EXMENT_USE_CACHE), a new request - whose request session is empty, so only the persistent cache is left -
     * sees every storage transition at once, and routes its queries to the source the table reads now. Nothing here clears the cache by hand:
     * the transitions and the schema changes have to do it (TableStorage::transitionTo -> System::clearCacheAfterCommit, PhysicalSchemaManager).
     */
    public function testEveryTransitionIsSeenByANewRequestThroughThePersistentCache(): void
    {
        $this->enableExecution();
        $previous = config('exment.use_cache');
        config(['exment.use_cache' => true]);
        try {
            System::clearCache();
            $name = 'hyb_stageb_cache';
            $table = $this->createFixtureTable($name);
            $ids = $this->seedRows($table);
            $db = getDBTableName($table);
            $transitions = app(StorageTransitions::class);
            $manager = app(PhysicalSchemaManager::class);
            $num = CustomColumn::getEloquent('num', $table);
            $pcol = PhysicalNaming::stagingColumn($num);

            $request = function () use ($name) {
                System::clearRequestSession();
                return CustomTable::getEloquent($name);
            };
            $seen = function (string $step) use ($request, $table) {
                $cached = TableStorage::fromModel($request());
                $fresh = TableStorage::fresh($table);
                $this->assertSame(
                    [$fresh->mode(), $fresh->state(), $fresh->generation()],
                    [$cached->mode(), $cached->state(), $cached->generation()],
                    "a new request sees the table as it is after: $step"
                );
                return $cached;
            };
            // the key a new request queries the column by, and a query on it that must run
            $keyOf = function () use ($request) {
                $t = $request();
                $key = CustomColumn::getEloquent('num', $t)->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_GENERAL);
                $t->getValueQuery()->whereNotNull($key)->count();
                return $key;
            };

            $this->assertTrue($seen('start')->isJson());
            $this->assertStringNotContainsString($pcol, $keyOf());

            $manager->provision($table);
            $seen('provision');
            $transitions->startDualWrite($table);
            $seen('dual-write');
            sleep(1);
            $transitions->startBackfill($table, $this->attestation());
            $seen('backfill');
            $status = app(StorageBackfill::class)->run($table, 500, 100);
            $this->assertSame(StorageRunRepository::STATUS_COMPLETED, $status['status'], json_encode($status));
            $transitions->startVerification($table);
            $seen('verification');
            $verify = app(\Exceedone\Exment\Services\Storage\StorageVerifier::class)->run($table, 500, 100);
            $this->assertSame(StorageRunRepository::RESULT_PASSED, $verify['result'], json_encode($verify));
            $transitions->awaitConfirmation($table);
            $seen('awaiting confirmation');
            $this->assertStringNotContainsString($pcol, $keyOf(), 'not read from the typed column before the cutover');
            sleep(1);
            $run = StorageRunRepository::latest((int)$table->id, StorageRunRepository::KIND_VERIFY, $this->storage($table)->generation());
            $transitions->cutover($table, new CutoverConfirmation((int)\Exment::getUserId(), (int)$run->id, (int)$run->generation,
                StorageL5Report::fromRun($run)->version(), new \DateTimeImmutable()), $this->attestation());
            $this->assertTrue($seen('cutover')->readsPhysical(), 'a new request reads the typed columns right after the cutover');
            $this->assertStringContainsString($pcol, $keyOf(), 'and queries the typed column');

            $transitions->rollback($table, $this->attestation(), true);
            $this->assertFalse($seen('rollback')->readsPhysical(), 'a new request stops reading the typed columns right after the rollback');
            $this->assertStringNotContainsString($pcol, $keyOf());

            $transitions->cancel($table);
            $seen('cancel');
            $cleanup = app(StagingCleanup::class);
            $cleanup->startConfirmed($table, $name);
            $this->runJob($table, StorageConversionJob::OP_CLEANUP);
            $seen('cleanup');
            $manager->deprovision($table);
            $this->assertTrue($seen('deprovision')->isJson());
            $this->assertFalse(Schema::hasColumn($db, $pcol));
            // the columns are gone: a new request must not name them
            $this->assertStringNotContainsString($pcol, $keyOf());
            $this->assertSame('Alpha', $this->model($table, $ids['normal'])->getValue('label'));
        } finally {
            config(['exment.use_cache' => $previous]);
            System::clearCache();
        }
    }
    public function testExportAndRestrictedUserQueriesInPhysicalRead(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $integer = CustomColumn::getEloquent('integer', $table);
        $date = CustomColumn::getEloquent('date', $table);
        $view = \Exceedone\Exment\Model\CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'stageb restricted',
            'view_type' => \Exceedone\Exment\Enums\ViewType::SYSTEM,
            'view_kind_type' => \Exceedone\Exment\Enums\ViewKindType::DEFAULT,
        ]);
        $filter = new \Exceedone\Exment\Model\CustomViewFilter();
        $filter->custom_view_id = $view->id;
        $filter->view_column_type = \Exceedone\Exment\Enums\ConditionType::COLUMN;
        $filter->view_column_table_id = $table->id;
        $filter->view_column_target_id = $integer->id;
        $filter->view_filter_condition = \Exceedone\Exment\Enums\FilterOption::NUMBER_GT;
        $filter->view_filter_condition_value_text = '50';
        $filter->save();
        $sort = new \Exceedone\Exment\Model\CustomViewSort();
        $sort->custom_view_id = $view->id;
        $sort->view_column_type = \Exceedone\Exment\Enums\ConditionType::COLUMN;
        $sort->view_column_table_id = $table->id;
        $sort->view_column_target_id = $date->id;
        $sort->sort = -1;
        $sort->priority = 1;
        $sort->save();

        $observe = function () use ($table, $view) {
            System::clearCache();
            $t = CustomTable::getEloquent($table->id);
            $out = [];
            // export (admin) - on a clean request: the grid filter reads the current request, and an
            // earlier HTTP call of this test would leave its ?view= (a harness artifact)
            $this->app->instance('request', \Illuminate\Http\Request::create('/'));
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            System::clearRequestSession();
            $classname = getModelName($t);
            $provider = new \Exceedone\Exment\Services\DataImportExport\Providers\Export\DefaultTableProvider([
                'custom_table' => $t, 'grid' => new \ExmentAdminCore\Admin\Grid(new $classname()),
            ]);
            $out['export'] = $provider->data();
            // user2: view query (permission scope + filter + sort)
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
            System::clearRequestSession();
            $v = \Exceedone\Exment\Model\CustomView::find($view->id);
            $q = $t->getValueQuery();
            $v->filterSortModel($q);
            $out['sql'] = $q->toSql();
            $out['user2_ids'] = array_map('intval', $q->pluck('id')->all());
            // user2: grid rows through the HTTP kernel (lazy rows)
            app('auth')->forgetGuards();
            $this->actingAs(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2), 'admin');
            $html = (string)$this->get(admin_url("data/{$t->table_name}?view={$v->suuid}&per_page=100&lazy_grid_rows=1"), ['X-Requested-With' => 'XMLHttpRequest'])->baseResponse->getContent();
            preg_match_all('/data-(?:key|id)="(\d+)"/', $html, $m);
            $out['grid_ids'] = array_values(array_unique(array_map('intval', $m[1])));
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            System::clearRequestSession();
            return $out;
        };

        $json = $observe();
        $this->assertNotEmpty($json['user2_ids']);
        $this->assertLessThan($table->getValueModel()->withoutGlobalScopes()->count(), count($json['user2_ids']), 'user2 sees a subset');
        $this->toPhysicalRead($table);
        $physical = $observe();

        $pcolInteger = PhysicalNaming::stagingColumn($integer);
        $this->assertStringNotContainsString($pcolInteger, $json['sql']);
        $this->assertStringContainsString($pcolInteger, $physical['sql'], 'the filter reads the typed column');
        // the view sorts by one column: rows with an equal sort value come back in a plan-dependent order (any plan,
        // any collation), so the check is the same MEMBERS and the same SEQUENCE OF SORT VALUES, not the order inside a tie
        $dateOf = function (int $id) use ($table) {
            return (string)(json_decode((string)DB::table(getDBTableName($table))->where('id', $id)->value('value'), true)['date'] ?? '');
        };
        foreach (['user2_ids' => 'user2 view query', 'grid_ids' => 'user2 grid rows'] as $key => $label) {
            $members = [$json[$key], $physical[$key]];
            sort($members[0]);
            sort($members[1]);
            $this->assertSame($members[0], $members[1], "$label: same rows");
            $this->assertSame(array_map($dateOf, $json[$key]), array_map($dateOf, $physical[$key]), "$label: same sequence of sort values (ties in any order)");
        }
        // timestamps are Carbon objects: compare the exported values, not object identities
        $this->assertSame(count($json['export']), count($physical['export']), 'export row count');
        foreach ($json['export'] as $i => $row) {
            $this->assertSame(json_encode($row), json_encode($physical['export'][$i]), "export row $i");
        }
        $this->assertGreaterThan(1, count($physical['export']), 'header + rows exported');
    }

    // ------------------------------------------------------------------ transition race

    /** Leaves hyb_stageb_race provisioned, for the two-process writer / transition probe. */
    public function testPrepareTransitionRace(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_race');
        $table->getValueModel()->setValue(['num' => '1', 'label' => 'race'])->save();
        app(PhysicalSchemaManager::class)->provision($table);
        $this->assertSame(StorageState::PROVISIONED, $this->storage($table)->state());
    }

    // ------------------------------------------------------------------ concurrent workers

    /** Leaves hyb_stageb_dup in backfilling with a started run, for the two-process worker probe. */
    public function testPrepareConcurrentBackfill(): void
    {
        $table = $this->createFixtureTable('hyb_stageb_dup');
        $this->seedRows($table);
        for ($i = 0; $i < 40; $i++) {
            $table->getValueModel()->setValue(['num' => (string)$i, 'label' => "row $i"])->save();
        }
        app(PhysicalSchemaManager::class)->provision($table);
        app(StorageTransitions::class)->startDualWrite($table);
        sleep(1);
        app(StorageTransitions::class)->startBackfill($table, $this->attestation());
        $runId = app(StorageBackfill::class)->start($table);
        $this->assertGreaterThan(0, $runId);
    }
}
