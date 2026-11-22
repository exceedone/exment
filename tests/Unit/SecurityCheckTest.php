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

        Security\SiteProbe::clear();
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

    public function __construct(string $name, bool $fixable)
    {
        $this->name = $name;
        $this->fixable = $fixable;
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
