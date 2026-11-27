<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Exceedone\Exment\Services\BackupRestore\Restore;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Before a restore (ISS02943): what it replaces (read from the backup without extracting it), and
 * the backup of the data now, made first (the restore does not start when it fails).
 *
 * No restore replaces the database here: the restores below stop at a broken file.
 */
class BackupRestorePreviewTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $backupsBefore = [];

    private string $key = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        Notification::fake();
        config(['exment.backup.save_interval' => 0]);
        $this->backupsBefore = Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list');
        $this->key = 'claudepreview' . substr(md5(uniqid('', true)), 0, 8);
    }

    protected function tearDown(): void
    {
        $created = array_diff(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list'), $this->backupsBefore);
        if (count($created) > 0) {
            Storage::disk(Define::DISKNAME_BACKUP_SYNC)->delete(array_values($created));
        }
        Storage::disk(Define::DISKNAME_ADMIN_TMP)->delete("{$this->key}.zip");
        foreach (BackupRun::query()->get() as $run) {
            /** @var BackupRun $run */
            $path = BackupRuns::logFullPath($run);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        System::clearCache();
        parent::tearDown();
    }

    public function testThePreviewTellsWhatTheRestoreReplaces(): void
    {
        $this->storeBackup([
            'table_definition.sql' => 'CREATE TABLE a (id int);',
            'custom_tables.sql' => 'INSERT INTO a VALUES (1);',
            'exm__abc.tsv' => "id\n1\n",
            'storage/app/plugins/Sample/config.json' => '{}',
            'storage/app/plugins/Sample/Plugin.php' => '<?php',
            '.env' => 'APP_LOCALE=ja',
            Define::BACKUP_VERSION_FILE => strval(json_encode(['exment' => 'v7.0.0', 'laravel' => '11.0.0'])),
        ]);

        $preview = (new Restore())->preview($this->key);
        $this->assertIsArray($preview);
        $this->assertTrue($preview['database']);
        $this->assertSame(2, $preview['tables']);
        $this->assertSame(2, $preview['folders'][BackupTarget::PLUGIN]['files'] ?? null);
        $this->assertTrue($preview['env']);
        $this->assertSame('v7.0.0', $preview['exment']);
        $this->assertSame('11.0.0', $preview['laravel']);

        // shown in the dialog of the restore
        $modal = $this->get(admin_url("backup/importModal/{$this->key}"))->assertOk();
        $body = strval($modal->json('body'));
        $this->assertStringContainsString(e(exmtrans('backup.run.screen.preview_database', ['count' => 2])), $body);
        $this->assertStringContainsString('backup_first', $body);

        // a name that is not of a backup, a file that is not there
        $this->assertNull((new Restore())->preview('../etc'));
        $this->assertNull((new Restore())->preview('claudenotthere'));
    }

    public function testTheDataNowIsBackedUpFirstAndARestoreOfABrokenFileStops(): void
    {
        System::backup_target([BackupTarget::PLUGIN]);
        System::clearCache();
        Storage::disk(Define::DISKNAME_ADMIN_TMP)->put("{$this->key}.zip", 'this is not a zip');

        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_COMMAND, ['file_name' => $this->key]);
        try {
            BackupRuns::runRestore($run, new Restore(), "{$this->key}.zip", true, null, true);
            $this->fail('a broken file was restored');
        } catch (InvalidZipEntryException $e) {
            // refused
        }
        $this->assertFalse(app()->isDownForMaintenance(), 'the site is up again');

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->stageRow('safety')['finished_at'] ?? null, 'the backup of the data now was made first');
        $safety = array_values(array_filter(
            array_diff(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list'), $this->backupsBefore),
            fn ($file) => str_starts_with(basename($file), BackupRuns::SAFETY_PREFIX)
        ));
        $this->assertCount(1, $safety);
        $this->assertStringContainsString('the backup before the restore', strval(BackupRuns::readLog($run)));
    }

    public function testAStageNotRunIsSkippedOnceTheRunEnded(): void
    {
        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_COMMAND);
        $run->status = BackupRun::STATUS_FAILED;
        $run->stages = [['name' => 'verify', 'started_at' => '2026-01-01 00:00:00', 'finished_at' => null]];
        $run->save();

        $states = array_column($run->toStatusArray()['stages'], 'state', 'name');
        $this->assertSame('skipped', $states['safety']);
        $this->assertSame('stopped', $states['verify']);
        $this->assertSame('skipped', $states['load']);
    }

    /**
     * @param array<string, string> $entries
     */
    private function storeBackup(array $entries): void
    {
        $local = (string) tempnam(sys_get_temp_dir(), 'claude_preview');
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($local, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, strval($content));
            }
            $zip->close();
            Storage::disk(Define::DISKNAME_BACKUP_SYNC)->put("list/{$this->key}.zip", strval(file_get_contents($local)));
        } finally {
            @unlink($local);
        }
    }
}
