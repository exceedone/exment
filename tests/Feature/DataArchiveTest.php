<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\DataArchive;
use Exceedone\Exment\Model\DataArchiveEvent;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataArchive\ArchiveException;
use Exceedone\Exment\Services\DataArchive\ArchiveStore;
use Exceedone\Exment\Services\DataArchive\DataArchiver;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The data archive (ISS02970, part 1): the preview, the archive (deleted from the database only
 * once the file in its place holds the rows), the restore, the trash, the legal hold and the purge.
 * The database is rolled back; the files are removed.
 */
class DataArchiveTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TYPE = 'iss2970_test';

    /** The rows are of 2001: older than every other row of the tests. */
    protected const BEFORE = '2002-01-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        DataArchive::forgetTableExists();
        System::data_archive_driver(ArchiveStore::DRIVER_LOCAL);
        System::data_archive_policies([]);
        System::clearCache();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach (DataArchive::query()->get() as $archive) {
            ArchiveStore::delete(strval($archive->disk), strval($archive->path));
        }
        DataArchiver::$now = null;
        DataArchiver::$afterPut = null;
        System::features([]);
        System::clearCache();
        Features::reset();
        parent::tearDown();
    }

    /**
     * 3 records of 10 changes each, in 2001.
     */
    protected function revisions(): void
    {
        $rows = [];
        for ($record = 1; $record <= 3; $record++) {
            for ($no = 1; $no <= 10; $no++) {
                $rows[] = [
                    'suuid' => short_uuid(), 'revisionable_type' => static::TYPE, 'revisionable_id' => $record, 'revision_no' => $no,
                    'key' => 'value', 'old_value' => json_encode(['n' => $no - 1]), 'new_value' => json_encode(['n' => $no, 'text' => "改 '{$record}' \"{$no}\""]),
                    'create_user_id' => 1, 'created_at' => Carbon::parse('2001-03-01')->addDays($record * 10 + $no), 'updated_at' => Carbon::parse('2001-03-01'),
                ];
            }
        }
        DB::table('revisions')->insert($rows);
    }

    protected function ours(): \Illuminate\Database\Query\Builder
    {
        return DB::table('revisions')->where('revisionable_type', static::TYPE);
    }

    public function testArchiveThenRestoreTheRevisions(): void
    {
        $this->revisions();
        $before = Carbon::parse(static::BEFORE);
        $original = $this->ours()->orderBy('id')->get()->map(function ($row) {
            return (array)$row;
        })->all();

        // the newest change of each record stays
        $preview = DataArchiver::preview('revisions', $before, 1);
        $this->assertSame(27, $preview['count']);
        $this->assertSame(['2001-03' => 18, '2001-04' => 9], $preview['months']);
        $this->assertGreaterThan(0, $preview['bytes']);

        $archive = DataArchiver::archive('revisions', $before, 27, 1);
        $this->assertSame(DataArchive::STATUS_COMPLETED, $archive->status);
        $this->assertSame(27, $archive->row_count);
        $this->assertSame(27, $archive->deleted_count);
        $this->assertFalse($archive->warning);
        $this->assertSame(3, $this->ours()->count());
        $this->assertSame(10, intval($this->ours()->min('revision_no')));
        $file = storage_path('app/' . $archive->path);
        $this->assertFileExists($file);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3));
        }
        $this->assertSame(['preview', 'archive', 'verify', 'delete_rows'], DataArchiveEvent::query()->where('source', 'revisions')->orderBy('id')->pluck('action')->all());

        // the restore: what wrote it, nothing in the way
        $restore = DataArchiver::restorePreview($archive);
        $this->assertSame(27, $restore['count']);
        $this->assertSame(27, $restore['insert']);
        $this->assertSame(0, $restore['existing']);
        $this->assertSame([], $restore['missing_columns']);
        $this->assertSame(DataArchiver::restore($archive, 1), 27);
        $back = $this->ours()->orderBy('id')->get()->map(function ($row) {
            return (array)$row;
        })->all();
        $this->assertEquals($original, $back);

        // a second restore puts back nothing, overwrites nothing
        $again = DataArchiver::restorePreview($archive->refresh());
        $this->assertSame(27, $again['existing']);
        $this->assertSame(0, DataArchiver::restore($archive, 1));
        $this->assertSame(30, $this->ours()->count());
        $this->assertFileExists($file);
    }

    public function testTheCountChangedSinceThePreview(): void
    {
        $this->revisions();
        try {
            DataArchiver::archive('revisions', Carbon::parse(static::BEFORE), 10, 1);
            $this->fail('archived');
        } catch (ArchiveException $e) {
            $this->assertSame('changed', $e->reason());
        }
        $this->assertSame(30, $this->ours()->count());
        $this->assertSame(0, DataArchive::query()->count());
    }

    public function testABrokenFileInItsPlaceDeletesNothing(): void
    {
        $this->revisions();
        // the file in its place is not the one written (the copy next to the server is fine)
        DataArchiver::$afterPut = function (DataArchive $archive) {
            file_put_contents(storage_path('app/' . $archive->path), 'not a zip');
        };
        try {
            DataArchiver::archive('revisions', Carbon::parse(static::BEFORE), 27, 1);
            $this->fail('archived');
        } catch (ArchiveException $e) {
            $this->assertSame('broken', $e->reason());
        }
        $this->assertSame(30, $this->ours()->count());
        $archive = DataArchive::query()->firstOrFail();
        $this->assertSame(DataArchive::STATUS_FAILED, $archive->status);
        $this->assertSame(0, $archive->deleted_count);
        $this->assertFileDoesNotExist(storage_path('app/' . $archive->path));
        $this->assertSame(1, DataArchiveEvent::query()->where('action', 'failed')->count());
        $this->assertSame(0, DataArchiveEvent::query()->where('action', 'delete_rows')->count());
    }

    public function testARestoreIsRefusedWhenAColumnIsGone(): void
    {
        $this->revisions();
        $archive = DataArchiver::archive('revisions', Carbon::parse(static::BEFORE), 27, 1);
        // an archive written when the table had another column
        $file = storage_path('app/' . $archive->path);
        $zip = new \ZipArchive();
        $zip->open($file);
        $manifest = json_decode(strval($zip->getFromName('manifest.json')), true);
        $manifest['columns'][] = 'a_gone_column';
        $manifest['exment_version'] = 'v6.9.9';
        $zip->addFromString('manifest.json', strval(json_encode($manifest)));
        $zip->close();
        $archive->update(['sha256' => hash_file('sha256', $file)]);

        $preview = DataArchiver::restorePreview($archive);
        $this->assertSame('v6.9.9', $preview['version']);
        $this->assertSame(['a_gone_column'], $preview['missing_columns']);
        try {
            DataArchiver::restore($archive, 1);
            $this->fail('restored');
        } catch (ArchiveException $e) {
            $this->assertSame('columns', $e->reason());
            $this->assertStringContainsString('a_gone_column', $e->text());
        }
        $this->assertSame(3, $this->ours()->count());
    }

    public function testTheTrashTheHoldAndThePurge(): void
    {
        $this->revisions();
        DataArchiver::savePolicies(['revisions' => ['trash_days' => 30]]);
        System::clearCache();
        $archive = DataArchiver::archive('revisions', Carbon::parse(static::BEFORE), 27, 1);
        $file = storage_path('app/' . $archive->path);

        // held: not to the trash
        DataArchiver::hold($archive, true, 1);
        try {
            DataArchiver::trash($archive, 1);
            $this->fail('trashed');
        } catch (ArchiveException $e) {
            $this->assertSame('held', $e->reason());
        }
        DataArchiver::hold($archive, false, 1);

        $now = Carbon::parse('2030-01-10 04:00');
        DataArchiver::$now = $now->copy();
        DataArchiver::trash($archive, 1);
        $this->assertSame('2030-02-09', optional($archive->refresh()->purge_at)->format('Y-m-d'));

        // 25 days on: told once, nothing purged
        $bells = NotifyNavbar::withoutGlobalScopes()->count();
        DataArchiver::$now = $now->copy()->addDays(25);
        $this->assertSame(['notified' => 1, 'purged' => 0], DataArchiver::purge());
        $this->assertGreaterThan($bells, NotifyNavbar::withoutGlobalScopes()->count());
        $this->assertSame(['notified' => 0, 'purged' => 0], DataArchiver::purge());
        $this->assertFileExists($file);

        // held in the trash: never purged
        DataArchiver::hold($archive, true, 1);
        DataArchiver::$now = $now->copy()->addDays(40);
        $this->assertSame(0, DataArchiver::purge()['purged']);
        $this->assertFileExists($file);

        // released: purged on its day
        DataArchiver::hold($archive, false, 1);
        $this->assertSame(1, DataArchiver::purge()['purged']);
        $this->assertFileDoesNotExist($file);
        $this->assertNull(DataArchive::find($archive->id));
        $actions = DataArchiveEvent::query()->where('data_archive_id', $archive->id)->pluck('action')->all();
        foreach (['hold', 'unhold', 'trash', 'purge_notice', 'purge'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function testThePageAndWhoUsesIt(): void
    {
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->revisions();

        // off: not there
        System::features([]);
        System::clearCache();
        System::clearRequestSession();
        Features::reset();
        $this->get(admin_url('data_archive'), $ajax)->assertStatus(404);

        System::features([DataArchiver::FEATURE => ['enabled' => true]]);
        System::clearCache();
        System::clearRequestSession();
        Features::reset();

        // a user without the permission
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        System::clearRequestSession();
        $this->get(admin_url('data_archive'), $ajax)->assertStatus(403);
        $this->post(admin_urls('data_archive', 'run'), ['source' => 'revisions', 'before' => static::BEFORE, 'expected' => 27], $ajax)->assertStatus(403);
        $this->assertSame(30, $this->ours()->count());

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
        $this->get(admin_url('data_archive'), $ajax)->assertOk()
            ->assertSee(exmtrans('data_archive.sources.revisions'))->assertSee('data-source="revisions"', false);
        $this->get(admin_url('data_archive') . '?tab=sources&preview=revisions&before=' . static::BEFORE, $ajax)->assertOk()
            ->assertSee('id="data-archive-run"', false)->assertSee('name="expected" value="27"', false);
        $this->post(admin_urls('data_archive', 'run'), ['source' => 'revisions', 'before' => static::BEFORE, 'expected' => 27], $ajax)->assertRedirect();
        $this->assertSame(3, $this->ours()->count());
        $archive = DataArchive::query()->firstOrFail();
        $this->get(admin_url('data_archive') . '?tab=archives&restore=' . $archive->id, $ajax)->assertOk()
            ->assertSee('id="data-archive-restore"', false);
        $this->post(admin_urls('data_archive', $archive->id, 'restore'), [], $ajax)->assertRedirect();
        $this->assertSame(30, $this->ours()->count());
        $this->post(admin_urls('data_archive', $archive->id, 'action'), ['do' => 'trash'], $ajax)->assertRedirect();
        $this->get(admin_url('data_archive') . '?tab=trash', $ajax)->assertOk()->assertSee('data-archive="' . $archive->id . '"', false);
        $this->get(admin_url('data_archive') . '?tab=events', $ajax)->assertOk()->assertSee('data-event="trash"', false);
    }
}
