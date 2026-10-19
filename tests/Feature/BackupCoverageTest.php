<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;

/**
 * The backup screen (admin/backup/*) and the exment:backup / exment:restore commands.
 *
 * The backups here hold the plugin folder only: the database part runs mysqldump with table
 * locks (MySqlConnection::dumpDatabase()), which would wait on this test's open transaction.
 * A restore is never run: it would replace the shared test database; only its refusals are.
 */
class BackupCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /**
     * Backup files this test created, deleted again in tearDown()
     *
     * @var array<int, string>
     */
    private array $createdBackups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        if (!empty($this->createdBackups)) {
            $this->backupDisk()->delete($this->createdBackups);
        }
        System::clearCache();
        parent::tearDown();
    }

    public function testBackupScreenSavesShowsRenamesDownloadsAndDeletesABackup(): void
    {
        // the save button backs up the targets of the backup settings
        System::backup_target([BackupTarget::PLUGIN]);
        System::clearCache();

        $before = $this->backupFiles();
        $this->post(admin_url('backup/save'))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.save_succeeded')]);
        $file = $this->newBackup($before);
        $key = pathinfo($file, PATHINFO_FILENAME);
        $this->assertZipHoldsThePlugins($file);

        // the restore dialog of a listed backup names its file
        $modal = $this->get(admin_url("backup/importModal/{$key}"))
            ->assertOk()
            ->assertJson(['title' => exmtrans('backup.restore')]);
        $this->assertStringContainsString("{$key}.zip", (string) $modal->json('body'));

        // a restore needs the confirmation keyword: refused before anything is restored
        $this->post(admin_url('backup/import'), ['restore_zipfile' => $key, 'restore_keyword' => 'claude wrong keyword'])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('error.mistake_keyword')]);
        $this->assertFalse(app()->isDownForMaintenance(), 'a restore puts the application down first');

        $newKey = 'claudebackup' . substr(md5(uniqid('', true)), 0, 8);
        $this->post(admin_url('backup/editname'), ['file' => $key, 'filename' => $newKey])
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);
        $this->createdBackups[] = "list/{$newKey}.zip";
        $this->assertTrue($this->backupDisk()->exists("list/{$newKey}.zip"));
        $this->assertFalse($this->backupDisk()->exists($file));

        // downloadFile() ends the current output buffer before it streams (ob_end_clean()):
        // give it one of ours, or it would close PHPUnit's
        $level = ob_get_level();
        ob_start();
        try {
            $download = $this->get(admin_url("backup/download/{$newKey}"));
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $download->assertOk();
        $this->assertSame("attachment; filename*=UTF-8''{$newKey}.zip", $download->headers->get('Content-disposition'));
        $this->assertStringStartsWith('PK', (string) $download->streamedContent());

        $this->delete(admin_url('backup/delete'), ['files' => $newKey])
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertFalse($this->backupDisk()->exists("list/{$newKey}.zip"));
    }

    public function testImportWithoutAFileIsRefused(): void
    {
        $this->post(admin_url('backup/import'), ['restore_keyword' => Define::RESTORE_CONFIRM_KEYWORD])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('backup.message.restore_file_error')]);
        $this->assertFalse(app()->isDownForMaintenance(), 'a restore puts the application down first');
    }

    public function testBackupCommandWritesABackupOfTheTarget(): void
    {
        $before = $this->backupFiles();

        $this->runArtisan('exment:backup', ['--target' => BackupTarget::PLUGIN])
            ->assertExitCode(0)
            ->run();

        $this->assertZipHoldsThePlugins($this->newBackup($before));
    }

    public function testRestoreCommandStopsWhenTheCautionIsNotConfirmed(): void
    {
        $caution = (string) preg_replace('/<br>/u', '', exmtrans('backup.message.restore_caution'));

        $this->runArtisan('exment:restore')
            ->expectsConfirmation($caution, 'no')
            ->assertExitCode(1)
            ->run();
        $this->assertFalse(app()->isDownForMaintenance(), 'a restore puts the application down first');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        // artisan() only answers an int when console output mocking is off
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function backupDisk(): Filesystem
    {
        return Storage::disk(Define::DISKNAME_BACKUP_SYNC);
    }

    /**
     * @return array<int, string>
     */
    private function backupFiles(): array
    {
        return $this->backupDisk()->files('list');
    }

    /**
     * The one backup file that appeared since $before; it is deleted again in tearDown().
     *
     * @param array<int, string> $before
     */
    private function newBackup(array $before): string
    {
        $created = array_values(array_diff($this->backupFiles(), $before));
        $this->createdBackups = array_merge($this->createdBackups, $created);
        $this->assertCount(1, $created, 'one backup file is written');

        return $created[0];
    }

    /**
     * The zip holds the plugin folder (storage/app/plugins), where the test data installed the
     * test plugins.
     */
    private function assertZipHoldsThePlugins(string $file): void
    {
        $local = (string) tempnam(sys_get_temp_dir(), 'claude_backup');
        try {
            file_put_contents($local, (string) $this->backupDisk()->get($file));
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($local) === true, "{$file} is a zip archive");
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
        } finally {
            unlink($local);
        }

        $plugins = array_filter($names, fn (string $name) => str_starts_with($name, 'storage/app/plugins/'));
        $this->assertNotEmpty($plugins, 'the backup holds the plugin folder');
        $this->assertEmpty(array_filter($names, fn (string $name) => str_ends_with($name, '.sql')), 'no database dump was taken');
    }
}
