<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Storage\Disk\DiskServiceItem;
use Exceedone\Exment\Storage\Disk\PluginDiskService;
use Illuminate\Support\Facades\Storage;

/**
 * A plugin kept on a cloud disk (EXMENT_DRIVER_PLUGIN=s3) is run from its local copy. Every
 * request checked that copy for each plugin, and made the cloud disk to do so - its client and
 * credentials, and a request asking for the plugin's directory: 30-80 ms per plugin on Exment
 * SaaS (ISS00413). The check now reads the local copy only; the cloud disk is made when the copy
 * is to be downloaded.
 *
 * The cloud disk here is one that throws when made. The local copy is a directory of the test.
 */
class PluginDiskLazyTest extends UnitTestBase
{
    /** @var string */
    protected $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/exment_plugin_lazy_' . getmypid();
        @mkdir($this->root, 0777, true);

        Storage::extend('throws-when-made', function () {
            throw new \RuntimeException('the cloud disk was made');
        });
        config([
            'exment.driver.plugin' => 's3',
            'filesystems.disks.plugin_sync' => ['driver' => 'throws-when-made'],
            'filesystems.disks.plugin_local' => ['driver' => 'local', 'root' => $this->root],
        ]);
        Storage::forgetDisk(['plugin_sync', 'plugin_local']);
        // Exment SaaS: the worker remembers the plugins it checked; each test starts afresh
        PluginDiskService::clearSyncedPaths();
    }

    protected function tearDown(): void
    {
        \File::deleteDirectory($this->root);
        Storage::forgetDisk(['plugin_sync', 'plugin_local']);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testAnUpToDateCopyIsCheckedWithoutTheCloudDisk()
    {
        $plugin = $this->plugin();
        $this->copy($plugin, $plugin->updated_at->format('YmdHis'));

        $this->assertFalse($this->isNeedDownload(new PluginDiskService($plugin)));
    }

    /**
     * @return void
     */
    public function testAnOldOrMissingCopyIsToBeDownloaded()
    {
        $plugin = $this->plugin();
        $this->assertTrue($this->isNeedDownload(new PluginDiskService($plugin)), 'no copy');

        $this->copy($plugin, '20000101000000');
        $this->assertTrue($this->isNeedDownload(new PluginDiskService($plugin)), 'an older copy');
    }

    /**
     * The download is where the cloud disk is made.
     *
     * @return void
     */
    public function testTheDownloadMakesTheCloudDisk()
    {
        $this->expectExceptionMessage('the cloud disk was made');
        (new PluginDiskService($this->plugin()))->syncFromDisk();
    }

    /**
     * The path of a plugin (asked several times per plugin on every page) is its local copy. A copy
     * that is up to date is used without asking the cloud disk whether the plugin is there.
     *
     * @return void
     */
    public function testAnUpToDateCopyIsUsedWithoutTheCloudDisk()
    {
        $plugin = $this->plugin();
        $this->copy($plugin, $plugin->updated_at->format('YmdHis'));
        $local = \Exment::replaceBackToSlash(Storage::disk('plugin_local')->path($plugin->getPath()));

        $this->assertSame(rtrim($local, '/'), rtrim($plugin->getLocalFullPath(), '/'));
        $this->assertSame(rtrim($local, '/'), rtrim(\Exment::replaceBackToSlash($plugin->getFullPath()), '/'));
        $plugin->requirePlugin();
    }

    /**
     * A copy that is not up to date is downloaded from the cloud disk, as before.
     *
     * @return void
     */
    public function testAnOldCopyStillAsksTheCloudDisk()
    {
        $plugin = $this->plugin();
        $this->copy($plugin, '20000101000000');
        $this->expectExceptionMessage('the cloud disk was made');
        $plugin->getFullPath();
    }

    /**
     * A plugin required several times is looked in once by the class loader.
     *
     * @return void
     */
    public function testTheClassLoaderKeepsADirectoryOnce()
    {
        $loader = new \Exceedone\Exment\Services\ClassLoader();
        $loader->registerDir('/a', 'App\Plugins\A');
        $loader->registerDir('/a', 'App\Plugins\A');
        $loader->registerDir('/b', 'App\Plugins\B');
        $dirs = new \ReflectionProperty($loader, 'dirs');
        $dirs->setAccessible(true);
        $this->assertCount(2, $dirs->getValue($loader));
    }

    /**
     * On the local disk (the default) nothing is copied.
     *
     * @return void
     */
    public function testALocalPluginIsNotCopied()
    {
        config(['exment.driver.plugin' => 'local']);
        $this->assertFalse($this->isNeedDownload(new PluginDiskService($this->plugin())));
    }

    /**
     * A disk given as a closure is made when first used, once.
     *
     * @return void
     */
    public function testADiskItemMakesItsDiskWhenUsed()
    {
        $made = 0;
        $item = new DiskServiceItem(function () use (&$made) {
            $made++;
            return Storage::disk('plugin_local');
        }, 'a.txt', 'dir');
        $this->assertSame('dir', $item->dirName());
        $this->assertSame(0, $made);

        $item->disk();
        $this->assertTrue($item->isDriverLocal());
        $this->assertSame(1, $made);
    }

    protected function plugin(): Plugin
    {
        $plugin = new Plugin();
        $plugin->plugin_name = 'LazyDiskTest';
        $plugin->updated_at = now();
        return $plugin;
    }

    protected function copy(Plugin $plugin, string $updatedAt): void
    {
        $disk = Storage::disk('plugin_local');
        $disk->makeDirectory($plugin->getPath());
        $disk->put($plugin->getPath() . '/updated_at.txt', $updatedAt);
    }

    protected function isNeedDownload(PluginDiskService $service): bool
    {
        $method = new \ReflectionMethod($service, 'isNeedDownload');
        $method->setAccessible(true);
        return (bool) $method->invoke($service);
    }
}
