<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\DefaultTableProvider;
use Exceedone\Exment\Controllers\ApiDataController;
use Illuminate\Http\Request;

/**
 * Regression tests for the "one_record_flg" limitation added by commit 713bd2a.
 *
 * The commit guards CustomTable::enableCreate() with
 *     if ($this->isOneRecord() && $this->getValueModel()->query()->exists())
 * That guard is evaluated ONCE against the live DB. It correctly blocks the
 * single-record create paths, but it is blind to sibling NEW rows created in the
 * same batch. These tests prove the two batch write-paths the commit left open:
 *
 *   - IMPORT: CustomTableAction::import validates ALL rows BEFORE saving ANY, so
 *     every new row of a 2+ row file sees the empty pre-import DB and passes.
 *
 * The seeded table "table_a" is a one_record_flg table with a single text column
 * "name" and zero rows, which is exactly the empty one-record table needed.
 *
 * Each test runs inside a DB transaction (DatabaseTransactions) so any rows it
 * inserts are rolled back — the tests never leave data behind.
 */
class OneRecordBatchBypassTest extends TestCase
{
    use DatabaseTransactions;

    /** seeded one_record_flg table, single text column "name", initially empty */
    const ONE_RECORD_TABLE = 'table_a';

    protected function setUp(): void
    {
        parent::setUp();

        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        $this->be(LoginUser::find('1'));
    }

    protected function oneRecordTable(): CustomTable
    {
        $table = CustomTable::getEloquent(self::ONE_RECORD_TABLE);
        $this->assertInstanceOf(CustomTable::class, $table, 'Seeded table "' . self::ONE_RECORD_TABLE . '" not found.');
        // make sure the flag is on regardless of seed drift
        $table->setOption('one_record_flg', 1);
        $this->assertTrue(boolval($table->isOneRecord()), 'Precondition: table must be one-record.');
        $this->assertSame(0, (int)$table->getValueModel()->query()->count(), 'Precondition: table must start empty.');
        return $table;
    }

    /**
     * Importing a file with 2+ NEW rows into an empty one-record table must never
     * leave the table with more than one record.
     *
     * Mirrors CustomTableAction::import for a single table: validate the whole
     * sheet, and only save when validation produced no errors.
     */
    public function testImportCannotCreateSecondRecordInOneRecordTable()
    {
        $table = $this->oneRecordTable();

        $provider = new DefaultTableProvider([
            'custom_table' => $table,
            'primary_key'  => 'id',
        ]);

        // raw sheet: row0 = header keys, row1 = label row (skipped), row2.. = data
        $raw = [
            0 => ['value.name'],
            1 => ['Name'],
            2 => ['ALPHA'],
            3 => ['BETA'],
        ];

        $dataObject = $provider->getDataObject($raw, []);
        $this->assertCount(2, $dataObject, 'Precondition: the import file yields two NEW rows.');

        list($data_import, $error_data) = $provider->validateImportData($dataObject);

        // Mirror CustomTableAction::import: a non-empty error list aborts the whole
        // import without saving anything; otherwise every validated row is saved.
        try {
            if (empty($error_data)) {
                foreach ($data_import as $row) {
                    $row['data'] = $provider->dataProcessing($row['data']);
                    $provider->importData($row);
                }
            }
        } catch (\Throwable $e) {
            // A save-time guard rejecting the extra row is an acceptable enforcement
            // strategy too; the invariant below is what actually matters.
        }

        $count = (int)$table->getValueModel()->query()->count();
        $this->assertLessThanOrEqual(
            1,
            $count,
            "A one-record table (one_record_flg=1) must never hold more than one row, even via import. "
            . "Found {$count}. Import validates every row against the pre-import DB state, so multiple NEW "
            . "rows in a single file all pass enableCreate() and get saved."
        );
    }

    /**
     * The 2nd new row must be rejected with the one-record error so the whole
     * import is aborted (CustomTableAction::import returns on a non-empty error
     * list) rather than silently dropping the extra row.
     */
    public function testImportSecondRowRejectedWithOneRecordError()
    {
        $table = $this->oneRecordTable();
        $provider = new DefaultTableProvider(['custom_table' => $table, 'primary_key' => 'id']);

        $raw = [0 => ['value.name'], 1 => ['Name'], 2 => ['ALPHA'], 3 => ['BETA']];
        $dataObject = $provider->getDataObject($raw, []);

        list($data_import, $error_data) = $provider->validateImportData($dataObject);

        $this->assertNotEmpty($error_data, 'Importing a 2nd new row into a one-record table must produce a validation error.');
        $this->assertCount(1, $data_import, 'Only the first new row may be accepted.');
        $message = \Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY()->getMessage();
        $this->assertStringContainsString(
            $message,
            implode("\n", $error_data),
            'The rejection must use the one-record error message.'
        );
    }

    /**
     * Regression guard: importing a SINGLE new row into an empty one-record table
     * must still succeed and create exactly one record.
     */
    public function testImportSingleRowStillWorks()
    {
        $table = $this->oneRecordTable();
        $provider = new DefaultTableProvider(['custom_table' => $table, 'primary_key' => 'id']);

        $raw = [0 => ['value.name'], 1 => ['Name'], 2 => ['SOLO']];
        $dataObject = $provider->getDataObject($raw, []);
        $this->assertCount(1, $dataObject);

        list($data_import, $error_data) = $provider->validateImportData($dataObject);
        $this->assertEmpty($error_data, 'A single new row must not be blocked.');

        foreach ($data_import as $row) {
            $row['data'] = $provider->dataProcessing($row['data']);
            $provider->importData($row);
        }

        $this->assertSame(1, (int)$table->getValueModel()->query()->count(), 'The first record must be created.');
    }

    /**
     * API bulk-create: POSTing a vector body of 2+ value objects to an empty
     * one-record table must not create more than one record. Drives the real
     * ApiDataController::saveData() the way dataCreate() does.
     */
    public function testApiBulkCreateCannotExceedOneRecord()
    {
        $table = $this->oneRecordTable();

        $request = Request::create('/api/data/' . self::ONE_RECORD_TABLE, 'POST', [
            'value' => [
                ['name' => 'ALPHA'],
                ['name' => 'BETA'],
            ],
        ]);

        $controller = new ApiDataController($table, $request);

        $method = new \ReflectionMethod($controller, 'saveData');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $request);

        $count = (int)$table->getValueModel()->query()->count();
        $this->assertLessThanOrEqual(
            1,
            $count,
            "API bulk-create must never create more than one record in a one-record table. Found {$count}."
        );

        // The request must be rejected (not a successful create response).
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $result, 'Bulk create on a one-record table must be rejected.');
        $this->assertSame(403, $result->getStatusCode());
        $payload = json_decode($result->getContent(), true);
        $this->assertEquals(\Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY, $payload['code'] ?? null, 'Rejection must carry error code 402.');
    }

    /**
     * KH feedback (3) / TC-DA-08 message: the API bulk-create rejection on a
     * one-record table must carry ONLY the first sentence ("このテーブルは1レコ
     * ードのみ登録可能です。"). The old text appended "すでにレコードが存在するた
     * め、新規作成できません。", which implies the first row is kept - but the
     * reject-all path creates nothing, so that second sentence must be gone.
     */
    public function testApiBulkCreateRejectionMessageIsSingleSentence()
    {
        $table = $this->oneRecordTable();

        $request = Request::create('/api/data/' . self::ONE_RECORD_TABLE, 'POST', [
            'value' => [
                ['name' => 'ALPHA'],
                ['name' => 'BETA'],
            ],
        ]);

        $controller = new ApiDataController($table, $request);
        $method = new \ReflectionMethod($controller, 'saveData');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $request);

        $this->assertSame(403, $result->getStatusCode());
        $payload = json_decode($result->getContent(), true);
        $this->assertEquals(\Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY, $payload['code'] ?? null, 'Rejection must carry error code 402.');

        // the response message is the ErrorCode's own (single-sentence) message ...
        $this->assertSame(
            \Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY()->getMessage(),
            $payload['message'] ?? null,
            'Bulk-create rejection must use the one-record ErrorCode message.'
        );
        // ... and must NOT contain the removed second sentence.
        $this->assertStringNotContainsString('すでにレコードが存在', $payload['message'] ?? '');
        $this->assertStringNotContainsString('新規作成できません', $payload['message'] ?? '');

        // reject-all: nothing was created.
        $this->assertSame(0, (int)$table->getValueModel()->query()->count(), 'Reject-all must create zero records.');

        // Pin the exact one-sentence text in both shipped locales so the removed
        // second sentence can never silently reappear via the lang files.
        $original = app()->getLocale();
        try {
            app()->setLocale('ja');
            $this->assertSame(
                'このテーブルは1レコードのみ登録可能です。',
                \Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY()->getMessage()
            );
            app()->setLocale('en');
            $this->assertSame(
                'This table allows only one record.',
                \Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY()->getMessage()
            );
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * KH feedback (3), full-table path: creating into a one-record table that
     * ALREADY holds a record must return 403 / code 402 with the one-record
     * message - NOT the generic "Permission denied" that dataCreate() returned
     * before (it hard-coded admin.deny for every enableCreate() failure).
     * Drives the real public dataCreate().
     */
    public function testApiSingleCreateOnFullOneRecordTableReturnsOneRecordMessage()
    {
        $table = $this->oneRecordTable();

        // seed exactly one existing record (reuse the proven single-row import path)
        $provider = new DefaultTableProvider(['custom_table' => $table, 'primary_key' => 'id']);
        $raw = [0 => ['value.name'], 1 => ['Name'], 2 => ['EXISTING']];
        list($data_import, $error_data) = $provider->validateImportData($provider->getDataObject($raw, []));
        $this->assertEmpty($error_data, 'Precondition: seeding one record must succeed.');
        foreach ($data_import as $row) {
            $row['data'] = $provider->dataProcessing($row['data']);
            $provider->importData($row);
        }
        $this->assertSame(1, (int)$table->getValueModel()->query()->count(), 'Precondition: table now holds one record.');

        // now attempt a single create - the table is full
        $request = Request::create('/api/data/' . self::ONE_RECORD_TABLE, 'POST', [
            'value' => ['name' => 'SECOND'],
        ]);
        $controller = new ApiDataController($table, $request);
        $result = $controller->dataCreate($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $result);
        $this->assertSame(403, $result->getStatusCode());
        $payload = json_decode($result->getContent(), true);
        $this->assertEquals(\Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY, $payload['code'] ?? null, 'Full one-record create must carry error code 402, not 101.');
        $this->assertSame(
            \Exceedone\Exment\Enums\ErrorCode::ONE_RECORD_ALREADY()->getMessage(),
            $payload['message'] ?? null,
            'Full one-record create must show the one-record message, not "Permission denied".'
        );
        $this->assertNotSame(trans('admin.deny'), $payload['message'] ?? null, 'Must not be the generic permission-denied message.');

        // still exactly one record - the rejected create added nothing.
        $this->assertSame(1, (int)$table->getValueModel()->query()->count(), 'The rejected create must not add a second record.');
    }
}
