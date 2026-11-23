<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Console\InstallCommand;
use Exceedone\Exment\Services\Installer\AppKey;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The install on a production site (migrate and db:seed with --force, a failure is an exit
 * code), and a site whose .env has no APP_KEY.
 */
class InstallForceAndKeyTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        AppKey::$envWriter = null;
        parent::tearDown();
    }

    /**
     * @param array<string, int> $codes the exit code of each command
     */
    private function command(array $codes, array &$calls): InstallCommand
    {
        $command = new class ($codes, $calls) extends InstallCommand {
            /** @var array<string, int> */
            public $codes;

            /** @var array<int, array{0: string, 1: array<string, mixed>}> */
            public $calls;

            /**
             * @param array<string, int> $codes
             * @param array<int, array{0: string, 1: array<string, mixed>}> $calls
             */
            public function __construct(array $codes, array &$calls)
            {
                parent::__construct();
                $this->codes = $codes;
                $this->calls = &$calls;
            }

            public function call($command, array $arguments = [])
            {
                $this->calls[] = [strval($command), $arguments];
                return $this->codes[strval($command)] ?? 0;
            }
        };
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        return $command;
    }

    public function testMigrateAndSeedAreForced(): void
    {
        $calls = [];
        $this->assertSame(0, $this->command([], $calls)->initDatabase());
        $this->assertSame(['migrate', 'db:seed'], array_column($calls, 0));
        $this->assertTrue($calls[0][1]['--force']);
        $this->assertTrue($calls[1][1]['--force']);
    }

    public function testAFailedMigrateStopsWithItsCode(): void
    {
        $calls = [];
        $this->assertSame(1, $this->command(['migrate' => 1], $calls)->initDatabase());
        $this->assertSame(['migrate'], array_column($calls, 0), 'no seed without the tables');

        $calls = [];
        $this->assertSame(2, $this->command(['db:seed' => 2], $calls)->initDatabase());
    }

    public function testTheInstallScreenForcesTheKey(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Installer/InstallingForm.php');
        $this->assertStringContainsString("\\Artisan::call('key:generate', ['--force' => true]);", $source);
    }

    public function testAMissingKeyIsMadeBeforeTheInstall(): void
    {
        $written = [];
        AppKey::$envWriter = function (array $values) use (&$written) {
            $written[] = $values;
        };
        $before = config('app.key');
        try {
            config(['app.key' => '']);
            $this->assertTrue(AppKey::ensure());
            $this->assertCount(1, $written);
            $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/=]{40,}$/', $written[0]['APP_KEY']);
            $this->assertSame($written[0]['APP_KEY'], config('app.key'), 'this request too');

            // a key there: nothing
            $this->assertFalse(AppKey::ensure());
            $this->assertCount(1, $written);
        } finally {
            config(['app.key' => $before]);
        }
    }
}
