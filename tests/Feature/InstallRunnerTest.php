<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Installer\InstalledState;
use Exceedone\Exment\Services\Installer\InstallRunner;
use Exceedone\Exment\Tests\InstalledStateTrait;

/**
 * The install of the web installer in steps (InstallRunner): install/step does a part of the
 * work in each call and answers the progress, install/status answers the progress only.
 * The migrations and the other parts are stand-ins here: the database of the tests is kept.
 */
class InstallRunnerTest extends FeatureTestBase
{
    use InstalledStateTrait;

    /** @var string */
    private $dir;

    /** @var string[] the migrations of the stand-in */
    private $all = ['m1', 'm2', 'm3', 'm4', 'm5'];

    /** @var string[] the migrations done */
    private $ran = [];

    /** @var string[] what was run, in turn */
    private $calls = [];

    /** @var string|null a migration that fails once */
    private $failOnce = null;

    /** @var int the micro seconds each migration takes */
    private $migrationSleep = 0;

    /** @var string the token of the browser that started the install */
    private $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        // not installed: the marker and .env of a temporary folder
        $this->useInstalledState(false);

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('exment_install_run_', true);
        mkdir($this->dir);
        InstallRunner::$statePath = $this->dir . DIRECTORY_SEPARATOR . 'state.json';
        InstallRunner::$lockPath = $this->dir . DIRECTORY_SEPARATOR . 'state.lock';

        InstallRunner::$migrationLister = function () {
            return [$this->all, $this->ran];
        };
        InstallRunner::$migrationRunner = function (string $name) {
            if ($this->failOnce === $name) {
                $this->failOnce = null;
                throw new \RuntimeException("SQLSTATE[42S01]: Base table or view already exists ($name)");
            }
            if ($this->migrationSleep > 0) {
                usleep($this->migrationSleep);
            }
            $this->calls[] = $name;
            $this->ran[] = $name;
        };
        InstallRunner::$phaseRunner = function (string $phase) {
            $this->calls[] = $phase;
            // as the command at the end: the site is installed
            if ($phase === InstallRunner::PHASE_FINISH) {
                InstalledState::markInstalled();
            }
        };

        config(['exment.install_step_seconds' => 100]);
    }

    protected function tearDown(): void
    {
        InstallRunner::$statePath = null;
        InstallRunner::$lockPath = null;
        InstallRunner::$migrationLister = null;
        InstallRunner::$migrationRunner = null;
        InstallRunner::$phaseRunner = null;
        foreach ((array) glob($this->dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        $this->restoreInstalledState();
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE);
        session()->forget(Define::SYSTEM_KEY_SESSION_INITIALIZE_INPUTS);
        System::clearCache();
        parent::tearDown();
    }

    /**
     * Start the install, as the browser that started it (its cookie).
     */
    private function begin(): void
    {
        $token = InstallRunner::start();
        $this->assertIsString($token);
        $this->token = $token;
        $this->asBrowser($token);
    }

    /**
     * The next requests with this token in the cookie of the install (null: no cookie).
     */
    private function asBrowser(?string $token): void
    {
        $this->unencryptedCookies = [];
        // the cookies go with the JSON requests too (as from the page: same origin)
        $this->withCredentials();
        if (!is_null($token)) {
            $this->withUnencryptedCookie(InstallRunner::COOKIE, $token);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function step(array $data = []): array
    {
        $response = $this->postJson(admin_base_path('install/step'), $data);
        $response->assertStatus(200);
        return $response->json();
    }

    public function testTheStepsRunInTurnWithTheProgress(): void
    {
        $this->ran = ['m1', 'm2'];
        $this->begin();

        // the files to publish
        $p = $this->step();
        $this->assertSame(['publish'], $this->calls);
        $this->assertSame('running', $p['status']);
        $this->assertSame('migrate', $p['phase']);
        $this->assertSame(1, $p['done']);
        $this->assertFalse($p['busy']);

        // the migrations not done yet (the 2 done are not run again)
        $p = $this->step();
        $this->assertSame(['publish', 'm3', 'm4', 'm5'], $this->calls);
        $this->assertSame('seed', $p['phase']);
        $this->assertSame(5, $p['migrations_done']);
        $this->assertSame(5, $p['migrations_total']);
        // 1 (files) + 5 (migrations) of 3 + 5
        $this->assertSame(6, $p['done']);
        $this->assertSame(8, $p['total']);

        // the first data, then the end
        $p = $this->step();
        $this->assertSame('finish', $p['phase']);
        $this->assertSame(7, $p['done']);
        $p = $this->step();
        $this->assertSame(['publish', 'm3', 'm4', 'm5', 'seed', 'finish'], $this->calls);
        $this->assertSame('succeeded', $p['status']);
        $this->assertSame(100, $p['percent']);
        $this->assertSame(admin_url('initialize'), $p['redirect']);
        $this->assertFileDoesNotExist(InstallRunner::statePath());
        $this->assertTrue(InstalledState::isInstalled());

        // again after the end: the install pages are closed, nothing is run again
        $this->postJson(admin_base_path('install/step'))->assertNotFound();
        $this->assertCount(6, $this->calls);
    }

    public function testEachCallWorksForItsTimeOnly(): void
    {
        config(['exment.install_step_seconds' => 1]);
        $this->migrationSleep = 400000;
        $this->begin();
        $this->step();

        // 1 second: 3 migrations of 0.4 seconds at most (the one going on ends)
        $p = $this->step();
        $this->assertSame('migrate', $p['phase']);
        $this->assertGreaterThan(0, $p['migrations_done']);
        $this->assertLessThan(5, $p['migrations_done']);
        $first = $p['migrations_done'];

        // the next calls go on from there
        for ($i = 0; $i < 10 && $p['phase'] === 'migrate'; $i++) {
            $p = $this->step();
            $this->assertGreaterThanOrEqual($first, $p['migrations_done']);
        }
        $this->assertSame('seed', $p['phase']);
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], array_values(array_filter($this->calls, fn ($c) => str_starts_with($c, 'm'))));
    }

    public function testAFailureIsShownAndTheRetryGoesOnFromThere(): void
    {
        $this->failOnce = 'm3';
        $this->begin();
        $this->step();

        $p = $this->step();
        $this->assertSame('failed', $p['status']);
        $this->assertSame('migrate', $p['phase']);
        $this->assertSame(2, $p['migrations_done']);
        $this->assertStringContainsString('already exists (m3)', $p['error']);

        // the screen of the progress shows it (a reload)
        $this->get(admin_base_path('install'))->assertStatus(200)->assertSee('already exists (m3)');

        // without "retry": nothing more is run
        $p = $this->step();
        $this->assertSame('failed', $p['status']);
        $this->assertSame(['publish', 'm1', 'm2'], $this->calls);

        // "run again": from the migration that failed, the ones done are not run again
        $p = $this->step(['retry' => 1]);
        $this->assertSame('running', $p['status']);
        $this->assertNull($p['error']);
        $this->assertSame('seed', $p['phase']);
        $this->assertSame(['publish', 'm1', 'm2', 'm3', 'm4', 'm5'], $this->calls);
    }

    public function testTheLockKeepsASecondRunnerOut(): void
    {
        $this->begin();

        // another call works on the install (it holds the lock)
        $handle = fopen(InstallRunner::lockPath(), 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $p = $this->step();
            $this->assertTrue($p['busy']);
            $this->assertSame('running', $p['status']);
            $this->assertSame('publish', $p['phase']);
            $this->assertSame([], $this->calls);

            $status = $this->getJson(admin_base_path('install/status'))->assertStatus(200)->json();
            $this->assertTrue($status['busy']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // let go: the next call works
        $p = $this->step();
        $this->assertFalse($p['busy']);
        $this->assertSame(['publish'], $this->calls);
    }

    public function testTheStatusRunsNothing(): void
    {
        $this->begin();
        $this->step();

        $status = $this->getJson(admin_base_path('install/status'))->assertStatus(200)->json();
        $this->assertSame('running', $status['status']);
        $this->assertSame('migrate', $status['phase']);
        $this->assertFalse($status['busy']);
        $this->assertSame(1, $status['done']);
        $this->assertSame(['publish'], $this->calls);
    }

    public function testTheInstallPagesFollowTheRun(): void
    {
        $this->begin();

        // the progress, whatever the session says (a new one after the key is made)
        $this->get(admin_base_path('install'))->assertStatus(200)->assertSee(exmtrans('install.progress.header'));

        // the form sent again (a reload of the POST): the same install, its progress
        $this->post(admin_base_path('install'), ['ADMIN_ROUTE_PREFIX' => ''])->assertRedirect(admin_url('install'));
        $this->assertSame([], $this->calls);

        // the other pages of the site wait for the install
        $this->get(admin_base_path('auth/login'))->assertRedirect(admin_base_path('install'));

        // "reset" while the install goes on: nothing (by the link, or the form)
        $this->get(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
        $this->post(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
        $this->assertFileExists(InstallRunner::statePath());
    }

    public function testOnlyTheBrowserThatStartedItRunsTheInstall(): void
    {
        $this->failOnce = 'm2';
        $this->begin();

        // another browser (no cookie, or another token): nothing runs, nothing is told
        foreach ([null, str_repeat('0', 64)] as $other) {
            $this->asBrowser($other);
            $this->postJson(admin_base_path('install/step'))->assertStatus(403)->assertExactJson(['status' => 'in_progress']);
            $this->postJson(admin_base_path('install/step'), ['retry' => 1])->assertStatus(403);
            $this->getJson(admin_base_path('install/status'))->assertStatus(403)->assertExactJson(['status' => 'in_progress']);
            $this->post(admin_base_path('install/step'))->assertRedirect(admin_url('install'));
            $this->get(admin_base_path('install'))->assertStatus(200)
                ->assertSee(exmtrans('install.progress.other_browser'))
                ->assertDontSee('install-progress-bar', false);
        }
        $this->assertSame([], $this->calls);

        // the browser that started it
        $this->asBrowser($this->token);
        $this->step();
        $p = $this->step();
        $this->assertSame('failed', $p['status']);
        $this->assertSame(['publish', 'm1'], $this->calls);

        // the failure is not shown to another browser
        $this->asBrowser(null);
        $this->get(admin_base_path('install'))->assertStatus(200)->assertDontSee('already exists');
        $this->postJson(admin_base_path('install/step'), ['retry' => 1])->assertStatus(403);
        $this->assertSame(['publish', 'm1'], $this->calls);
    }

    public function testTheCookieOfTheInstall(): void
    {
        $cookie = InstallRunner::cookie('abc', '/exment');
        $this->assertSame(InstallRunner::COOKIE, $cookie->getName());
        $this->assertSame('abc', $cookie->getValue());
        $this->assertSame('/exment', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(0, $cookie->getExpiresTime(), 'until the browser is closed');
        $this->assertSame('/', InstallRunner::cookie('abc', '')->getPath());

        // not encrypted: the key of the application is made in the request that sets it
        $this->assertTrue((new \Exceedone\Exment\Middleware\EncryptCookies(app('encrypter')))->isDisabled(InstallRunner::COOKIE));

        // only the hash of the token is kept
        $token = InstallRunner::start();
        $this->assertIsString($token);
        $this->assertStringNotContainsString($token, (string) file_get_contents(InstallRunner::statePath()));
        $this->assertTrue(InstallRunner::isOwner($token));
        $this->assertFalse(InstallRunner::isOwner(null));
        $this->assertFalse(InstallRunner::isOwner(''));
        $this->assertFalse(InstallRunner::isOwner($token . 'x'));
        $this->assertNull(InstallRunner::start(), 'a second start keeps the install there');
        $this->assertTrue(InstallRunner::isOwner($token));
    }

    public function testResetOnlyAfterAFailureByTheBrowserThatStartedIt(): void
    {
        $this->failOnce = 'm1';
        $this->begin();
        $this->step();
        $this->assertSame('failed', $this->step()['status']);

        // by the link (GET): nothing
        $this->get(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
        $this->assertFileExists(InstallRunner::statePath());

        // by another browser: nothing
        $this->asBrowser(null);
        $this->post(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
        $this->assertFileExists(InstallRunner::statePath());

        // by the form of the browser that started it: forgotten, and its cookie too
        $this->asBrowser($this->token);
        $response = $this->post(admin_base_path('install/reset'))->assertRedirect(admin_url('install'));
        $this->assertFileDoesNotExist(InstallRunner::statePath());
        $forgotten = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === InstallRunner::COOKIE);
        $this->assertNotNull($forgotten);
        $this->assertTrue($forgotten->isCleared());
    }

    public function testTheErrorShownIsItsFirstLineWithTheMigration(): void
    {
        InstallRunner::$migrationRunner = function (string $name) {
            $this->ran[] = $name;
            if ($name === 'm2') {
                // as a migration that fails on MySQL: Laravel adds the connection and the SQL
                throw new \Illuminate\Database\QueryException(
                    'mysql',
                    'create table `custom_tables` (`id` int)',
                    [],
                    new \PDOException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'custom_tables' already exists\nmore lines")
                );
            }
        };
        $this->begin();
        $this->step();
        $p = $this->step();

        $this->assertSame('failed', $p['status']);
        $this->assertSame("m2: SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'custom_tables' already exists", $p['error']);
        $this->assertStringNotContainsString('Connection:', $p['error']);
        $this->assertStringNotContainsString('create table', $p['error']);

        $this->assertSame('SQLSTATE[HY000] [2002] Connection refused', InstallRunner::errorText("SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: db, Port: 3306, Database: exment, SQL: select 1)\n#0 trace"));
    }

    public function testTheFirstDataIsNeverPutOverASiteSetUp(): void
    {
        // the database of the tests is a site set up (System::initialized), the marker is not there
        $this->assertTrue(boolval(System::initialized()));
        $this->assertFalse(InstalledState::isInstalled());
        $menus = \DB::table(config('admin.database.menu_table'))->count();
        $this->assertGreaterThan(0, $menus);

        // the command itself
        $exitCode = \Artisan::call('exment:install-step', ['step' => InstallRunner::PHASE_SEED]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('set up already', \Artisan::output());

        // through the steps (the real command)
        InstallRunner::$phaseRunner = null;
        $this->begin();
        $state = json_decode((string) file_get_contents(InstallRunner::statePath()), true);
        $state['phase'] = InstallRunner::PHASE_SEED;
        file_put_contents(InstallRunner::statePath(), json_encode($state));
        $p = $this->step();
        $this->assertSame('failed', $p['status']);
        $this->assertSame('seed', $p['phase']);
        $this->assertStringContainsString('set up already', $p['error']);

        $this->assertSame($menus, \DB::table(config('admin.database.menu_table'))->count(), 'the menus are kept');
        System::clearCache();
        $this->assertTrue(boolval(System::initialized()));
    }

    public function testTheStatusDoesNotKeepARunnerOut(): void
    {
        $this->begin();

        // a reader of the status holds a shared lock: no runner is working
        $handle = fopen(InstallRunner::lockPath(), 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_SH | LOCK_NB));
        try {
            $this->assertFalse(InstallRunner::isBusy());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testAnInstalledSiteAnswersNotFoundOnEveryInstallPage(): void
    {
        // a state left from before, on a site installed since, and the cookie of that install
        $this->failOnce = 'm1';
        $this->begin();
        $this->step();
        $this->step();
        $this->restoreInstalledState();
        $this->useInstalledState(true);
        $this->assertFalse(InstallRunner::inProgress());
        $calls = $this->calls;

        $this->get(admin_base_path('install'))->assertNotFound();
        $this->post(admin_base_path('install'), ['ADMIN_ROUTE_PREFIX' => ''])->assertNotFound();
        $this->postJson(admin_base_path('install/step'))->assertNotFound();
        $this->postJson(admin_base_path('install/step'), ['retry' => 1])->assertNotFound();
        $this->getJson(admin_base_path('install/status'))->assertNotFound();
        $this->get(admin_base_path('install/reset'))->assertNotFound();
        $this->post(admin_base_path('install/reset'))->assertNotFound();
        $this->assertFileExists(InstallRunner::statePath());

        $runner = new InstallRunner();
        $p = $runner->step(true);
        $this->assertSame('succeeded', $p['status']);
        $this->assertSame($calls, $this->calls);
    }
}
