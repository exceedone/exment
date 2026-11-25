<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The reads and the writes of the data in the IT audit report (ISS02905): the counts of each day
 * and the rows not counted yet, once each; the users who read unusually much; the filters; who
 * read and changed a record, for the users with Audit only.
 */
class AuditReportDataAccessTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        DataAccessLog::resetTableExists();
        System::operation_log_scope(LogSettings::SCOPE_ALL);
        System::clearCache();
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    /**
     * @param int|string $id
     */
    protected function user($id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        return $user;
    }

    /**
     * @param int|string $id
     */
    protected function loginAs($id): void
    {
        $this->be($this->user($id));
        System::clearRequestSession();
    }

    protected function table(string $name = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL): CustomTable
    {
        $table = CustomTable::getEloquent($name);
        $this->assertNotNull($table);
        return $table;
    }

    /**
     * Rows in January 2020: counted to the 2nd, raw from the 3rd.
     */
    protected function data(): void
    {
        $admin = intval($this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id);
        $user1 = intval($this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id);
        $user2 = intval($this->user(TestDefine::TESTDATA_USER_LOGINID_USER2)->base_user_id);
        $table = intval($this->table()->id);
        $other = intval($this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->id);
        DataAccessLog::query()->where('created_at', '<', '2020-02-01')->delete();
        DataAccessDaily::query()->where('date', '<', '2020-02-01')->delete();
        System::data_access_summarized_until('2020-01-02');
        System::clearCache();

        $daily = function (string $date, int $user, int $table, string $kind, int $count) {
            DataAccessDaily::query()->insert(['date' => $date, 'base_user_id' => $user, 'custom_table_id' => $table, 'kind' => $kind, 'via' => 'page', 'count' => $count]);
        };
        $daily('2020-01-01', $admin, $table, 'read', 300);
        $daily('2020-01-02', $admin, $table, 'list', 200);
        $daily('2020-01-02', $user1, $table, 'read', 10);
        $daily('2020-01-02', $user2, $other, 'read', 10);
        $raw = function (string $at, int $user, int $table, string $kind, ?int $record = 1) {
            DataAccessLog::query()->insert(['base_user_id' => $user, 'custom_table_id' => $table, 'record_id' => $record, 'kind' => $kind, 'via' => 'api', 'created_at' => $at]);
        };
        // a row of a day counted: in the counts already, not again
        $raw('2020-01-01 10:00:00', $admin, $table, 'read');
        $raw('2020-01-03 10:00:00', $admin, $table, 'read');
        $raw('2020-01-03 11:00:00', $user1, $table, 'update');
        $raw('2020-01-04 11:00:00', $user1, $table, 'update');
        $raw('2020-01-04 12:00:00', $user1, $table, 'force_delete');
        // after the period
        $raw('2020-01-10 12:00:00', $admin, $table, 'read');
    }

    public function testTheCountsAndTheHeavyReaders(): void
    {
        $this->data();
        $report = new AuditReport(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-05'));
        $table = strval($this->table()->table_view_name);
        $other = strval($this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL)->table_view_name);
        $rows = $report->section('data_access')['rows'];
        // table, user code, user name, read, list, export, create, update, delete, restore
        $find = function (string $tableName, string $userId) use ($rows) {
            foreach ($rows as $row) {
                if ($row[0] === $tableName && $row[1] === $userId) {
                    return array_slice($row, 3);
                }
            }
            return null;
        };
        $this->assertSame(['301', '200', '0', '0', '0', '0', '0'], $find($table, 'admin'));
        $this->assertSame(['10', '0', '0', '0', '2', '1', '0'], $find($table, 'user1'));
        $this->assertSame(['10', '0', '0', '0', '0', '0', '0'], $find($other, 'user2'));
        $this->assertCount(3, $rows);

        // admin: 501 reads, the median 10
        $heavy = $report->section('data_access_heavy')['rows'];
        $this->assertCount(1, $heavy);
        $this->assertSame(['admin', '501', '10.0', '50.1'], [$heavy[0][0], $heavy[0][2], $heavy[0][3], $heavy[0][4]]);

        // a table, a user
        $only = (new AuditReport(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-05')))
            ->filterData(intval($this->table()->id), intval($this->user(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id));
        $this->assertSame([[$table, 'user1']], array_map(function ($row) {
            return [$row[0], $row[1]];
        }, $only->section('data_access')['rows']));

        // the page and the CSV, with the filter
        $query = ['from' => '2020-01-01', 'to' => '2020-01-05', 'data_user' => 'user1'];
        $this->get(admin_urls_query('log_management', 'audit', $query))->assertOk()->assertSee(exmtrans('audit_report.section.data_access'))->assertSee('value="user1"', false);
        $csv = $this->get(admin_urls_query('log_management', 'audit', 'data_access.csv', $query));
        $csv->assertOk();
        $this->assertStringContainsString('user1', strval($csv->streamedContent()));
        $this->assertStringNotContainsString('admin', strval($csv->streamedContent()));
    }

    public function testTheReadsNotRecorded(): void
    {
        System::operation_log_scope(LogSettings::SCOPE_CHANGES);
        System::clearCache();
        $report = new AuditReport(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-05'));
        $this->assertStringContainsString(exmtrans('audit_report.note.reads_not_recorded'), strval($report->section('data_access')['note']));
    }

    public function testWhoReadAndChangedARecord(): void
    {
        $table = $this->table();
        $record = $table->getValueModel();
        $record->setValue('text', 'AuditReportDataAccessTest ' . uniqid());
        $record->save();
        $id = intval($record->id);

        // the detail page: the box, with the record made and read
        $page = $this->get(admin_urls('data', $table->table_name, $id));
        $page->assertOk()->assertSee(exmtrans('audit_report.record_access.header'));
        $this->get(admin_urls('data', $table->table_name, $id))->assertSee(exmtrans('audit_report.data_kind.create'))->assertSee(exmtrans('audit_report.data_kind.read'));
        $this->get(admin_urls_query('log_management', 'audit', 'record', ['table' => $table->table_name, 'id' => $id]))->assertOk()->assertSee(exmtrans('audit_report.data_kind.create'));

        // a user without Audit: no box, the page refused
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_urls('data', $table->table_name, $id))->assertOk()->assertDontSee(exmtrans('audit_report.record_access.header'));
        $denied = $this->get(admin_urls_query('log_management', 'audit', 'record', ['table' => $table->table_name, 'id' => $id]), static::AJAX);
        $denied->assertStatus(403);
        $denied->assertDontSee(exmtrans('audit_report.data_kind.create'));
    }
}
