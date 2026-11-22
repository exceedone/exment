<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;

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
        $this->storeLogo('site_logo', null);
        $this->storeLogo('site_logo_mini', null);
        System::clearCache();
        Initialize::initializeConfig();

        if (!is_file(public_path(Initialize::DEFAULT_LOGO))) {
            // public/images not published: the site name, as before
            $this->assertStringNotContainsString('<img', strval(config('admin.logo')));
            return;
        }
        $this->assertStringContainsString('<img src="' . asset(Initialize::DEFAULT_LOGO) . '"', strval(config('admin.logo')));
        $this->assertStringContainsString('<img src="' . asset(Initialize::DEFAULT_LOGO_MINI) . '"', strval(config('admin.logo-mini')));
    }

    public function testTheSiteKeepsItsOwnLogo(): void
    {
        // stored as the setting screen stores an upload (the setter takes an UploadedFile)
        $this->storeLogo('site_logo', 'system/site_logo_test.png');
        $this->storeLogo('site_logo_mini', 'system/site_logo_mini_test.png');
        System::clearCache();
        Initialize::initializeConfig();

        $this->assertStringContainsString('site_logo_test.png', strval(config('admin.logo')));
        $this->assertStringNotContainsString(Initialize::DEFAULT_LOGO, strval(config('admin.logo')));
        $this->assertStringContainsString('site_logo_mini_test.png', strval(config('admin.logo-mini')));
    }

    private function storeLogo(string $name, ?string $path): void
    {
        \DB::table(System::getTableName())->updateOrInsert(['system_name' => $name], ['system_value' => $path]);
    }
}
