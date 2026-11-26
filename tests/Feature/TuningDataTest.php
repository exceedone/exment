<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Exceedone\Exment\Services\SystemRequire\Tuning\AttachmentTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\DatabaseSettingTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\DataStats;
use Exceedone\Exment\Services\SystemRequire\Tuning\DeletedRowsTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\RevisionTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\TableSizeTuning;
use Exceedone\Exment\Services\TrashPurge;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The data on the tuning page (ISS02927): counted at night (exment:tuning-data), read by the page;
 * "not counted yet" before; the advice of each item; nothing is changed.
 */
class TuningDataTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::tuning_data_stats([]);
        System::clearCache();
    }

    protected function tearDown(): void
    {
        System::tuning_data_stats([]);
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        return $table;
    }

    /**
     * Counts of one table, as DataStats keeps them.
     *
     * @param array<string, mixed> $counts
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    protected function stats(array $counts, array $more = []): array
    {
        return array_merge([
            'time' => time(),
            'tables' => [$this->table()->table_name => array_merge([
                'rows' => 10, 'deleted' => 0, 'revisions' => 0, 'data_bytes' => null, 'index_bytes' => null, 'previous_rows' => null, 'previous_time' => null,
            ], $counts)],
            'files' => ['count' => 0, 'bytes' => 0, 'bytes_partial' => false, 'orphans' => 0],
            'database' => ['bytes' => null],
        ], $more);
    }

    public function testNotCountedYet(): void
    {
        $this->assertNull(DataStats::load());
        foreach ([new TableSizeTuning(), new RevisionTuning(), new DeletedRowsTuning(), new AttachmentTuning()] as $item) {
            $this->assertSame(SystemRequireResult::CANNOT_CHECK, $item->checkResult(), get_class($item));
            $this->assertSame(exmtrans('tuning.data_not_counted'), $item->getResultText());
            $this->assertSame(exmtrans('tuning.data_not_counted_help'), $item->getMessage());
            $this->assertNotNull($item->getResultClassSet());
        }
    }

    public function testTheCountOfTheNight(): void
    {
        $table = $this->table();
        $model = $table->getValueModel();
        $all = $model->withTrashed()->count();
        $deleted = $model->onlyTrashed()->count();
        $first = $model->query()->first();
        $this->assertNotNull($first);
        $first->delete();
        // an attachment of data deleted for good
        $orphan = new ExmentFile();
        $orphan->uuid = make_uuid();
        $orphan->local_dirname = $table->table_name;
        $orphan->local_filename = 'tuning_orphan.txt';
        $orphan->filename = 'tuning_orphan.txt';
        $orphan->parent_type = $table->table_name;
        $orphan->parent_id = 99999999;
        $orphan->save();

        $this->assertSame(0, \Artisan::call('exment:tuning-data'));
        $stats = DataStats::load();
        $this->assertNotNull($stats);
        $counts = $stats['tables'][$table->table_name];
        $this->assertSame($deleted + 1, $counts['deleted']);
        $this->assertSame($all - $deleted - 1, $counts['rows']);
        $this->assertSame(\DB::table('revisions')->where('revisionable_type', $table->table_name)->count(), $counts['revisions']);
        $this->assertNull($counts['previous_rows']);
        $this->assertGreaterThanOrEqual(1, $stats['files']['orphans']);
        $this->assertSame(ExmentFile::count(), $stats['files']['count']);
        if (DataStats::isMySql()) {
            $this->assertIsInt($counts['data_bytes']);
            // the whole database, more than the custom tables
            $custom = array_sum(array_map(function ($table) {
                return intval($table['data_bytes']) + intval($table['index_bytes']);
            }, $stats['tables']));
            $this->assertGreaterThan($custom, $stats['database']['bytes']);
        }
        // the count before is kept for the growth
        DataStats::collect();
        $this->assertSame($counts['rows'], DataStats::load()['tables'][$table->table_name]['previous_rows']);

        // it changes no data
        $this->assertSame($all, $model->withTrashed()->count());
        $this->assertNotNull(ExmentFile::find($orphan->uuid));
    }

    public function testTheLargestTablesAndTheGrowth(): void
    {
        $item = new TableSizeTuning($this->stats(['rows' => 120000, 'previous_rows' => 1000]));
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertStringContainsString('1,000 → 120,000', strval($item->getResultText()));

        $item = new TableSizeTuning($this->stats(['rows' => 120000, 'previous_rows' => 100000, 'data_bytes' => 1048576, 'index_bytes' => 0]));
        $this->assertSame(SystemRequireResult::OK, $item->checkResult());
        $this->assertStringContainsString($this->table()->table_view_name . ': 120,000', strval($item->getResultText()));
    }

    public function testRevisions(): void
    {
        $ok = new RevisionTuning($this->stats(['revisions' => 1000]));
        $this->assertSame(SystemRequireResult::OK, $ok->checkResult());
        // the numbers, also when it is OK
        $this->assertStringContainsString($this->table()->table_view_name . ': 1,000', strval($ok->getResultText()));
        $item = new RevisionTuning($this->stats(['revisions' => RevisionTuning::LIMIT + 1]));
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertSame(admin_urls('table', $this->table()->id, 'edit'), $item->getSettingUrl());
    }

    public function testDeletedRows(): void
    {
        $table = $this->table();
        $many = ['rows' => 20000, 'deleted' => 20000];
        $small = new DeletedRowsTuning($this->stats(['rows' => 200000, 'deleted' => 20000]));
        $this->assertSame(SystemRequireResult::OK, $small->checkResult(), 'a small share');
        $this->assertStringContainsString($this->table()->table_view_name . ': 20,000', strval($small->getResultText()));
        if (!TrashPurge::purgeable($table)) {
            $this->markTestSkipped('the table does not purge');
        }
        $table->setOption(TrashPurge::OPTION, null);
        $table->save();
        $item = new DeletedRowsTuning($this->stats($many));
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertStringContainsString('20,000', strval($item->getResultText()));
        // deleted for good after some days: no advice
        $table->setOption(TrashPurge::OPTION, 30);
        $table->save();
        System::clearRequestSession();
        $this->assertSame(SystemRequireResult::OK, (new DeletedRowsTuning($this->stats($many)))->checkResult());
    }

    public function testAttachments(): void
    {
        $item = new AttachmentTuning($this->stats([], ['files' => ['count' => 12, 'bytes' => 2048, 'bytes_partial' => false, 'orphans' => 0]]));
        $this->assertSame(SystemRequireResult::OK, $item->checkResult());
        $item = new AttachmentTuning($this->stats([], ['files' => ['count' => 12, 'bytes' => 2048, 'bytes_partial' => true, 'orphans' => 3]]));
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertStringContainsString(exmtrans('tuning.item.attachments.orphans', ['count' => 3]), strval($item->getResultText()));
    }

    public function testTheMemoryOfTheDatabase(): void
    {
        $gb = 1024 * 1024 * 1024;
        $item = (new DatabaseSettingTuning($this->stats([], ['database' => ['bytes' => 2 * $gb]])))->setBufferPool(128 * 1024 * 1024);
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $this->assertSame("[mysqld]\ninnodb_buffer_pool_size = 2560M", $item->getFixText());

        $item = (new DatabaseSettingTuning($this->stats([], ['database' => ['bytes' => $gb]])))->setBufferPool(2 * $gb);
        $this->assertSame(SystemRequireResult::OK, $item->checkResult());
        $this->assertNull($item->getFixText());

        // read from the database (MySQL, MariaDB)
        if (DataStats::isMySql()) {
            $read = (new DatabaseSettingTuning($this->stats([], ['database' => ['bytes' => $gb]])))->bufferPool();
            $this->assertIsInt($read);
            $this->assertGreaterThan(0, $read);
        }

        // another database, or not readable
        $item = (new DatabaseSettingTuning($this->stats([], ['database' => ['bytes' => $gb]])))->setBufferPool(null);
        $this->assertSame(SystemRequireResult::CANNOT_CHECK, $item->checkResult());
        $this->assertSame(exmtrans('tuning.item.database_settings.not_read'), $item->getResultText());
    }

    public function testThePageAndTheSchedule(): void
    {
        $this->assertArrayHasKey('exment:tuning-data', ScheduledJobs::JOBS);
        $this->assertNotNull(ScheduledJobs::event('exment:tuning-data'));

        config(['exment.features_locked' => '']);
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('data-tuning="table_size"', $content);
        $this->assertStringContainsString(e(exmtrans('tuning.data_not_counted')), $content);

        DataStats::collect();
        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringNotContainsString(e(exmtrans('tuning.data_not_counted_help')), $content);
    }
}
