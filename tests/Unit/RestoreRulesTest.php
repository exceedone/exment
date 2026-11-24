<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\BackupRestore\Restore;
use Exceedone\Exment\Services\CoreExtensions;

/**
 * The rules an application adds to a restore (CoreExtensions::addRestoreRule): the keys of the
 * backup's .env and the files that are not brought back, and the steps before and after.
 */
class RestoreRulesTest extends UnitTestBase
{
    /**
     * @var Restore
     */
    protected $restore;

    /** @var array<int, string> the folders of the plugin disk this test made */
    protected $madeDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        CoreExtensions::reset();
        $this->restore = (new Restore())->initBackupRestore('restore_rules_test');
        \File::ensureDirectoryExists($this->restore->diskService()->tmpDiskItem()->dirFullPath());
    }

    protected function tearDown(): void
    {
        CoreExtensions::reset();
        $this->restore->diskService()->deleteTmpDirectory();
        foreach ($this->madeDirs as $dir) {
            \Storage::disk(Define::DISKNAME_PLUGIN_SYNC)->deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function testWithoutRulesEverythingIsRestored(): void
    {
        $this->assertTrue($this->callRestore('restoresEnv', 'EXMENT_ANYTHING'));
        $this->assertTrue($this->callRestore('restoresFile', BackupTarget::PLUGIN, null));
        $this->assertTrue($this->callRestore('restoresFile', BackupTarget::PLUGIN, 'Some/Plugin.php'));
    }

    public function testAnEnvRuleKeepsTheSitesValue(): void
    {
        CoreExtensions::addRestoreRule([
            'env' => function (string $key): bool {
                return $key !== 'EXMENT_DECIDED_BY_THE_OPERATOR';
            },
        ]);

        $this->assertFalse($this->callRestore('restoresEnv', 'EXMENT_DECIDED_BY_THE_OPERATOR'));
        $this->assertTrue($this->callRestore('restoresEnv', 'EXMENT_OTHER'));
    }

    public function testAFileRuleLeavesOutAFolderOrAFile(): void
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $keep = 'RestoreRulesKeep' . $suffix;
        $skip = 'RestoreRulesSkip' . $suffix;
        $this->madeDirs = [$keep, $skip];

        // the backup: two plugins
        $tmp = $this->restore->diskService()->tmpDiskItem()->dirFullPath();
        foreach ([$keep, $skip] as $name) {
            \File::ensureDirectoryExists(path_join($tmp, 'storage', 'app', 'plugins', $name));
            \File::put(path_join($tmp, 'storage', 'app', 'plugins', $name, 'config.json'), '{"plugin_name":"' . $name . '"}');
        }

        $seen = [];
        CoreExtensions::addRestoreRule([
            'file' => function (string $target, ?string $path) use ($skip, &$seen): bool {
                $seen[] = [$target, $path];
                return $target !== BackupTarget::PLUGIN || is_null($path) || strpos(ltrim($path, '/'), $skip . '/') !== 0;
            },
        ]);

        $this->callRestore('copyFiles');

        $disk = \Storage::disk(Define::DISKNAME_PLUGIN_SYNC);
        $this->assertTrue($disk->exists($keep . '/config.json'), 'the plugin the rule allows is restored');
        $this->assertFalse($disk->exists($skip . '/config.json'), 'the plugin the rule leaves out is not');
        $this->assertContains([BackupTarget::PLUGIN, null], $seen, 'the rule is asked for the whole folder first');
    }

    public function testAFileRuleLeavesOutAWholeFolder(): void
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $name = 'RestoreRulesNone' . $suffix;
        $this->madeDirs = [$name];

        $tmp = $this->restore->diskService()->tmpDiskItem()->dirFullPath();
        \File::ensureDirectoryExists(path_join($tmp, 'storage', 'app', 'plugins', $name));
        \File::put(path_join($tmp, 'storage', 'app', 'plugins', $name, 'config.json'), '{}');

        CoreExtensions::addRestoreRule([
            'file' => function (string $target, ?string $path): bool {
                return $target !== BackupTarget::PLUGIN;
            },
        ]);

        $this->callRestore('copyFiles');

        $this->assertFalse(\Storage::disk(Define::DISKNAME_PLUGIN_SYNC)->exists($name . '/config.json'));
    }

    public function testTheStepsRunInTheOrderAdded(): void
    {
        $order = [];
        CoreExtensions::addRestoreRule([
            'before' => function () use (&$order) {
                $order[] = 'before 1';
            },
            'after' => function () use (&$order) {
                $order[] = 'after 1';
            },
        ]);
        CoreExtensions::addRestoreRule([
            'before' => function () use (&$order) {
                $order[] = 'before 2';
            },
        ]);

        $this->callRestore('runRestoreRules', 'before');
        $this->callRestore('runRestoreRules', 'after');

        $this->assertSame(['before 1', 'before 2', 'after 1'], $order);
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoreExtensions::addRestoreRule([
            'database' => function () {
            },
        ]);
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    protected function callRestore(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(Restore::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->restore, ...$args);
    }
}
