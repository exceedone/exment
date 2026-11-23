<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;

/**
 * The .env at the end of the install: production (exment.zip ships "local") and not
 * readable by others.
 */
class InstalledEnvTest extends UnitTestBase
{
    /** @var array<int, array<string, mixed>> */
    private $written = [];

    /** @var string */
    private $env;

    protected function setUp(): void
    {
        parent::setUp();
        AutoTuning::$envWriter = function (array $values) {
            $this->written[] = $values;
        };
        $this->env = sys_get_temp_dir() . '/exment_installed_env_' . uniqid('', true);
        file_put_contents($this->env, "APP_ENV=local\n");
        @chmod($this->env, 0644);
    }

    protected function tearDown(): void
    {
        AutoTuning::$envWriter = null;
        @unlink($this->env);
        parent::tearDown();
    }

    public function testALocalSiteBecomesProductionAndTheFileIsClosed(): void
    {
        config(['app.env' => 'local']);
        AutoTuning::installedEnv([$this->env]);

        $this->assertSame([['EXMENT_INITIALIZE' => 1, 'APP_ENV' => 'production']], $this->written);
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('posix_geteuid')) {
            clearstatcache();
            $this->assertSame('640', substr(sprintf('%o', fileperms($this->env)), -3));
        }
    }

    public function testAnotherEnvironmentIsKept(): void
    {
        config(['app.env' => 'staging']);
        AutoTuning::installedEnv([$this->env]);

        $this->assertSame([['EXMENT_INITIALIZE' => 1]], $this->written);
    }

    public function testTheSetupUsesIt(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Installer/InitializeForm.php');
        $this->assertStringContainsString('AutoTuning::installedEnv()', $source);
    }
}
