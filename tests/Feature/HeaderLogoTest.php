<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The logo at the top left: the site's own, or the Exment logo when it has none.
 */
class HeaderLogoTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testWithoutALogoOfItsOwnTheSiteShowsTheExmentLogo(): void
    {
        \DB::table(System::getTableName())->whereIn('system_name', ['site_logo', 'site_logo_mini'])->delete();
        System::clearCache();
        Initialize::initializeConfig();

        if (!is_file(public_path(Initialize::DEFAULT_LOGO))) {
            // public/images not published: the site name, as before
            $this->assertStringNotContainsString('<img', strval(config('admin.logo')));
            return;
        }
        $this->assertStringContainsString('<img src="' . asset(Initialize::defaultLogo()) . '"', strval(config('admin.logo')));
        $this->assertStringContainsString('<img src="' . asset(Initialize::DEFAULT_LOGO_MINI) . '"', strval(config('admin.logo-mini')));
        // no plate behind it (modern.css)
        $this->assertStringContainsString('class="' . Initialize::DEFAULT_LOGO_CLASS . '"', strval(config('admin.logo')));
        $this->assertStringContainsString('class="' . Initialize::DEFAULT_LOGO_CLASS . '"', strval(config('admin.logo-mini')));
    }

    public function testTheDefaultLogoFitsTheBar(): void
    {
        // the light bar of the modern design: the colored letters
        System::ui_design('modern');
        System::clearCache();
        if (\Exment::getUiDesignForced() !== null) {
            $this->markTestSkipped('the design is fixed by the server (EXMENT_UI_DESIGN)');
        }
        $this->assertSame(Initialize::DEFAULT_LOGO, Initialize::defaultLogo());

        // the classic design: the colored bar of a blue skin takes the light letters,
        // the white bar of a black skin the colored ones
        System::ui_design('classic');
        System::site_skin('skin-blue');
        System::clearCache();
        $expected = is_file(public_path(Initialize::DEFAULT_LOGO_DARK)) ? Initialize::DEFAULT_LOGO_DARK : Initialize::DEFAULT_LOGO;
        $this->assertSame($expected, Initialize::defaultLogo());

        System::site_skin('skin-black-light');
        System::clearCache();
        $this->assertSame(Initialize::DEFAULT_LOGO, Initialize::defaultLogo());
    }

    public function testTheSiteKeepsItsOwnLogo(): void
    {
        // uploaded as on the setting screen (the setter stores the file and keeps its path)
        Storage::fake(config('admin.upload.disk'));
        System::site_logo(UploadedFile::fake()->image('site_logo_test.png', 200, 40));
        System::site_logo_mini(UploadedFile::fake()->image('site_logo_mini_test.png', 40, 40));
        System::clearCache();
        $logo = strval(System::site_logo());
        $mini = strval(System::site_logo_mini());
        $this->assertNotSame('', $logo);
        $this->assertNotSame('', $mini);
        Initialize::initializeConfig();

        $this->assertStringContainsString($logo, strval(config('admin.logo')));
        $this->assertStringNotContainsString(Initialize::DEFAULT_LOGO, strval(config('admin.logo')));
        $this->assertStringContainsString($mini, strval(config('admin.logo-mini')));
    }
}
