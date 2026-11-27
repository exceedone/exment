<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Exceedone\Exment\Storage\Disk\BackupDiskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;

/**
 * A backup is checked before it is restored (ISS02943): its sha256 against the one recorded when
 * it was made, and every entry read to its end (a broken file is refused before the database is
 * replaced). And the files compressed already are stored as they are.
 */
class BackupVerifyTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $temps = [];

    /** @var array<int, string> */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        Notification::fake();
        $this->backupsBefore = Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list');
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $temp) {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        $created = array_diff(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->files('list'), $this->backupsBefore);
        if (count($created) > 0) {
            Storage::disk(Define::DISKNAME_BACKUP_SYNC)->delete(array_values($created));
        }
        // the files of the runs too (a status file of a restore left behind would answer the pages)
        /** @var \Illuminate\Support\Collection<int, BackupRun> $runs */
        $runs = BackupRun::query()->get();
        foreach ($runs as $run) {
            foreach ([BackupRuns::logFullPath($run), BackupRuns::statusPath($run->uuid), BackupRuns::consolePath($run), BackupRuns::tokenPath($run->uuid), storage_path(path_join(BackupRuns::LOG_DIR, $run->uuid . '.rate'))] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        parent::tearDown();
    }

    public function testAGoodBackupPasses(): void
    {
        $path = $this->zip(['table_definition.sql' => str_repeat("CREATE TABLE a (id int);\n", 100), 'config/app.php' => '<?php return [];']);
        $zip = $this->open($path);

        BackupDiskService::verify($zip, $path, hash_file('sha256', $path) ?: null);
        BackupDiskService::verify($zip, $path, null);
        $zip->close();
        $this->addToAssertionCount(1);
    }

    public function testAFileThatChangedIsRefused(): void
    {
        $path = $this->zip(['table_definition.sql' => 'CREATE TABLE a (id int);']);
        $zip = $this->open($path);

        try {
            BackupDiskService::verify($zip, $path, str_repeat('0', 64));
            $this->fail('a file whose sha256 differs was not refused');
        } catch (InvalidZipEntryException $e) {
            $this->assertSame(strval(exmtrans('backup.run.message.verify_sha256')), $e->getMessage());
        } finally {
            $zip->close();
        }
    }

    public function testABrokenEntryIsRefusedBeforeItIsExtracted(): void
    {
        $content = str_repeat('exment backup data ', 200);
        $path = $this->zip(['data.tsv' => $content], true);
        // a byte of the stored data changed: its CRC no longer matches
        $bytes = strval(file_get_contents($path));
        $at = strpos($bytes, 'exment backup data');
        $this->assertNotFalse($at);
        $bytes[$at + 3] = 'X';
        file_put_contents($path, $bytes);

        $zip = $this->open($path);
        try {
            BackupDiskService::verify($zip, $path, null);
            $this->fail('a broken entry was not refused');
        } catch (InvalidZipEntryException $e) {
            $this->assertSame(strval(exmtrans('backup.run.message.verify_read', ['name' => 'data.tsv'])), $e->getMessage());
        } finally {
            $zip->close();
        }
    }

    public function testTheCommandShowsTheTranslatedMessageOnly(): void
    {
        $path = $this->zip(['data.tsv' => str_repeat('exment backup data ', 200)], true);
        $bytes = strval(file_get_contents($path));
        $at = strpos($bytes, 'exment backup data');
        $this->assertNotFalse($at);
        $bytes[$at + 3] = 'X';
        file_put_contents($path, $bytes);

        $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_COMMAND);
        $lines = [];
        $progress = new \Exceedone\Exment\Services\BackupRestore\RunProgress($run, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $progress->begin();
        $zip = $this->open($path);
        try {
            BackupDiskService::verify($zip, $path, null, $progress);
            $this->fail('a broken entry was not refused');
        } catch (InvalidZipEntryException $e) {
            // refused
        } finally {
            $zip->close();
            $progress->releaseLock();
        }
        // the English text of the log is not printed (the translated message of the exception is)
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('a file of the backup cannot be read', $line);
        }
        $this->assertStringContainsString('a file of the backup cannot be read', strval(BackupRuns::readLog($run)), 'kept in the log');
    }

    public function testABackupRecordsItsSha256AndStoresTheCompressedFilesAsTheyAre(): void
    {
        $pending = $this->artisan('exment:backup', ['--target' => BackupTarget::PLUGIN]);
        $this->assertInstanceOf(PendingCommand::class, $pending);
        $pending->assertExitCode(0)->run();

        /** @var BackupRun $run */
        $run = BackupRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BackupRun::STATUS_SUCCEEDED, $run->status);
        $file = "list/{$run->file_name}.zip";
        $local = $this->temp();
        file_put_contents($local, strval(Storage::disk(Define::DISKNAME_BACKUP_SYNC)->get($file)));
        $this->assertSame(hash_file('sha256', $local), $run->sha256);

        $zip = $this->open($local);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $this->assertIsArray($stat);
                $extension = strtolower(pathinfo(strval($stat['name']), PATHINFO_EXTENSION));
                if (in_array($extension, Backup::STORED_EXTENSIONS, true)) {
                    $this->assertSame(\ZipArchive::CM_STORE, $stat['comp_method'], strval($stat['name']));
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(array $entries, bool $store = false): string
    {
        $path = $this->temp();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            if ($store) {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
        }
        $zip->close();
        return $path;
    }

    private function open(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        return $zip;
    }

    private function temp(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'claude_backup_verify');
        $this->temps[] = $path;
        return $path;
    }
}
