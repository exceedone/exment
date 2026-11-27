<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The generations of the backups (ISS02943): the automatic ones count toward "Number of
 * preservation generations"; the ones made by hand only when it is chosen; the ones made before a
 * restore have a limit of their own. And the space the backups use, with a warning when the disk
 * has little free space left.
 */
class BackupGenerationsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        $this->disk()->delete(array_map(fn ($name) => "list/{$name}.zip", $this->made));
        System::clearCache();
        parent::tearDown();
    }

    public function testOnlyTheAutomaticBackupsCountUnlessTheManualOnesAreChosen(): void
    {
        $prefix = 'claudegen' . substr(md5(uniqid('', true)), 0, 6);
        $old = $this->make("{$prefix}a1", 400);
        $middle = $this->make("{$prefix}a2", 300);
        $new = $this->make("{$prefix}a3", 200);
        $manual = $this->make("{$prefix}m1", 500, BackupRun::TRIGGER_SCREEN);
        $command = $this->make("{$prefix}m2", 450, BackupRun::TRIGGER_COMMAND);
        $safety = $this->make(BackupRuns::SAFETY_PREFIX . '20200101000000', 600);
        $others = $this->otherBackups();

        System::backup_history_files(2 + count($others));
        System::backup_history_include_manual(false);
        System::clearCache();
        $this->removeOldBackups();

        $this->assertFalse($this->exists($old), 'the oldest automatic backup is deleted');
        foreach ([$middle, $new, $manual, $command, $safety] as $name) {
            $this->assertTrue($this->exists($name), "{$name} is kept");
        }

        // the manual ones counted too: the oldest of all goes
        System::backup_history_include_manual(true);
        System::clearCache();
        $this->removeOldBackups();

        $this->assertFalse($this->exists($manual), 'the oldest backup made by hand is deleted');
        $this->assertFalse($this->exists($command));
        $this->assertTrue($this->exists($new));
        $this->assertTrue($this->exists($safety), 'a backup made before a restore has its own limit');
    }

    public function testOnlyTheLastBackupsMadeBeforeARestoreAreKept(): void
    {
        config(['exment.backup.keep_safety' => 3]);
        $names = [];
        foreach (['20200101000001', '20200101000002', '20200101000003', '20200101000004', '20200101000005'] as $time) {
            $names[] = $this->make(BackupRuns::SAFETY_PREFIX . $time, 10);
        }
        $before = count(array_filter($this->disk()->files('list'), fn ($file) => str_starts_with(basename($file), BackupRuns::SAFETY_PREFIX)));

        $this->assertSame(max(0, $before - 3), BackupRuns::keepSafetyBackups());
        $this->assertFalse($this->exists($names[0]));
        $this->assertFalse($this->exists($names[1]));
        $this->assertTrue($this->exists($names[4]));
    }

    public function testTheSpaceOfTheBackupsAndAWarningWhenTheDiskIsAlmostFull(): void
    {
        $name = $this->make('claudespace' . substr(md5(uniqid('', true)), 0, 6), 2048);

        $space = Backup::space();
        $this->assertGreaterThanOrEqual(1, $space['count']);
        // the file made here holds 3 bytes ("zip")
        $this->assertGreaterThanOrEqual(3, $space['bytes']);
        $this->assertNotNull($space['free'], 'the backup disk of the tests is on this server');

        config(['exment.backup.low_free_bytes' => PHP_INT_MAX]);
        $this->assertTrue(Backup::space()['low']);

        $page = $this->get(admin_url('backup'))->assertOk();
        $page->assertSee('backup-space-low', false);
        $page->assertSee('backup_history_include_manual', false);
        $this->assertTrue($this->exists($name));
    }

    private function removeOldBackups(): void
    {
        $backup = new Backup();
        $backup->initBackupRestore();
        $method = new \ReflectionMethod(Backup::class, 'removeOldBackups');
        $method->invoke($backup);
    }

    /**
     * A backup file, $age seconds old, with the record of its run when it was made by hand.
     */
    private function make(string $name, int $age, ?string $trigger = null): string
    {
        $this->disk()->put("list/{$name}.zip", 'zip');
        touch($this->disk()->path("list/{$name}.zip"), time() - $age);
        clearstatcache();
        $this->made[] = $name;
        if (isset($trigger)) {
            $run = new BackupRun();
            $run->uuid = (string) Str::uuid();
            $run->kind = BackupRun::KIND_BACKUP;
            $run->triggered_by = $trigger;
            $run->status = BackupRun::STATUS_SUCCEEDED;
            $run->file_name = $name;
            $run->save();
        }
        return $name;
    }

    /**
     * The automatic backups other tests left, newer than the oldest made here (kept by the count).
     *
     * @return array<int, string>
     */
    private function otherBackups(): array
    {
        return array_values(array_filter($this->disk()->files('list'), function ($file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            return str_ends_with($file, '.zip') && !in_array($name, $this->made, true)
                && !str_starts_with($name, BackupRuns::SAFETY_PREFIX)
                && $this->disk()->lastModified($file) > time() - 400;
        }));
    }

    private function exists(string $name): bool
    {
        return $this->disk()->exists("list/{$name}.zip");
    }

    private function disk(): Filesystem
    {
        return Storage::disk(Define::DISKNAME_BACKUP_SYNC);
    }
}
