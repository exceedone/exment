<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\DataAccessLogs;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The reads and the writes of the data (ISS02905): a row each, from the page, the API or the
 * system; a read again in the minutes is not written; the reads only when the operation log keeps
 * everything; the counts of each day, and the rows deleted only once counted.
 */
class DataAccessLogTest extends ApiTestBase
{
    use DatabaseTransactions;

    /** @var int */
    protected $last = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DataAccessLog::resetTableExists();
        LogCatalog::reset();
        System::operation_log_scope(LogSettings::SCOPE_ALL);
        System::clearCache();
        $this->last = intval(DataAccessLog::query()->max('id'));
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($user);
        System::clearRequestSession();
    }

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $this->assertNotNull($table);
        return $table;
    }

    protected function newRecord(): CustomValue
    {
        $record = $this->table()->getValueModel();
        $record->setValue('text', 'DataAccessLogTest ' . uniqid());
        $record->save();
        return $record;
    }

    /**
     * The rows written in the test: kind, via, record.
     *
     * @return array<int, array{0: string, 1: string, 2: int|null}>
     */
    protected function rows(): array
    {
        return DataAccessLog::query()->where('id', '>', $this->last)->where('custom_table_id', $this->table()->id)->orderBy('id')->get()->map(function (DataAccessLog $row) {
            return [$row->kind, $row->via, is_null($row->record_id) ? null : intval($row->record_id)];
        })->all();
    }

    public function testTheWrites(): void
    {
        $record = $this->newRecord();
        $id = intval($record->id);
        $record->setValue('text', 'changed')->save();
        $record->delete();
        $record->restore();
        $record->forceDelete();
        $this->assertSame([
            [DataAccessLog::KIND_CREATE, DataAccessLog::VIA_SYSTEM, $id],
            [DataAccessLog::KIND_UPDATE, DataAccessLog::VIA_SYSTEM, $id],
            // the saves of the delete and of the restore are not changes
            [DataAccessLog::KIND_DELETE, DataAccessLog::VIA_SYSTEM, $id],
            [DataAccessLog::KIND_RESTORE, DataAccessLog::VIA_SYSTEM, $id],
            [DataAccessLog::KIND_FORCE_DELETE, DataAccessLog::VIA_SYSTEM, $id],
        ], $this->rows());
        $row = DataAccessLog::query()->where('id', '>', $this->last)->firstOrFail();
        $this->assertEquals(\Exment::getUserId(), $row->base_user_id);
    }

    public function testTheReadsOfThePagesAndTheApi(): void
    {
        $record = $this->newRecord();
        $id = intval($record->id);
        $table = $this->table()->table_name;
        $this->last = intval(DataAccessLog::query()->max('id'));

        $this->get(admin_urls('data', $table, $id))->assertOk();
        // again in the minutes: not written
        $this->get(admin_urls('data', $table, $id))->assertOk();
        $this->get(admin_urls('data', $table))->assertOk();

        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'data', $table, $id))->assertOk();
        $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'data', $table))->assertOk();

        $this->assertSame([
            [DataAccessLog::KIND_READ, DataAccessLog::VIA_PAGE, $id],
            [DataAccessLog::KIND_LIST, DataAccessLog::VIA_PAGE, null],
            [DataAccessLog::KIND_READ, DataAccessLog::VIA_API, $id],
            [DataAccessLog::KIND_LIST, DataAccessLog::VIA_API, null],
        ], $this->rows());
        $this->assertNotNull(DataAccessLog::query()->where('id', '>', $this->last)->where('kind', DataAccessLog::KIND_LIST)->where('via', DataAccessLog::VIA_PAGE)->value('custom_view_id'), 'the view of the list');
    }

    public function testNoReadsWhenTheLogKeepsTheChangesOnly(): void
    {
        System::operation_log_scope(LogSettings::SCOPE_CHANGES);
        System::clearCache();
        $record = $this->newRecord();
        $this->get(admin_urls('data', $this->table()->table_name, $record->id))->assertOk();
        $this->assertSame([[DataAccessLog::KIND_CREATE, DataAccessLog::VIA_SYSTEM, intval($record->id)]], $this->rows());
    }

    public function testTheCountsOfEachDay(): void
    {
        $table = $this->table();
        $user = intval(\Exment::getUserId());
        $row = ['base_user_id' => $user, 'custom_table_id' => $table->id, 'kind' => DataAccessLog::KIND_READ, 'via' => DataAccessLog::VIA_PAGE];
        foreach (['2020-01-01 09:00:00', '2020-01-01 10:00:00', '2020-01-01 23:59:59', '2020-01-02 08:00:00', '2020-01-03 08:00:00'] as $at) {
            DataAccessLog::query()->insert($row + ['record_id' => 1, 'created_at' => $at]);
        }
        DataAccessLog::query()->insert(['kind' => DataAccessLog::KIND_UPDATE, 'record_id' => 1, 'created_at' => '2020-01-02 09:00:00'] + $row);
        DataAccessDaily::query()->where('date', '<', '2020-01-04')->delete();
        System::data_access_summarized_until('2019-12-31');
        System::clearCache();

        $this->assertSame(2, DataAccessLogs::summarize(Carbon::parse('2020-01-03 12:00:00')));
        $counts = DataAccessDaily::query()->where('custom_table_id', $table->id)->where('base_user_id', $user)->orderBy('date')->orderBy('kind')->get()->map(function (DataAccessDaily $daily) {
            return [$daily->date->format('Y-m-d'), $daily->kind, $daily->count];
        })->all();
        $this->assertSame([['2020-01-01', 'read', 3], ['2020-01-02', 'read', 1], ['2020-01-02', 'update', 1]], $counts);
        $this->assertSame('2020-01-02', optional(DataAccessLogs::summarizedUntil())->format('Y-m-d'));

        // the rows are deleted only once counted: the 3rd stays
        System::log_retention_data_access(1);
        System::clearCache();
        LogCatalog::prune(Carbon::parse('2020-06-01'));
        $left = DataAccessLog::query()->where('custom_table_id', $table->id)->where('created_at', '<', '2020-01-04')->pluck('created_at')->map(function ($at) {
            return Carbon::parse($at)->format('Y-m-d');
        })->all();
        $this->assertSame(['2020-01-03'], $left);
        $this->assertSame(3, DataAccessDaily::query()->where('custom_table_id', $table->id)->where('date', '<', '2020-01-04')->count(), 'the counts stay');
    }
}
