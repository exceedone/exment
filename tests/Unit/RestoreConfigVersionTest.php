<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\BackupRestore\Restore;

/**
 * Restore copies the backup's config files only when the backup was taken on the same Laravel
 * major version (Restore::canRestoreConfig()).
 *
 * Config files of another Laravel version overwrite the current ones and stop the application:
 * restoring an Exment v6 (Laravel 10) backup into a Laravel 12 skeleton put back config/app.php
 * listing App\Providers\AuthServiceProvider, which the skeleton does not have, and every page
 * and artisan command failed.
 */
class RestoreConfigVersionTest extends UnitTestBase
{
    /**
     * @var Restore
     */
    protected $restore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restore = (new Restore())->initBackupRestore('restore_config_version_test');
        \File::ensureDirectoryExists($this->restore->diskService()->tmpDiskItem()->dirFullPath());
    }

    protected function tearDown(): void
    {
        $this->restore->diskService()->deleteTmpDirectory();

        parent::tearDown();
    }

    /**
     * A backup without version information (Exment v6 and older) does not restore config files.
     *
     * @return void
     */
    public function testConfigIsSkippedWithoutVersionFile()
    {
        $this->assertFalse($this->canRestoreConfig());
    }

    /**
     * @return void
     */
    public function testConfigIsSkippedForAnotherLaravelMajorVersion()
    {
        $this->writeVersionFile(['exment' => 'v6.2.11', 'laravel' => '10.50.2']);

        $this->assertFalse($this->canRestoreConfig());
    }

    /**
     * @return void
     */
    public function testConfigIsRestoredForTheSameLaravelMajorVersion()
    {
        $major = explode('.', app()->version())[0];
        $this->writeVersionFile(['exment' => 'v7.0.0', 'laravel' => "{$major}.0.0"]);

        $this->assertTrue($this->canRestoreConfig());
    }

    /**
     * @return void
     */
    public function testConfigIsSkippedForABrokenVersionFile()
    {
        \File::put($this->versionFilePath(), 'not json');
        $this->assertFalse($this->canRestoreConfig());

        $this->writeVersionFile(['exment' => 'v7.0.0']);
        $this->assertFalse($this->canRestoreConfig());
    }

    /**
     * @param array<string, string> $versions
     * @return void
     */
    protected function writeVersionFile(array $versions)
    {
        \File::put($this->versionFilePath(), json_encode($versions));
    }

    /**
     * @return string
     */
    protected function versionFilePath(): string
    {
        return path_join($this->restore->diskService()->tmpDiskItem()->dirFullPath(), Define::BACKUP_VERSION_FILE);
    }

    /**
     * @return bool
     */
    protected function canRestoreConfig(): bool
    {
        $reflection = new \ReflectionMethod(Restore::class, 'canRestoreConfig');
        $reflection->setAccessible(true);

        return $reflection->invoke($this->restore);
    }
}
