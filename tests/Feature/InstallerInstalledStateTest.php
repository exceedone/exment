<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\InitializeStatus;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Installer\DatabaseForm;
use Exceedone\Exment\Services\Installer\InstalledState;
use Exceedone\Exment\Services\Installer\InstallingForm;
use Exceedone\Exment\Services\Installer\InstallService;
use Exceedone\Exment\Services\Installer\LangForm;
use Exceedone\Exment\Services\Installer\SystemRequireForm;
use Exceedone\Exment\Tests\InstalledStateTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The install pages follow "installed" (InstalledState), which does not depend
 * on the database nor on the config cache.
 */
class InstallerInstalledStateTest extends FeatureTestBase
{
    use InstalledStateTrait;

    protected function tearDown(): void
    {
        $this->restoreInstalledState();
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE);
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS);
        System::clearCache();
        parent::tearDown();
    }

    /**
     * canConnection() and hasTable() answer "no database" without a real call.
     */
    private function databaseDown(): void
    {
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE, false);
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, []);
    }

    public function testInstalledAndDatabaseDownIs503OnEveryPath(): void
    {
        $this->useInstalledState(true);
        $this->databaseDown();

        $this->get(admin_base_path('install'))->assertStatus(503);
        $this->get(admin_base_path('install/reset'))->assertStatus(503);
        $this->post(admin_base_path('install'), ['locale' => 'en'])->assertStatus(503);
        $this->get(admin_base_path('initialize'))->assertStatus(503);
        $this->get(admin_base_path('dashboard'))->assertStatus(503);
    }

    /**
     * With "php artisan config:cache", env() does not read .env: EXMENT_INITIALIZE
     * is read from the file itself.
     */
    public function testEnvFileIsReadWithoutEnvAsWithAConfigCache(): void
    {
        $this->useInstalledState(false, "APP_KEY=base64:abc\nDB_CONNECTION=mysql\nDB_DATABASE=exment\nEXMENT_INITIALIZE=1\n");
        $this->assertFalse(getenv('EXMENT_INITIALIZE'));
        $this->assertTrue(InstalledState::isInstalled());

        $this->databaseDown();
        $this->get(admin_base_path('install'))->assertStatus(503);
        $this->get(admin_base_path('dashboard'))->assertStatus(503);
    }

    public function testEnvValuesAreRead(): void
    {
        $cases = [
            "EXMENT_INITIALIZE=1" => true,
            "EXMENT_INITIALIZE=true" => true,
            "EXMENT_INITIALIZE=\"1\"" => true,
            "EXMENT_INITIALIZE = 1" => true,
            "EXMENT_INITIALIZE=1 # set up" => true,
            "EXMENT_INITIALIZE=0" => false,
            "EXMENT_INITIALIZE=" => false,
            "# EXMENT_INITIALIZE=1" => false,
            "APP_KEY=base64:abc\nDB_DATABASE=exment" => false,
        ];
        foreach ($cases as $content => $expected) {
            $this->useInstalledState(false, $content . "\n");
            $this->assertSame($expected, InstalledState::isInstalled(), $content);
            $this->restoreInstalledState();
        }

        // the real environment of the process counts too
        $this->useInstalledState(false);
        putenv('EXMENT_INITIALIZE=1');
        $this->assertTrue(InstalledState::isInstalled());
    }

    public function testNotInstalledTheInstallerIsReachable(): void
    {
        $this->useInstalledState(false, "APP_KEY=base64:abc\nDB_CONNECTION=mysql\n");
        $this->databaseDown();

        $this->get(admin_base_path('dashboard'))->assertRedirect(admin_base_path('install'));

        // the database step of the wizard
        InstallService::setInitializeStatus(InitializeStatus::LANG);
        $this->get(admin_base_path('install'))->assertStatus(200);

        // starting over is possible
        $this->get(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
    }

    public function testInstalledAndDatabaseUpTheInstallPagesGoToTheLogin(): void
    {
        $this->useInstalledState(true);

        $this->get(admin_base_path('install'))->assertRedirect(admin_base_path('auth/login'));
        $this->get(admin_base_path('install/reset'))->assertRedirect(admin_base_path('auth/login'));
        $this->post(admin_base_path('install'), ['locale' => 'en'])->assertRedirect(admin_base_path('auth/login'));
    }

    public function testTheInstallerRefusesToRunWhenInstalled(): void
    {
        $this->useInstalledState(true);
        $envPath = base_path('.env');
        $before = (string) file_get_contents($envPath);

        request()->merge([
            'locale' => 'en',
            'connection' => 'mysql', 'host' => '203.0.113.1', 'port' => '3306',
            'database' => 'x', 'username' => 'x', 'password' => 'x',
            'ADMIN_ROUTE_PREFIX' => 'admin',
        ]);
        InstallService::setInputParams(['DB_HOST' => '203.0.113.1', 'DB_DATABASE' => 'x']);

        foreach ([new LangForm(), new DatabaseForm(), new SystemRequireForm(), new InstallingForm()] as $form) {
            try {
                $form->post();
                $this->fail(get_class($form) . '::post must refuse on an installed site');
            } catch (HttpException $ex) {
                $this->assertSame(404, $ex->getStatusCode(), get_class($form));
            }
        }

        // the wizard steps through the service too
        InstallService::setInitializeStatus(InitializeStatus::SYSTEM_REQUIRE);
        foreach (['index', 'post'] as $method) {
            try {
                call_user_func([InstallService::class, $method]);
                $this->fail("InstallService::$method must refuse on an installed site");
            } catch (HttpException $ex) {
                $this->assertSame(404, $ex->getStatusCode());
            }
        }

        $this->assertSame($before, (string) file_get_contents($envPath), '.env is not touched');
    }

    public function testASiteSetUpBeforeGetsTheMarker(): void
    {
        $this->useInstalledState(false);
        $this->assertTrue(boolval(System::initialized()));
        $this->assertFileDoesNotExist(InstalledState::markerPath());

        $this->get(admin_base_path('auth/login'));

        $this->assertFileExists(InstalledState::markerPath());
    }
}
