<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Jobs\ExtractAttachmentTextJob;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentBackfillCommandTest extends TestCase
{
    private string $originalConnection;

    /** @var array<string,mixed> */
    private array $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        $this->originalConfig = [
            'queue.default' => config('queue.default'),
            'queue.connections.meili_backfill_inline' => config('queue.connections.meili_backfill_inline'),
            'queue.connections.meili_backfill_async' => config('queue.connections.meili_backfill_async'),
            'admin.upload.disk' => config('admin.upload.disk'),
            'meilisearch.realtime_sync' => config('meilisearch.realtime_sync'),
        ];

        config(['database.connections.meili_backfill_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('meili_backfill_test');

        Schema::create('files', function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('parent_type');
            $table->integer('parent_id');
            $table->string('file_type');
            $table->string('filename');
            $table->string('local_filename');
            $table->string('local_dirname');
        });
        Schema::create('meili_attachment_texts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('file_uuid')->unique();
            $table->string('file_hash')->nullable();
            $table->string('extractor_version');
            $table->string('status');
            $table->string('parser')->nullable();
            $table->string('extension')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->longText('text')->nullable();
            $table->unsignedInteger('text_length')->default(0);
            $table->boolean('truncated')->default(0);
            $table->string('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
        });

        $table = new CustomTable();
        $table->table_name = 'backfill_notes';
        $table->options = ['search_enabled' => true];
        System::setRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomTable::getTableName()), collect([$table]));

        Storage::fake('meili_backfill_files');
        Storage::disk('meili_backfill_files')->put('documents/example.txt', 'cache me');
        config([
            'admin.upload.disk' => 'meili_backfill_files',
            'queue.default' => 'meili_backfill_inline',
            'queue.connections.meili_backfill_inline' => ['driver' => 'sync'],
            'queue.connections.meili_backfill_async' => ['driver' => 'database'],
            'meilisearch.realtime_sync' => true,
        ]);
        DB::table('files')->insert([
            'uuid' => 'backfill-file',
            'parent_type' => 'backfill_notes',
            'parent_id' => 17,
            'file_type' => FileType::CUSTOM_VALUE_DOCUMENT,
            'filename' => 'example.txt',
            'local_filename' => 'example.txt',
            'local_dirname' => 'documents',
        ]);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomTable::getTableName()));
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('meili_backfill_test');
        config(['database.connections.meili_backfill_test' => null]);
        config($this->originalConfig);
        parent::tearDown();
    }

    public function testSyncDriverBackfillOnlyBuildsAndReusesCache(): void
    {
        $this->assertSame(0, Artisan::call('exment:meili-attachments-backfill', ['--sync' => true]));
        $this->assertSame(1, DB::table('meili_attachment_texts')->count());
        $this->assertStringContainsString('php artisan exment:meili-index', Artisan::output());
        Bus::assertNotDispatched(SyncMeiliDocumentJob::class);
        Bus::assertNotDispatched(ReindexMeiliTableJob::class);

        DB::table('meili_attachment_texts')->where('file_uuid', 'backfill-file')
            ->update(['extracted_at' => '2000-01-01 00:00:00']);
        $this->assertSame(0, Artisan::call('exment:meili-attachments-backfill', ['--sync' => true]));
        $this->assertSame(1, DB::table('meili_attachment_texts')->count());
        $this->assertSame('2000-01-01 00:00:00', DB::table('meili_attachment_texts')->value('extracted_at'));
        Bus::assertNotDispatched(SyncMeiliDocumentJob::class);
    }

    public function testSyncDriverRejectsReindexBeforeExtraction(): void
    {
        $this->assertSame(1, Artisan::call('exment:meili-attachments-backfill', ['--sync' => true, '--reindex' => true]));
        $this->assertStringContainsString('cannot queue table jobs', Artisan::output());
        $this->assertSame(0, DB::table('meili_attachment_texts')->count());
        Bus::assertNotDispatched(ReindexMeiliTableJob::class);
    }

    public function testSyncDriverRejectsQueuedExtraction(): void
    {
        $this->assertSame(1, Artisan::call('exment:meili-attachments-backfill'));
        $this->assertStringContainsString('Queued attachment extraction is unavailable', Artisan::output());
        $this->assertSame(0, DB::table('meili_attachment_texts')->count());
        Bus::assertNotDispatched(ExtractAttachmentTextJob::class);
    }

    public function testAsyncDriverStillDispatchesPerRecordSync(): void
    {
        config(['queue.default' => 'meili_backfill_async']);
        $this->assertSame(0, Artisan::call('exment:meili-attachments-backfill', ['--sync' => true]));
        $this->assertSame(1, DB::table('meili_attachment_texts')->count());
        Bus::assertDispatched(SyncMeiliDocumentJob::class, 1);
        Bus::assertNotDispatched(ReindexMeiliTableJob::class);
    }

    public function testAsyncDriverReindexSkipsPerFileSync(): void
    {
        config(['queue.default' => 'meili_backfill_async']);
        $this->assertSame(0, Artisan::call('exment:meili-attachments-backfill', ['--sync' => true, '--reindex' => true]));
        $this->assertSame(1, DB::table('meili_attachment_texts')->count());
        Bus::assertNotDispatched(SyncMeiliDocumentJob::class);
        Bus::assertDispatched(ReindexMeiliTableJob::class, 1);
    }
}
