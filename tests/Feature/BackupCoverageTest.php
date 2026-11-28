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

    /** the folder of the fake mysqldump (fakeMysqlBin()) */
    private ?string $fakeMysqlDir = null;

    /** @var array<int, string> the folders of restoreFolder() */
    private array $restoreFolders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach ($this->restoreFolders as $dir) {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->restoreFolders = [];
        if (!is_null($this->fakeMysqlDir)) {
            foreach (['mysqldump', 'mysql'] as $name) {
                @unlink("{$this->fakeMysqlDir}/{$name}");
            }
            @rmdir($this->fakeMysqlDir);
            $this->fakeMysqlDir = null;
        }
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

    public function testBackupCommandFailsWhenTheDatabaseDumpFails(): void
    {
        // a mysqldump that cannot connect (as with a self-signed certificate of the server)
        $this->fakeMysqlBin("echo 'mysqldump: Got error: 2026: TLS/SSL error: self-signed certificate in certificate chain' >&2\nexit 2");
        $before = $this->backupFiles();

        $this->runArtisan('exment:backup', ['--target' => BackupTarget::DATABASE])
            ->expectsOutputToContain(strval(exmtrans('backup.message.backup_error')))
            // the hint about TLS
            ->expectsOutputToContain(strval(exmtrans('backup.message.tls_hint')))
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, $this->backupFiles(), 'no backup file is written');
    }

    public function testBackupCommandFailsWhenTheDumpWritesNothing(): void
    {
        // a mysqldump that ends with 0 but writes no data
        $this->fakeMysqlBin('exit 0');
        $before = $this->backupFiles();

        $this->runArtisan('exment:backup', ['--target' => BackupTarget::DATABASE])
            ->expectsOutputToContain(strval(exmtrans('backup.message.dump_empty', ['file' => config('exment.backup_info.def_file', 'table_definition.sql')])))
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, $this->backupFiles(), 'no backup file is written');
    }

    public function testTheBackupScreenShowsAnErrorWhenTheDumpFails(): void
    {
        // the reason holds characters of HTML: toastr shows the message as HTML
        $reason = 'mysqldump: Got error: 2026: TLS/SSL error <b>self-signed</b> & more';
        $this->fakeMysqlBin("echo '{$reason}' >&2\nexit 2");
        System::backup_target([BackupTarget::DATABASE]);
        System::clearCache();
        $before = $this->backupFiles();

        $response = $this->post(admin_url('backup/save'))
            ->assertOk()
            ->assertJson(['result' => false]);
        $message = (string) $response->json('message');
        $this->assertStringStartsWith(esc_html(exmtrans('backup.message.backup_error')), $message);
        $this->assertStringContainsString(esc_html($reason), $message);
        $this->assertStringNotContainsString('<b>', $message);

        // the run is recorded as failed, not as a success
        $run = \Exceedone\Exment\Model\BackupRun::where('uuid', $response->json('run'))->first();
        $this->assertNotNull($run);
        $this->assertSame(\Exceedone\Exment\Model\BackupRun::STATUS_FAILED, $run->status);
        $this->assertSame($before, $this->backupFiles(), 'no backup file is written');
    }

    public function testTheErrorOfTheDumpHasNoPassword(): void
    {
        $detail = \Exceedone\Exment\Database\MySqlConnection::dumpErrorDetail(
            "mysqldump: [Warning] Using a password on the command line interface can be insecure.\nmysqldump: Got error: 1045: Access denied (password s3cret-pw)\n",
            's3cret-pw'
        );
        $this->assertSame('mysqldump: Got error: 1045: Access denied (password ***)', $detail);
    }

    public function testTotalUpdateStopsWhenTheBackupFails(): void
    {
        $this->fakeMysqlBin("echo 'mysqldump: Got error: 2026: TLS/SSL error' >&2\nexit 2");

        // the update is not run after a failed backup; the site is up again
        $this->runArtisan('exment:total-update', ['--publish' => 0])
            ->expectsOutputToContain('TLS/SSL error')
            ->assertExitCode(1)
            ->run();
        $this->assertFalse(app()->isDownForMaintenance(), 'the maintenance mode is ended');
    }

    public function testAShortPasswordIsMaskedOnlyInItsArgument(): void
    {
        // a password of a few letters is not replaced in the words: only the argument is masked
        $detail = \Exceedone\Exment\Database\MySqlConnection::dumpErrorDetail(
            "mysqldump: Got error: 2026: TLS/SSL error in mysqldump --password='ab' --host=db\n",
            'ab'
        );
        $this->assertSame('mysqldump: Got error: 2026: TLS/SSL error in mysqldump --password=*** --host=db', $detail);
    }

    public function testTheOptionsOfEnvGoToMysqldump(): void
    {
        // the fake mysqldump tells its arguments, then fails
        $this->fakeMysqlBin("echo \"args: \$*\" >&2\nexit 2");
        config(['exment.backup_info.dump_options' => '--ssl-mode=DISABLED  --skip-ssl-verify-server-cert']);

        $this->runArtisan('exment:backup', ['--target' => BackupTarget::DATABASE])
            ->expectsOutputToContain('--ssl-mode=DISABLED --skip-ssl-verify-server-cert')
            ->assertExitCode(1)
            ->run();
    }

    public function testTheOptionsOfEnvAreEscaped(): void
    {
        config(['exment.backup_info.restore_options' => "--ssl-mode=DISABLED ;rm\t--x=\$(id)"]);
        $this->assertSame(
            implode(' ', [escapeshellarg('--ssl-mode=DISABLED'), escapeshellarg(';rm'), escapeshellarg('--x=$(id)')]),
            \Exceedone\Exment\Database\MySqlConnection::clientOptions('restore_options')
        );
        config(['exment.backup_info.restore_options' => null]);
        $this->assertSame('', \Exceedone\Exment\Database\MySqlConnection::clientOptions('restore_options'));
    }

    public function testTheTlsErrorsAreKnown(): void
    {
        $connection = \Exceedone\Exment\Database\MySqlConnection::class;
        $this->assertTrue($connection::isTlsError('ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain'));
        $this->assertTrue($connection::isTlsError('mysqldump: Got error: 2026: TLS/SSL error: SSL is required, but the server does not support it'));
        $this->assertFalse($connection::isTlsError('mysqldump: Got error: 1045: Access denied for user'));
    }

    public function testARestoreWithAnEmptyFileChangesNothing(): void
    {
        $this->fakeMysqlBin('exit 0');
        $dir = $this->restoreFolder(['table_definition.sql' => '', 'custom_tables.sql' => 'INSERT INTO x VALUES (1);']);

        $e = $this->restoreFailure(function () use ($dir) {
            \ExmentDB::checkRestore($dir);
        });
        $this->assertFalse($e->databaseChanged());
        $this->assertSame(strval(exmtrans('backup.message.restore_nothing_restored', [
            'reason' => exmtrans('backup.message.restore_empty_file', ['file' => 'table_definition.sql']),
        ])), $e->getMessage());
    }

    public function testARestoreWhoseClientCannotConnectChangesNothing(): void
    {
        $this->fakeMysqlBin('exit 0', "echo 'ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain' >&2\nexit 1");
        $dir = $this->restoreFolder(['table_definition.sql' => '-- definition', 'custom_tables.sql' => 'INSERT INTO x VALUES (1);']);
        $count = \DB::table('custom_tables')->count();

        $e = $this->restoreFailure(function () use ($dir) {
            \ExmentDB::restoreDatabase($dir);
        });
        $this->assertFalse($e->databaseChanged());
        $this->assertStringContainsString(strval(exmtrans('backup.message.restore_nothing_restored', ['reason' => ''])), $e->getMessage());
        $this->assertStringContainsString('self-signed certificate', $e->getMessage());
        $this->assertStringContainsString(strval(exmtrans('backup.message.tls_hint')), $e->getMessage());
        // nothing was truncated
        $this->assertSame($count, \DB::table('custom_tables')->count());
    }

    public function testARestoreWhoseImportFailsSaysItStoppedMidway(): void
    {
        // SELECT 1 is answered; the import of a file fails
        $this->fakeMysqlBin('exit 0', "for a in \"\$@\"; do [ \"\$a\" = '-e' ] && exit 0; done\necho 'ERROR 1064 (42000): syntax error' >&2\nexit 1");
        $dir = $this->restoreFolder(['table_definition.sql' => '-- definition']);

        $e = $this->restoreFailure(function () use ($dir) {
            \ExmentDB::restoreDatabase($dir);
        });
        $this->assertTrue($e->databaseChanged());
        $this->assertSame(strval(exmtrans('backup.message.restore_stopped_midway', [
            'reason' => exmtrans('backup.message.restore_import_failed', ['file' => 'table_definition.sql', 'code' => 1, 'detail' => 'ERROR 1064 (42000): syntax error']),
        ])), $e->getMessage());
    }

    public function testRestoreCommandSaysNothingWasRestored(): void
    {
        $this->fakeMysqlBin('exit 0', "echo 'ERROR 2026 (HY000): SSL is required, but the server does not support it' >&2\nexit 1");

        // a backup of the plugin folder, and a definition in it (zip made here)
        $name = 'claudenothing' . substr(md5(uniqid('', true)), 0, 8);
        $zipPath = tempnam(sys_get_temp_dir(), 'exment-zip-');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('table_definition.sql', '-- definition');
        $zip->close();
        $this->backupDisk()->put("list/{$name}.zip", (string) file_get_contents($zipPath));
        @unlink($zipPath);
        $this->createdBackups[] = "list/{$name}.zip";
        $count = \DB::table('custom_tables')->count();

        $this->runArtisan('exment:restore', ['file' => $name, '--yes' => true])
            ->expectsOutputToContain(strval(exmtrans('backup.message.restore_nothing_restored', ['reason' => ''])))
            ->assertExitCode(1)
            ->run();

        $this->assertSame($count, \DB::table('custom_tables')->count());
        $this->assertFalse(app()->isDownForMaintenance(), 'the site is up again');
    }

    /**
     * The failure of a restore that $call makes (the test fails when it does not fail).
     */
    private function restoreFailure(callable $call): \Exceedone\Exment\Exceptions\RestoreFailedException
    {
        try {
            $call();
        } catch (\Throwable $e) {
            if ($e instanceof \Exceedone\Exment\Exceptions\RestoreFailedException) {
                return $e;
            }
            throw $e;
        }
        $this->fail('the restore did not stop');
    }

    /**
     * A folder of an unzipped backup with the files given; removed again in tearDown().
     *
     * @param array<string, string> $files name => contents
     */
    private function restoreFolder(array $files): string
    {
        $dir = sys_get_temp_dir() . '/exment-restore-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }
        $this->restoreFolders[] = $dir;
        return $dir;
    }

    public function testRestoreCommandSaysWhenTheFileIsNotThere(): void
    {
        $before = \Exceedone\Exment\Model\BackupRun::query()->count();
        $this->runArtisan('exment:restore', ['file' => '20991231000000', '--yes' => true])
            ->expectsOutputToContain(strval(exmtrans('backup.message.restore_file_not_found', ['name' => '20991231000000'])))
            ->assertExitCode(1)
            ->run();
        $this->assertFalse(app()->isDownForMaintenance(), 'nothing was started');
        $this->assertSame($before, \Exceedone\Exment\Model\BackupRun::query()->count(), 'no run is recorded');
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

    /**
     * A folder with a fake "mysqldump" and "mysql" (exment.backup_info.mysql_dir): they answer
     * "--version" and "--help", and run $body for a dump. Removed again in tearDown().
     */
    private function fakeMysqlBin(string $body, ?string $mysqlBody = null): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the fake mysqldump is a shell script');
        }
        if (!in_array(\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('the dump of mysqldump is for MySQL and MariaDB');
        }

        $dir = sys_get_temp_dir() . '/exment-fake-mysql-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        foreach (['mysqldump' => $body, 'mysql' => $mysqlBody ?? $body] as $name => $run) {
            $script = "#!/bin/sh\ncase \"\$1\" in\n  --version) echo '{$name}  Ver 8.0.0 (fake)'; exit 0;;\n  --help) echo 'fake'; exit 0;;\nesac\n{$run}\n";
            file_put_contents("{$dir}/{$name}", $script);
            chmod("{$dir}/{$name}", 0700);
        }
        $this->fakeMysqlDir = $dir;
        config(['exment.backup_info.mysql_dir' => $dir]);
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
