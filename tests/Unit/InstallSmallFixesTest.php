<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Installer\InstallingForm;

/**
 * Small things of the install: the cookie path of a site under a folder, the prefix field,
 * and the "how to set it" link only when there is a page.
 */
class InstallSmallFixesTest extends UnitTestBase
{
    protected function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testTheCookiePathOfASiteUnderAFolder(): void
    {
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        if (!is_null(env('SESSION_PATH'))) {
            $this->markTestSkipped('SESSION_PATH is set here');
        }
        $this->assertSame(['SESSION_PATH' => '/exment'], InstallingForm::sessionPath('/exment/'));
        $this->assertSame([], InstallingForm::sessionPath(''), 'at the top of the domain: "/" as before');
    }

    public function testThePrefixFieldStartsEmpty(): void
    {
        $view = $this->read('resources/views/install/installing.blade.php');
        $this->assertStringContainsString("value=\"{{ old('ADMIN_ROUTE_PREFIX', '') }}\"", $view);
    }

    public function testNoErrorLogForTheTablesBeforeTheInstall(): void
    {
        $helpers = $this->read('src/Services/Helpers.php');
        $this->assertStringContainsString("if (app()->configurationIsCached() || boolval(env('EXMENT_INITIALIZE', false))) {", $helpers);
        $this->assertStringContainsString("\\Log::debug('Error fetching table names (not installed yet): '", $helpers);
    }

    public function testNoEmptyLinkToTheManual(): void
    {
        foreach (['resources/views/widgets/system-require.blade.php', 'resources/views/tuning/index.blade.php'] as $path) {
            $view = $this->read($path);
            $this->assertStringNotContainsString('href="{{$check->getSettingUrl()}}"', $view, $path);
            $this->assertStringNotContainsString('href="{{ $check->getSettingUrl() }}"', $view, $path);
            $this->assertStringContainsString('@if(!is_null($settingUrl = $check->getSettingUrl()))', $view, $path);
        }
    }
}
