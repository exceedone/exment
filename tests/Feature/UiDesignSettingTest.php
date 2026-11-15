<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The screen design ("modern" or "classic").
 *
 * "modern" loads vendor/exment/css/modern.css on the login page and on the
 * admin pages; "classic" does not. The design comes from the system setting
 * "ui_design" (default "modern"), unless the server fixes it with
 * config exment.ui_design (.env EXMENT_UI_DESIGN), which then wins.
 */
class UiDesignSettingTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const STYLESHEET = 'vendor/exment/css/modern.css';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // the server does not fix the design, whatever the .env of the test machine says
        config(['exment.ui_design' => null]);
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testModernIsTheDefault(): void
    {
        $this->assertNull(\Exment::getUiDesignForced());
        $this->assertSame('modern', \Exment::getUiDesign());
        $this->assertTrue(\Exment::isModernUi());

        $this->assertStringContainsString(self::STYLESHEET, $this->loginPage());
        $this->assertStringContainsString(self::STYLESHEET, $this->adminPage());
    }

    public function testClassicSettingDoesNotLoadTheStylesheet(): void
    {
        $this->setDesign('classic');

        $this->assertSame('classic', \Exment::getUiDesign());
        $this->assertFalse(\Exment::isModernUi());

        $this->assertStringNotContainsString(self::STYLESHEET, $this->loginPage());
        $this->assertStringNotContainsString(self::STYLESHEET, $this->adminPage());
    }

    public function testModernSettingLoadsTheStylesheet(): void
    {
        $this->setDesign('modern');

        $this->assertStringContainsString(self::STYLESHEET, $this->loginPage());
        $this->assertStringContainsString(self::STYLESHEET, $this->adminPage());
    }

    public function testServerClassicWinsOverTheModernSetting(): void
    {
        $this->setDesign('modern');
        config(['exment.ui_design' => 'classic']);

        $this->assertSame('classic', \Exment::getUiDesignForced());
        $this->assertFalse(\Exment::isModernUi());

        $this->assertStringNotContainsString(self::STYLESHEET, $this->loginPage());
        $this->assertStringNotContainsString(self::STYLESHEET, $this->adminPage());
    }

    public function testServerModernWinsOverTheClassicSetting(): void
    {
        $this->setDesign('classic');
        config(['exment.ui_design' => 'modern']);

        $this->assertSame('modern', \Exment::getUiDesignForced());
        $this->assertStringContainsString(self::STYLESHEET, $this->loginPage());
    }

    public function testAnUnknownServerValueIsIgnored(): void
    {
        $this->setDesign('classic');

        foreach (['', 'true', 'dark', 1, true] as $value) {
            config(['exment.ui_design' => $value]);
            $this->assertNull(\Exment::getUiDesignForced());
            $this->assertSame('classic', \Exment::getUiDesign());
        }

        // upper case and spaces around the value are accepted
        config(['exment.ui_design' => ' Modern ']);
        $this->assertSame('modern', \Exment::getUiDesignForced());
    }

    public function testAnUnknownStoredValueFallsBackToModern(): void
    {
        $this->setDesign('dark');

        $this->assertSame('modern', \Exment::getUiDesign());
    }

    public function testTheSystemSettingPageOffersTheChoice(): void
    {
        $this->be($this->adminUser());

        $content = (string) $this->get(admin_url('system'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="ui_design"', $content);
        $this->assertStringContainsString(exmtrans('system.ui_design_options.classic'), $content);
        $this->assertStringContainsString(exmtrans('system.ui_design_options.modern'), $content);
    }

    public function testTheSystemSettingPageShowsTheServerValueReadOnly(): void
    {
        config(['exment.ui_design' => 'classic']);
        $this->be($this->adminUser());

        $content = (string) $this->get(admin_url('system'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();

        // no field to post, and the design in effect with the note
        $this->assertStringNotContainsString('name="ui_design"', $content);
        $this->assertStringContainsString(
            exmtrans('system.ui_design_forced', exmtrans('system.ui_design_options.classic')),
            $content
        );
    }

    private function setDesign(string $design): void
    {
        System::ui_design($design);
        System::clearCache();
    }

    private function adminUser(): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        return $user;
    }

    /**
     * The login page, as a guest. Call it before adminPage(), which logs in.
     */
    private function loginPage(): string
    {
        return (string) $this->get(admin_url('auth/login'))->assertOk()->getContent();
    }

    /**
     * The dashboard as a full page (not pjax), as the admin: only a full
     * page prints the stylesheet list.
     */
    private function adminPage(): string
    {
        // every request starts with an empty asset list in production
        $this->resetAdminAssets();
        $this->be($this->adminUser());

        return (string) $this->get(admin_url(''))->assertOk()->getContent();
    }

    private function resetAdminAssets(): void
    {
        foreach (['css', 'csslast'] as $name) {
            $property = new \ReflectionProperty(\ExmentAdminCore\Admin\Admin::class, $name);
            $property->setValue(null, $property->getDefaultValue());
        }
    }
}
