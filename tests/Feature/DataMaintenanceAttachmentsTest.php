<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DocumentVersion;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataMaintenance\AttachmentCleanup;
use Exceedone\Exment\Services\DocumentVersionService;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Data maintenance: delete the attachments of a table at once (#1829).
 */
class DataMaintenanceAttachmentsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = 'all_columns_table';

    /** @var string[] */
    protected array $storedPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        Notification::fake();
        Features::reset();
    }

    protected function tearDown(): void
    {
        foreach (ExmentFile::where('filename', 'like', 'dm-%')->get() as $file) {
            $this->storedPaths[] = $file->path;
        }
        foreach ($this->storedPaths as $path) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }
        System::clearRequestSession();
        Features::reset();
        parent::tearDown();
    }

    public function testDeletesAttachmentsWithTheirVersionsAndKeepsTheRecords(): void
    {
        [$record, $document] = $this->attach('dm-doc-1.txt', 'one');
        $first = $this->fileOf($document);
        DocumentVersionService::replace($record, $document, UploadedFile::fake()->createWithContent('dm-doc-2.txt', 'two'));
        $document = $this->reload($document);
        $current = $this->fileOf($document);
        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());

        $cleanup = new AttachmentCleanup($this->table(), [AttachmentCleanup::KIND_DOCUMENT]);
        $summary = $cleanup->summary();
        $this->assertSame(1, $summary['documents']);
        // the older version goes with its attachment: counted (and its size), deleted once
        $this->assertSame(1, $summary['versions']);
        $this->assertSame(2, $summary['files']);
        $this->assertSame(1, $summary['records']);
        $this->assertGreaterThanOrEqual(strlen('one') + strlen('two'), $summary['bytes']);

        $result = $cleanup->execute(1);
        $this->assertSame(1, $result['documents']);
        $this->assertSame(1, $result['versions']);

        $this->assertNull(ExmentFile::getData($first->uuid));
        $this->assertNull(ExmentFile::getData($current->uuid));
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists($current->path));
        $this->assertSame(0, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, $this->documentCount($record));
        // the record stays
        $this->assertNotNull(getModelName(static::TABLE)::find($record->id));
    }

    public function testBeforeKeepsNewerAttachmentsButDeletesTheirOlderVersions(): void
    {
        [$record, $document] = $this->attach('dm-old-1.txt', 'one');
        $first = $this->fileOf($document);
        DocumentVersionService::replace($record, $document, UploadedFile::fake()->createWithContent('dm-old-2.txt', 'two'));
        $document = $this->reload($document);
        $current = $this->fileOf($document);
        // the first file was added long ago, the current one today
        ExmentFile::where('uuid', $first->uuid)->update(['created_at' => Carbon::now()->subYears(2)]);

        $cleanup = new AttachmentCleanup($this->table(), [AttachmentCleanup::KIND_DOCUMENT], Carbon::now()->subYear());
        $summary = $cleanup->summary();
        $this->assertSame(0, $summary['documents']);
        $this->assertSame(1, $summary['versions']);

        $cleanup->execute(1);
        $this->assertNull(ExmentFile::getData($first->uuid));
        $this->assertNotNull(ExmentFile::getData($current->uuid));
        $this->assertSame(1, $this->documentCount($record));
        $this->assertSame(0, DocumentVersion::where('document_id', $document->id)->count());
    }

    public function testDeletesColumnFilesAndEmptiesTheValueQuietly(): void
    {
        $table = $this->table();
        $column = CustomColumn::getEloquent('file', $table);
        $record = $table->getValueModel();
        $record->setValue(['text' => 'dm column'])->save();
        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_COLUMN, 'body', static::TABLE, 'dm-col.txt')
            ->saveCustomValue($record->id, $column, $table);
        $record->setValue('file', $file->path)->save();
        $updated_at = strval(getModelName(static::TABLE)::find($record->id)->updated_at);

        // documents only: the column file stays
        (new AttachmentCleanup($table, [AttachmentCleanup::KIND_DOCUMENT]))->execute(1);
        $this->assertNotNull(ExmentFile::getData($file->uuid));

        $summary = (new AttachmentCleanup($table, [AttachmentCleanup::KIND_COLUMN]))->summary();
        $this->assertSame(1, $summary['column_files']);
        (new AttachmentCleanup($table, [AttachmentCleanup::KIND_COLUMN]))->execute(1);

        $this->assertNull(ExmentFile::getData($file->uuid));
        System::clearRequestSession();
        $fresh = getModelName(static::TABLE)::find($record->id);
        $this->assertNull(array_get($fresh->value, 'file'));
        $this->assertSame('dm column', array_get($fresh->value, 'text'));
        $this->assertSame($updated_at, strval($fresh->updated_at), 'the last update stays');
    }

    public function testAMissingFileIsShownAsDeleted(): void
    {
        $table = $this->table();
        $column = CustomColumn::getEloquent('file', $table);
        $record = $table->getValueModel();
        $record->setValue(['text' => 'dm gone', 'file' => static::TABLE . '/dm-gone-not-here.txt']);
        $html = strval($column->column_item->setCustomValue($record)->html());
        $this->assertStringContainsString(exmtrans('common.file_deleted'), $html);
    }

    public function testThePageIsNotFoundWhileTheFeatureIsOff(): void
    {
        $this->get(admin_urls('data_maintenance', static::TABLE))->assertNotFound();
    }

    public function testThePageCountsAndDeletesWithTheTableName(): void
    {
        Features::save([Features::DATA_MAINTENANCE => ['enabled' => true]]);
        [$record, $document] = $this->attach('dm-page.txt', 'page');
        $file = $this->fileOf($document);

        System::clearRequestSession();
        $this->get(admin_urls_query('data_maintenance', static::TABLE, ['check' => 1, 'kinds' => ['document']]))
            ->assertOk()
            ->assertSee(exmtrans('data_maintenance.attachments.result'));

        // a wrong table name deletes nothing
        System::clearRequestSession();
        $this->post(admin_urls('data_maintenance', static::TABLE, 'attachments'), [
            'kinds' => ['document'],
            'confirm_table_name' => 'wrong',
        ])->assertRedirect();
        $this->assertNotNull(ExmentFile::getData($file->uuid));

        System::clearRequestSession();
        $this->post(admin_urls('data_maintenance', static::TABLE, 'attachments'), [
            'kinds' => ['document'],
            'confirm_table_name' => static::TABLE,
        ])->assertRedirect(admin_urls('data_maintenance', static::TABLE));
        $this->assertNull(ExmentFile::getData($file->uuid));
        $this->assertSame(0, $this->documentCount($record));
    }

    public function testAUserWhoMayNotChangeTheTableCannotUseIt(): void
    {
        Features::save([Features::DATA_MAINTENANCE => ['enabled' => true]]);
        [, $document] = $this->attach('dm-deny.txt', 'kept');
        $file = $this->fileOf($document);

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        // sent as ajax: for a page request, the error of the permission (Permission::error())
        // sends the page and ends the process with exit, which ends PHPUnit too
        $this->post(admin_urls('data_maintenance', static::TABLE, 'attachments'), [
            'kinds' => ['document'],
            'confirm_table_name' => static::TABLE,
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        System::clearRequestSession();
        $this->assertNotNull(ExmentFile::getData($file->uuid));
    }

    // ---------------------------------------------------------------- helpers

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $this->assertInstanceOf(CustomTable::class, $table);
        return $table;
    }

    /**
     * @return array{0: CustomValue, 1: CustomValue}
     */
    protected function attach(string $name, string $body): array
    {
        $record = $this->table()->getValueModel();
        $record->setValue(['text' => 'dm ' . $name])->save();

        System::clearRequestSession();
        $this->post(admin_urls('data', static::TABLE, $record->id, 'fileupload'), [
            'file_data' => [UploadedFile::fake()->createWithContent($name, $body)],
        ])->assertOk()->assertJson(['result' => true]);

        $document = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel()->query()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $record->id)
            ->first();
        $this->assertInstanceOf(CustomValue::class, $document);
        return [$record, $document];
    }

    protected function reload(CustomValue $document): CustomValue
    {
        System::clearRequestSession();
        $fresh = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel($document->id);
        $this->assertInstanceOf(CustomValue::class, $fresh);
        return $fresh;
    }

    protected function fileOf(CustomValue $document): ExmentFile
    {
        $file = ExmentFile::getData(strval($document->getValue('file_uuid', true)));
        $this->assertInstanceOf(ExmentFile::class, $file);
        return $file;
    }

    protected function documentCount(CustomValue $record): int
    {
        return CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel()->query()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $record->id)
            ->count();
    }
}
