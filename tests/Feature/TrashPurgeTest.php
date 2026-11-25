<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DeletedArchive;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TrashPurge;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The deleted data deleted for good after the days of its table, kept in the archive
 * (ISS00147, exment:trash-purge). Everything in the database is rolled back.
 */
class TrashPurgeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PARENT = TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE;
    protected const CHILD = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;
    protected const FOLDER = 'archives/deleted_test_iss147';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        config([
            'exment.delete_force_custom_value' => false,
            'exment.deleted_archive_disk' => null,
            'exment.deleted_archive_folder' => static::FOLDER,
            'exment.deleted_archive_keep_days' => 365,
        ]);
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        try {
            Storage::disk(config('admin.upload.disk'))->deleteDirectory(static::FOLDER);
        } catch (\Throwable $e) {
        }
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function table(string $name): CustomTable
    {
        $table = CustomTable::getEloquent($name);
        $this->assertNotNull($table, "the test data has the table $name");
        return $table;
    }

    protected function keepDays(?int $days): void
    {
        $table = CustomTable::query()->where('table_name', static::PARENT)->firstOrFail();
        $table->setOption(TrashPurge::OPTION, $days);
        $table->save();
        System::clearRequestSession();
    }

    /**
     * A row of the parent table with a file, a document, a comment and two children, deleted
     * (to the trash) the days ago given.
     */
    protected function deletedRow(int $daysAgo): CustomValue
    {
        $parentTable = $this->table(static::PARENT);
        $value = $this->rowWithFile($parentTable);
        Model\File::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, 'document of iss147', $parentTable->table_name, 'doc.txt')
            ->saveCustomValue($value->id, null, $parentTable)
            ->saveDocumentModel($value, 'doc.txt');
        $value->addComment('a comment of iss147');

        $childTable = $this->table(static::CHILD);
        foreach ([1, 2] as $i) {
            $this->rowWithFile($childTable, function ($child) use ($value, $parentTable) {
                $child->parent_id = $value->id;
                $child->parent_type = $parentTable->table_name;
            });
        }

        $value->delete();
        $past = Carbon::now()->subDays($daysAgo);
        DB::table(getDBTableName($parentTable))->where('id', $value->id)->update(['deleted_at' => $past]);
        System::clearRequestSession();
        $value = getModelName($parentTable)::withTrashed()->find($value->id);
        $this->assertTrue($value->trashed());
        return $value;
    }

    protected function rowWithFile(CustomTable $table, ?\Closure $callback = null): CustomValue
    {
        $column = CustomColumn::getEloquent('file', $table);
        $file = Model\File::storeAs(FileType::CUSTOM_VALUE_COLUMN, 'content of iss147', $table->table_name, 'test.txt');
        $value = $table->getValueModel();
        $value->setValue('file', $file->path)->setValue('text', make_uuid());
        if ($callback) {
            $callback($value);
        }
        $value->save();
        $file->saveCustomValue($value->id, $column, $table);
        return $value;
    }

    protected function find(CustomValue $value): ?CustomValue
    {
        System::clearRequestSession();
        return getModelName($value->custom_table)::withTrashed()->find($value->id);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function purge(array $options = []): int
    {
        return Artisan::call('exment:trash-purge', $options);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, DeletedArchive>
     */
    protected function archives(CustomValue $value)
    {
        return DeletedArchive::query()->where('custom_table_name', $value->custom_table->table_name)->where('value_id', $value->id)->get();
    }

    public function testNoDaysPurgesNothing(): void
    {
        $this->keepDays(0);
        $value = $this->deletedRow(400);

        $this->assertSame(0, $this->purge());
        $this->assertNotNull($this->find($value));
        $this->assertCount(0, $this->archives($value));

        $this->keepDays(null);
        $this->assertSame(0, $this->purge());
        $this->assertNotNull($this->find($value));
    }

    public function testAnOldDeletedRowIsArchivedThenDeleted(): void
    {
        $this->keepDays(30);
        $value = $this->deletedRow(31);
        $revisions = \Exceedone\Exment\Revisionable\Revision::where('revisionable_type', static::PARENT)->where('revisionable_id', $value->id)->count();
        $this->assertGreaterThan(0, $revisions);

        $this->assertSame(0, $this->purge());

        $this->assertNull($this->find($value));
        $archives = $this->archives($value);
        $this->assertCount(1, $archives);
        $archive = $archives->first();

        $this->assertSame($value->id, $archive->value['id']);
        $this->assertSame($value->getValue('text'), $archive->value['value']['text']);
        $this->assertNotNull($archive->value['deleted_at']);
        $this->assertNotNull($archive->deleted_at);
        $this->assertNotNull($archive->archived_at);
        $this->assertSame(Carbon::now()->addDays(365)->toDateString(), $archive->purge_after->toDateString());

        $this->assertCount(2, $archive->children);
        $this->assertSame(static::CHILD, $archive->children[0]['table_name']);
        $this->assertCount(1, $archive->comments);
        $this->assertSame('a comment of iss147', $archive->comments[0]['value']['comment_detail']);
        $this->assertCount($revisions, $archive->revisions);

        // the files of the row (column and document) and of the children, copied
        $this->assertGreaterThanOrEqual(4, count($archive->files));
        $documents = array_filter($archive->files, function ($file) {
            return !is_null($file['document']);
        });
        $this->assertCount(1, $documents);
        foreach ($archive->files as $file) {
            $this->assertFalse($file['missing']);
            $this->assertStringStartsWith(static::FOLDER . '/' . static::PARENT . '/', $file['archive_path']);
            $this->assertTrue(Storage::disk($file['archive_disk'])->exists($file['archive_path']));
        }
        $columnFile = null;
        foreach ($archive->files as $file) {
            if (is_null($file['document']) && $file['parent_type'] === static::PARENT) {
                $columnFile = $file;
                break;
            }
        }
        $this->assertNotNull($columnFile);
        $this->assertSame('content of iss147', Storage::disk($columnFile['archive_disk'])->get($columnFile['archive_path']));

        // the children went with it
        foreach ($archive->children as $child) {
            System::clearRequestSession();
            $this->assertNull(getModelName(static::CHILD)::withTrashed()->find($child['id']));
        }
    }

    public function testARecentDeletedRowStays(): void
    {
        $this->keepDays(30);
        $value = $this->deletedRow(10);

        $this->assertSame(0, $this->purge());
        $this->assertNotNull($this->find($value));
        $this->assertCount(0, $this->archives($value));
    }

    public function testDryRunChangesNothing(): void
    {
        $this->keepDays(30);
        $value = $this->deletedRow(31);

        $this->assertSame(0, $this->purge(['--dry-run' => true]));
        $this->assertStringContainsString('would purge 1, would skip 0', Artisan::output());
        $this->assertNotNull($this->find($value));
        $this->assertCount(0, $this->archives($value));
    }

    public function testDryRunUsesTheSameSkipRules(): void
    {
        $this->keepDays(30);
        $purged = $this->deletedRow(31);
        $kept = $this->deletedRow(31);
        // a child of the second row is not deleted: a real run keeps that row
        $childTable = $this->table(static::CHILD);
        $childId = DB::table(getDBTableName($childTable))->where('parent_id', $kept->id)->orderBy('id')->value('id');
        DB::table(getDBTableName($childTable))->where('id', $childId)->update(['deleted_at' => null]);
        System::clearRequestSession();

        $this->assertSame(0, $this->purge(['--dry-run' => true]));
        $this->assertStringContainsString('would purge 1, would skip 1 (children not deleted: 1)', Artisan::output());
        $this->assertNotNull($this->find($purged));

        $this->assertSame(0, $this->purge());
        $this->assertStringContainsString('purged 1, skipped 1', Artisan::output());
        $this->assertNull($this->find($purged));
        $this->assertNotNull($this->find($kept));
    }

    public function testTheTableOption(): void
    {
        $this->keepDays(30);
        $value = $this->deletedRow(31);

        // another table only: nothing here
        $this->assertSame(0, $this->purge(['--table' => static::CHILD]));
        $this->assertNotNull($this->find($value));

        $this->assertSame(0, $this->purge(['--table' => static::PARENT]));
        $this->assertNull($this->find($value));
    }

    public function testAnExpiredArchiveIsRemoved(): void
    {
        $disk = Storage::disk(config('admin.upload.disk'));
        $make = function (?Carbon $purgeAfter, string $path) use ($disk) {
            $disk->put($path, 'archived');
            return DeletedArchive::create([
                'custom_table_id' => null,
                'custom_table_name' => static::PARENT,
                'value_id' => 999999,
                'value' => ['id' => 999999],
                'files' => [['uuid' => 'x', 'archive_disk' => config('admin.upload.disk'), 'archive_path' => $path]],
                'archived_at' => Carbon::now()->subYears(2),
                'purge_after' => $purgeAfter,
            ]);
        };
        $expired = $make(Carbon::now()->subDay(), static::FOLDER . '/old/a.txt');
        $future = $make(Carbon::now()->addDays(10), static::FOLDER . '/new/b.txt');
        $forever = $make(null, static::FOLDER . '/forever/c.txt');

        $this->assertSame(0, $this->purge(['--dry-run' => true]));
        $this->assertNotNull(DeletedArchive::find($expired->id));

        $this->assertSame(0, $this->purge());
        $this->assertNull(DeletedArchive::find($expired->id));
        $this->assertFalse($disk->exists(static::FOLDER . '/old/a.txt'));
        $this->assertNotNull(DeletedArchive::find($future->id));
        $this->assertTrue($disk->exists(static::FOLDER . '/new/b.txt'));
        $this->assertNotNull(DeletedArchive::find($forever->id));
    }

    public function testAFileNotCopiedKeepsTheRow(): void
    {
        // a disk that cannot be written: its folder is under a file
        $blocker = tempnam(sys_get_temp_dir(), 'iss147');
        config([
            'filesystems.disks.trash_purge_broken_iss147' => ['driver' => 'local', 'root' => $blocker . '/archive', 'throw' => true],
            'exment.deleted_archive_disk' => 'trash_purge_broken_iss147',
        ]);
        try {
            $this->keepDays(30);
            $value = $this->deletedRow(31);

            $this->assertSame(0, $this->purge());
            $this->assertNotNull($this->find($value));
            $this->assertCount(0, $this->archives($value));
            $this->assertStringContainsString('skipped 1', Artisan::output());
        } finally {
            @unlink($blocker);
        }
    }

    public function testTheSettingIsNotForTheUsers(): void
    {
        $this->assertFalse(TrashPurge::purgeable($this->table(\Exceedone\Exment\Enums\SystemTableName::USER)));
        $this->assertTrue(TrashPurge::purgeable($this->table(static::PARENT)));
    }
}
