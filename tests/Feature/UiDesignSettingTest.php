<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
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

    /**
     * The installer does not ask for the design: a new installation starts
     * with the default one.
     */
    public function testTheInstallerFormDoesNotOfferTheChoice(): void
    {
        System::initialized(0);
        System::clearCache();

        $content = (string) $this->get(admin_url('initialize'))->assertOk()->getContent();

        $this->assertStringContainsString('name="site_layout"', $content);
        $this->assertStringNotContainsString('name="ui_design"', $content);
        $this->assertSame('modern', \Exment::getUiDesign());
    }

    /**
     * The cells of the data list carry the column TYPE as a class, next to
     * the class made from the column NAME. The stylesheet needs it to
     * right-align numbers: a column named "price" is not found by its name.
     */
    public function testTheDataListMarksTheCellsWithTheColumnType(): void
    {
        $this->be($this->adminUser());

        $content = (string) $this->get(admin_url('data/custom_value_edit_all'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();

        // "index_text" and "multiples_of_3" are column names; text and yesno are their types
        $this->assertMatchesRegularExpression('/class="column-index_text exm-coltype-text[ "]/', $content);
        $this->assertMatchesRegularExpression('/class="column-multiples_of_3 exm-coltype-yesno[ "]/', $content);
        $this->assertMatchesRegularExpression('/class="column-integer exm-coltype-integer[ "]/', $content);
        // a system column (ID) has no type class
        $this->assertDoesNotMatchRegularExpression('/class="column-id exm-coltype-/', $content);
    }

    /**
     * The modern login page tells the stylesheet the site skin, so the
     * login button takes the skin's accent color. It is a data attribute,
     * never a skin class: that would switch on the admin page rules.
     */
    public function testTheModernLoginPageCarriesTheSiteSkin(): void
    {
        $this->setDesign('modern');
        System::site_skin('skin-red-light');
        System::clearCache();

        $content = $this->loginPage();

        $this->assertStringContainsString('<body class="hold-transition login-page" data-exm-skin="skin-red-light">', $content);
    }

    public function testTheClassicLoginPageDoesNotCarryTheSiteSkin(): void
    {
        $this->setDesign('classic');
        System::site_skin('skin-red-light');
        System::clearCache();

        $content = $this->loginPage();

        $this->assertStringContainsString('<body class="hold-transition login-page">', $content);
        $this->assertStringNotContainsString('data-exm-skin', $content);
    }

    /**
     * Every skin of the system setting except the two blue ones has its
     * accent in the stylesheet, for the admin pages (class) and for the
     * login pages (data attribute).
     */
    public function testTheStylesheetHasAnAccentForEverySkin(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/vendor/exment/css/modern.css');

        foreach (Define::SYSTEM_SKIN as $skin) {
            $family = preg_replace('/-light$/', '', $skin);
            if ($family === 'skin-blue') {
                continue;
            }
            $this->assertStringContainsString('body[class*="' . $family . '"],', $css);
            $this->assertStringContainsString('body[data-exm-skin^="' . $family . '"] {', $css);
        }
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
