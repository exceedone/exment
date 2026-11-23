<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Security;
use Illuminate\Support\Facades\Http;

/**
 * The security check (ISS02833): each item from the settings, the site asked about
 * itself through a fake, the items a SaaS site sees, and the fixes.
 */
class SecurityCheckTest extends UnitTestBase
{
    /** @var array<string> */
    protected $tempDirs = [];

    protected function tearDown(): void
    {
        Security\SiteProbe::clear();
        foreach ($this->tempDirs as $dir) {
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testDebugEnvAndKey(): void
    {
        config(['app.debug' => true, 'app.env' => 'local', 'app.key' => '']);
        $this->assertSame(SystemRequireResult::NG, (new Security\AppDebugSecurity())->checkResult());
        $this->assertSame(SystemRequireResult::WARNING, (new Security\AppEnvSecurity())->checkResult());
        $this->assertSame(SystemRequireResult::NG, (new Security\AppKeySecurity())->checkResult());

        config(['app.debug' => false, 'app.env' => 'production', 'app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->assertSame(SystemRequireResult::OK, (new Security\AppDebugSecurity())->checkResult());
        $this->assertSame(SystemRequireResult::OK, (new Security\AppEnvSecurity())->checkResult());
        $this->assertSame(SystemRequireResult::OK, (new Security\AppKeySecurity())->checkResult());
        $this->assertFalse((new Security\AppDebugSecurity())->canFix(), 'nothing to fix');

        config(['app.key' => 'SomeRandomString']);
        $this->assertSame(SystemRequireResult::NG, (new Security\AppKeySecurity())->checkResult());
        // a new key is never made from the screen
        $this->assertFalse((new Security\AppKeySecurity())->canFix());
    }

    public function testTheSessionCookie(): void
    {
        config(['app.url' => 'http://example.test', 'session.http_only' => true, 'session.same_site' => 'lax', 'session.secure' => false]);
        // on http, secure is not asked for (it would stop the sign-in)
        $this->assertSame([], (new Security\SessionCookieSecurity())->wanted());

        config(['app.url' => 'https://example.test', 'session.same_site' => null]);
        $this->assertSame(['SESSION_SAME_SITE' => 'lax', 'SESSION_SECURE_COOKIE' => 'true'], (new Security\SessionCookieSecurity())->wanted());
        $this->assertSame(SystemRequireResult::WARNING, (new Security\SessionCookieSecurity())->checkResult());
    }

    public function testTheHeadersOfTheLoginPage(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake(['https://example.test/admin/auth/login' => Http::response('', 200, [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
        ])]);
        $check = new Security\HeadersSecurity();
        $this->assertSame(['referrer-policy', 'strict-transport-security'], $check->missing());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertStringContainsString('Referrer-Policy', strval($check->getFixText()));
    }

    public function testNoHstsAskedOnHttp(): void
    {
        config(['app.url' => 'http://example.test']);
        Http::fake(['http://example.test/admin/auth/login' => Http::response('', 200, [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ])]);
        $this->assertSame([], (new Security\HeadersSecurity())->missing());
    }

    public function testWhatMustNotBeServed(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake([
            'https://example.test/.env' => Http::response('APP_KEY=', 200),
            'https://example.test/*' => Http::response('', 404),
        ]);
        $check = new Security\ExposedPathSecurity();
        $this->assertSame(['.env'], $check->exposed());
        $this->assertSame(SystemRequireResult::NG, $check->checkResult());
        $this->assertTrue($check->isHigh());
    }

    public function testBasicAuthInFrontOfTheSiteIsNotChecked(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake(['https://example.test/*' => Http::response('', 401, ['WWW-Authenticate' => 'Basic realm="site"'])]);
        // not "headers missing", not "nothing served"
        $headers = new Security\HeadersSecurity();
        $this->assertNull($headers->missing());
        $this->assertSame(exmtrans('security_check.not_checked'), $headers->getResultText());
        $exposed = new Security\ExposedPathSecurity();
        $this->assertNull($exposed->exposed());
        $this->assertSame(SystemRequireResult::WARNING, $exposed->checkResult());
        $this->assertFalse($exposed->checked());
    }

    public function testARedirectOfTheLoginPageIsNotChecked(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake(['https://example.test/*' => Http::response('', 302, ['Location' => 'https://example.test/other'])]);
        $this->assertNull((new Security\HeadersSecurity())->missing());
    }

    public function testNothingServedWhenRefused(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake(['https://example.test/*' => Http::response('', 403)]);
        $this->assertSame([], (new Security\ExposedPathSecurity())->exposed());
    }

    public function testASiteThatCannotReachItselfIsNotOk(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('no route');
        });
        $exposed = new Security\ExposedPathSecurity();
        $this->assertNull($exposed->exposed());
        $this->assertFalse($exposed->checked());
        $this->assertNotSame(SystemRequireResult::OK, $exposed->checkResult());
        $this->assertNull((new Security\HeadersSecurity())->missing());
        // unknown, said so: not the wording of a problem, and how to look by hand
        $this->assertSame(exmtrans('security_check.item.exposed_path.unknown'), $exposed->getMessage());
        $this->assertNotSame(exmtrans('security_check.item.exposed_path.warning'), $exposed->getMessage());
        $this->assertStringContainsString('https://example.test/.env', strval($exposed->getFixText()));
        $headers = new Security\HeadersSecurity();
        $this->assertSame(exmtrans('security_check.item.headers.unknown'), $headers->getMessage());
        $this->assertStringContainsString('https://example.test/admin/auth/login', strval($headers->getFixText()));
        $this->assertFalse($exposed->isHigh());
    }

    public function testStorageOpenToEveryone(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('no POSIX permissions here');
        }
        $dir = sys_get_temp_dir() . '/exment_security_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;
        chmod($dir, 0777);

        $check = new Security\FilePermissionSecurity();
        $check->storage = $dir;
        $this->assertTrue($check->storageOpen());

        chmod($dir, 0750);
        $this->assertFalse($check->storageOpen());
    }

    public function testLogsAndSessionsReadableByOthers(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('no POSIX permissions here');
        }
        $dir = sys_get_temp_dir() . '/exment_security_' . uniqid('', true);
        mkdir($dir . '/logs', 0750, true);
        mkdir($dir . '/framework/sessions', 0750, true);
        chmod($dir, 0750);
        chmod($dir . '/logs', 0750);
        chmod($dir . '/framework/sessions', 0750);
        file_put_contents($dir . '/framework/sessions/abc', 'a session');
        chmod($dir . '/framework/sessions/abc', 0600);
        file_put_contents($dir . '/logs/laravel.log', 'a log');
        chmod($dir . '/logs/laravel.log', 0644);

        $check = new Security\FilePermissionSecurity();
        $check->files = new FilePermissionTuningNone();
        $check->storage = $dir;
        $this->assertSame(['logs/*' => '644'], $check->storageReadable());
        $this->assertSame(SystemRequireResult::WARNING, $check->checkResult());
        $this->assertStringContainsString('storage/logs/* (644)', strval($check->getResultText()));
        $this->assertStringContainsString('chmod -R o-rwx storage', strval($check->getFixText()));

        // a folder open to the others (755) with the files closed
        chmod($dir . '/logs/laravel.log', 0640);
        chmod($dir . '/framework/sessions', 0755);
        $this->assertSame(['framework/sessions' => '755'], $check->storageReadable());

        chmod($dir . '/framework/sessions', 0750);
        $this->assertSame([], $check->storageReadable());
        $this->assertSame(SystemRequireResult::OK, $check->checkResult());

        @unlink($dir . '/framework/sessions/abc');
        @unlink($dir . '/logs/laravel.log');
        @rmdir($dir . '/framework/sessions');
        @rmdir($dir . '/framework');
        @rmdir($dir . '/logs');
        @rmdir($dir);
    }

    public function testASaasSiteSeesOnlyWhatItCanFix(): void
    {
        config(['exment.features_locked' => '']);
        $all = array_keys((new Security\SecurityCheck())->items());
        $this->assertContains('file_permission', $all);
        $this->assertContains('admin_password', $all);

        config(['exment.features_locked' => 'tuning,studio']);
        $this->assertFalse(Security\SecurityCheck::serverItemsShown());
        $this->assertSame(['admin_password', 'two_factor'], array_keys((new Security\SecurityCheck())->items()));
    }

    public function testOnlyAHighRiskOpensThePageAfterTheInitialize(): void
    {
        // a fresh site without two-step sign-in: the dashboard, told of it there
        $low = new Security\SecurityCheck([new SecurityItemRecorder('two_factor', false)]);
        $this->assertSame(['page' => false, 'high' => 0, 'other' => 1], $low->afterInitialize());

        $high = new Security\SecurityCheck([new SecurityItemRecorder('two_factor', false), new SecurityItemRecorder('app_debug', true, true)]);
        $this->assertSame(['page' => true, 'high' => 1, 'other' => 1], $high->afterInitialize());
        $this->assertSame(['app_debug'], array_keys($high->highProblems()));
    }

    public function testWhatIsAHighRisk(): void
    {
        config(['app.debug' => true]);
        $this->assertTrue((new Security\AppDebugSecurity())->isHigh());
        config(['app.debug' => false]);
        $this->assertFalse((new Security\AppDebugSecurity())->isHigh());

        $this->assertFalse((new Security\TwoFactorSecurity())->isHigh());
        $this->assertFalse((new Security\HeadersSecurity())->isHigh());

        // .env or .git served is high; vendor alone is a problem, not high
        config(['app.url' => 'https://example.test']);
        Http::fake([
            'https://example.test/vendor/*' => Http::response('{}', 200),
            'https://example.test/*' => Http::response('', 404),
        ]);
        $vendor = new Security\ExposedPathSecurity();
        $this->assertSame(['vendor'], $vendor->exposed());
        $this->assertFalse($vendor->isHigh());
    }

    public function testAServedGitIsAHighRisk(): void
    {
        config(['app.url' => 'https://example.test']);
        Http::fake([
            'https://example.test/.git/*' => Http::response('ref: refs/heads/main', 200),
            'https://example.test/*' => Http::response('', 404),
        ]);
        $check = new Security\ExposedPathSecurity();
        $this->assertSame(['.git'], $check->exposed());
        $this->assertTrue($check->isHigh());
    }

    public function testOnlyWhatCanBeFixedIsFixed(): void
    {
        $fixable = new SecurityItemRecorder('fixable', true);
        $advice = new SecurityItemRecorder('advice', false);
        $check = new Security\SecurityCheck([$fixable, $advice]);

        $this->assertFalse($check->fix('unknown'));
        $this->assertFalse($check->fix('advice'));
        $this->assertSame(0, $advice->fixed);
        $this->assertTrue($check->fix('fixable'));
        $this->assertSame(1, $fixable->fixed);
        $this->assertSame(['fixable', 'advice'], array_keys($check->problems()));
    }
}

/**
 * An item that is never right, fixable or not, counting the fixes.
 */
class SecurityItemRecorder extends Security\SecurityBase
{
    /** @var string */
    protected $name;

    /** @var bool */
    protected $fixable;

    /** @var int */
    public $fixed = 0;

    /** @var bool */
    protected $high;

    public function __construct(string $name, bool $fixable, bool $high = false)
    {
        $this->name = $name;
        $this->fixable = $fixable;
        $this->high = $high;
    }

    public function isHigh(): bool
    {
        return $this->high;
    }

    protected function key(): string
    {
        return $this->name;
    }

    public function scope(): string
    {
        return static::SCOPE_SITE;
    }

    public function checkResult(): string
    {
        return SystemRequireResult::WARNING;
    }

    public function canFix(): bool
    {
        return $this->fixable;
    }

    public function fix(): void
    {
        $this->fixed++;
    }
}

/** The tuning's file check with nothing open (.env and the config cache are not looked at). */
class FilePermissionTuningNone extends \Exceedone\Exment\Services\SystemRequire\Tuning\FilePermissionTuning
{
    /**
     * @return array<string, string>
     */
    public function openFiles(): array
    {
        return [];
    }
}
