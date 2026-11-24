<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SystemInfo;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The versions of the system setting (ISS02828): always shown, Exment, PHP, the web
 * server, the database and the OS, with a copy button; only Exment where Tuning is kept
 * from the sites (a SaaS). The system requirements moved to the tuning page.
 */
class SystemVersionInfoTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.features_locked' => '']);
    }

    protected function tearDown(): void
    {
        config(['exment.features_locked' => '']);
        Features::reset();
        System::clearCache();
        parent::tearDown();
    }

    public function testTheItems(): void
    {
        $items = SystemInfo::items();
        $this->assertSame(['Exment', 'PHP', exmtrans('system.version_info.web_server'), exmtrans('system.version_info.database'), 'OS'], array_keys($items));
        $this->assertSame(PHP_VERSION, $items['PHP']);
        // the database answers its driver and version
        $this->assertStringStartsWith(\Illuminate\Support\Facades\DB::connection()->getDriverName() . ' ', $items[exmtrans('system.version_info.database')]);
        foreach ($items as $value) {
            $this->assertNotSame('', trim($value));
        }

        // one "label: value" a line
        $lines = explode("\n", SystemInfo::text());
        $this->assertCount(5, $lines);
        $this->assertSame('PHP: ' . PHP_VERSION, $lines[1]);
    }

    public function testAWebServerThatDoesNotSayIsUnknown(): void
    {
        request()->server->remove('SERVER_SOFTWARE');
        $this->assertSame(exmtrans('system.version_info.unknown'), SystemInfo::webServer());

        request()->server->set('SERVER_SOFTWARE', 'nginx/1.27.3');
        $this->assertSame('nginx/1.27.3', SystemInfo::webServer());
    }

    public function testOnlyTheExmentVersionWhereTuningIsLocked(): void
    {
        config(['exment.features_locked' => 'tuning']);

        $this->assertFalse(SystemInfo::showsServer());
        $this->assertSame(['Exment'], array_keys(SystemInfo::items()));
        $this->assertStringNotContainsString(PHP_VERSION, SystemInfo::text());

        $content = $this->systemPage();
        $this->assertStringContainsString('data-version-info="Exment"', $content);
        $this->assertStringNotContainsString('data-version-info="PHP"', $content);
        $this->assertStringNotContainsString('data-version-info="OS"', $content);
        // no link to the tuning page the site cannot open
        $this->assertStringNotContainsString(admin_url('tuning'), $content);
    }

    public function testTheSystemPageShowsTheVersionsWithACopyButton(): void
    {
        // shown also when the server does not ask outside for the latest version
        System::outside_api(false);

        $content = $this->systemPage();
        $this->assertStringContainsString(exmtrans('system.version_header'), $content);
        $this->assertStringContainsString('data-version-info="PHP"', $content);
        $this->assertStringContainsString(e(PHP_VERSION), $content);
        $this->assertStringContainsString('id="exm-version-info-copy"', $content);
        $this->assertStringContainsString(e(SystemInfo::text()), $content);
        $this->assertStringContainsString(admin_url('tuning'), $content);

        // the system requirements are on the tuning page now
        $this->assertStringNotContainsString('require_item_header', $content);
        $this->assertStringNotContainsString(exmtrans('system_require.type.memory.label'), $content);
    }

    public function testTheTuningPageHasTheSystemRequirements(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="tuning-system-require"', $content);
        $this->assertStringContainsString(exmtrans('install.system_require.header'), $content);
        $this->assertStringContainsString(exmtrans('system_require.type.memory.label'), $content);
    }

    public function testOnlyTheSystemAdministratorsSeeIt(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(admin_url('system'))
            ->assertForbidden();
    }

    private function systemPage(): string
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        return (string) $this->get(admin_url('system'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();
    }
}
