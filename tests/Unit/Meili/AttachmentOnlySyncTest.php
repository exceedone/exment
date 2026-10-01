<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\Meili\MeiliSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttachmentOnlySyncTest extends TestCase
{
    public function testAttachmentOnlyRecordIsSyncedWhileItOwnsABusinessFile(): void
    {
        $originalConnection = config('database.default');
        config(['database.connections.meili_attachment_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('meili_attachment_test');
        try {
            Schema::create('files', function (Blueprint $table) {
                $table->string('uuid')->primary();
                $table->string('parent_type');
                $table->integer('parent_id');
                $table->string('file_type');
            });

            $record = $this->record(true);
            DB::table('files')->insert([
                'uuid' => 'avatar', 'parent_type' => 'notes', 'parent_id' => 17, 'file_type' => FileType::AVATAR,
            ]);
            $this->assertFalse(MeiliSync::shouldSync($record, 'upsert'));

            DB::table('files')->insert([
                'uuid' => 'document', 'parent_type' => 'notes', 'parent_id' => 17, 'file_type' => FileType::CUSTOM_VALUE_DOCUMENT,
            ]);
            $this->assertTrue(MeiliSync::shouldSync($record, 'upsert'));

            DB::table('files')->where('uuid', 'document')->delete();
            $this->assertFalse(MeiliSync::shouldSync($record, 'upsert'));
            $this->assertTrue(MeiliSync::shouldSync($record, 'delete'));
            $this->assertFalse(MeiliSync::shouldSync($this->record(false), 'upsert'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge('meili_attachment_test');
        }
    }

    private function record(bool $searchEnabled): CustomValue
    {
        $table = new class($searchEnabled) {
            public string $table_name = 'notes';

            public function __construct(private bool $searchEnabled)
            {
            }

            public function getOption(string $key): bool
            {
                return $key === 'search_enabled' && $this->searchEnabled;
            }

            public function getFreewordSearchColumns()
            {
                return collect();
            }
        };

        return new class($table) extends CustomValue {
            public function __construct(private object $tableForTest)
            {
                $this->id = 17;
            }

            public function getCustomTableAttribute()
            {
                return $this->tableForTest;
            }
        };
    }
}
