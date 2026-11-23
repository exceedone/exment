<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\PhpVersion;

/**
 * The PHP version against what the installed packages need (composer's platform check).
 */
class PhpVersionRequireTest extends UnitTestBase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/exment_php_version_' . uniqid('', true);
        @mkdir($this->dir . '/vendor/composer', 0777, true);
        PhpVersion::$basePath = $this->dir;
    }

    protected function tearDown(): void
    {
        PhpVersion::$basePath = null;
        PhpVersion::$version = null;
        @unlink($this->dir . '/vendor/composer/platform_check.php');
        @unlink($this->dir . '/composer.json');
        @rmdir($this->dir . '/vendor/composer');
        @rmdir($this->dir . '/vendor');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testTheMinimumOfThePlatformCheck(): void
    {
        file_put_contents($this->dir . '/vendor/composer/platform_check.php', "<?php\n\$issues = array();\nif (!(PHP_VERSION_ID >= 80300)) {\n    \$issues[] = 'Your Composer dependencies require a PHP version \">= 8.3.0\".';\n}\n");
        $this->assertSame('8.3.0', PhpVersion::minimum());

        PhpVersion::$version = '8.2.29';
        $item = new PhpVersion();
        $this->assertSame(SystemRequireResult::NG, $item->checkResult());
        $this->assertStringContainsString('8.3.0', strval($item->getMessage()));
        $this->assertStringContainsString('8.2.29', strval($item->getResultText()));

        PhpVersion::$version = '8.3.37';
        $this->assertSame(SystemRequireResult::OK, (new PhpVersion())->checkResult());
    }

    public function testComposerJsonWhenThereIsNoPlatformCheck(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['require' => ['php' => '^8.2']]));
        $this->assertSame('8.2.0', PhpVersion::minimum());

        file_put_contents($this->dir . '/composer.json', json_encode(['require' => ['php' => '^8.2'], 'config' => ['platform' => ['php' => '8.3.1']]]));
        $this->assertSame('8.3.1', PhpVersion::minimum());
    }
}
