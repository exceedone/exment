<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\CoreExtensions;
use Exceedone\Exment\Services\OperatorConfig;

/**
 * The settings the operator decides, from files only root can write (ISS02861): applied over the
 * config of the site; a file the web server may write is not read.
 */
class OperatorConfigTest extends UnitTestBase
{
    /** @var string */
    protected $file;

    protected function setUp(): void
    {
        parent::setUp();
        CoreExtensions::reset();
        OperatorConfig::reset();
        $this->file = sys_get_temp_dir() . '/exment-operator-' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        CoreExtensions::reset();
        OperatorConfig::reset();
        parent::tearDown();
    }

    public function testThePaths(): void
    {
        $paths = OperatorConfig::paths();
        $this->assertSame(OperatorConfig::SERVER_FILE, $paths[0]);
        $this->assertSame(OperatorConfig::SITES_DIR . '/' . basename(base_path()) . '.php', $paths[1], 'the site by the name of its folder');
        CoreExtensions::addOperatorConfigPath($this->file);
        $this->assertSame($this->file, OperatorConfig::paths()[2]);
    }

    public function testTheFileWinsOverTheConfig(): void
    {
        config(['exment.plugin_upload_enabled' => true]);
        file_put_contents($this->file, "<?php\nreturn ['exment.plugin_upload_enabled' => false, 'exment.features_locked' => 'tuning', 'not a key' => 1, 'nodot' => 2];\n");
        CoreExtensions::addOperatorConfigPath($this->file);
        OperatorConfig::$trustWritable = true;

        $applied = OperatorConfig::apply();
        $this->assertSame(['exment.plugin_upload_enabled', 'exment.features_locked'], $applied, 'config keys only');
        $this->assertFalse(config('exment.plugin_upload_enabled'));
        $this->assertSame('tuning', config('exment.features_locked'));
        $this->assertTrue(OperatorConfig::locks('exment.plugin_upload_enabled'));
        $this->assertFalse(OperatorConfig::locks('exment.api_max_rate_limit'));
    }

    public function testAFileOthersMayWriteIsNotRead(): void
    {
        config(['exment.plugin_upload_enabled' => true]);
        file_put_contents($this->file, "<?php\nreturn ['exment.plugin_upload_enabled' => false];\n");
        @chmod($this->file, 0666);
        CoreExtensions::addOperatorConfigPath($this->file);

        // the folder of the temporary files is writable by everyone, and the file too now
        $this->assertFalse(OperatorConfig::trusted($this->file));
        $this->assertSame([], OperatorConfig::apply());
        $this->assertTrue(config('exment.plugin_upload_enabled'));
    }

    public function testAFileOfRootIsRead(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('only root can make a file of root');
        }
        $dir = sys_get_temp_dir() . '/exment-operator-root-' . uniqid();
        mkdir($dir, 0755);
        $file = $dir . '/site.php';
        file_put_contents($file, "<?php\nreturn ['exment.api_max_rate_limit' => 7];\n");
        chmod($file, 0644);
        try {
            CoreExtensions::addOperatorConfigPath($file);
            $this->assertTrue(OperatorConfig::trusted($file));
            OperatorConfig::apply();
            $this->assertSame(7, config('exment.api_max_rate_limit'));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testAFileThatIsNotAnArrayOrBroken(): void
    {
        OperatorConfig::$trustWritable = true;
        CoreExtensions::addOperatorConfigPath($this->file);

        file_put_contents($this->file, "<?php\nreturn 'nothing';\n");
        $this->assertSame([], OperatorConfig::apply());

        file_put_contents($this->file, "<?php\nthrow new \\RuntimeException('broken');\n");
        $this->assertSame([], OperatorConfig::apply(), 'a broken file stops nothing');

        $this->assertSame([], (function () {
            CoreExtensions::reset();
            CoreExtensions::addOperatorConfigPath('/no/such/file.php');
            return OperatorConfig::apply();
        })());
    }
}
