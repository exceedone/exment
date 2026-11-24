<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The top of the login page: the site's own logo, or the Exment logo of the side menu when
 * the site has none (no longer only the text "Exment"), and the icon of the browser tab.
 */
class LoginPageLogoTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        \DB::table(System::getTableName())->whereIn('system_name', ['site_logo', 'site_favicon', 'login_page_image', 'login_background_color'])->delete();
        System::clearCache();
        // the favicon of a site set by an earlier test stays in admin-core (a static value, typed
        // string there, so it is emptied through reflection: null means "no favicon")
        (new \ReflectionProperty(\ExmentAdminCore\Admin\Admin::class, 'favicon'))->setValue(null, null);
    }

    protected function published(): bool
    {
        return is_file(public_path(Initialize::DEFAULT_LOGO));
    }

    public function testWithoutALogoOfItsOwnTheLoginPageShowsTheExmentLogo(): void
    {
        System::site_name('Exment');
        System::clearCache();
        $page = $this->get(admin_url('auth/login'))->assertOk();
        if (!$this->published()) {
            // public/images not published: the site name as text, as before
            $page->assertDontSee('class="exment-login-logo"', false);
            return;
        }
        $page->assertSee('<img src="' . asset(Initialize::DEFAULT_LOGO) . '" alt="Exment" class="exment-login-logo"', false);
        // the site name is "Exment" itself: not twice
        $page->assertDontSee('class="exment-login-site-name"', false);
    }

    public function testAnotherSiteNameIsShownUnderTheLogo(): void
    {
        System::site_name('Claude test site');
        System::clearCache();
        $page = $this->get(admin_url('auth/login'))->assertOk();
        if (!$this->published()) {
            $page->assertSee('<b>Claude test site</b>', false);
            return;
        }
        $page->assertSee('class="exment-login-logo"', false);
        $page->assertSee('<b class="exment-login-site-name">Claude test site</b>', false);
    }

    public function testADarkPageColorTakesTheLightLetters(): void
    {
        if (!$this->published() || !is_file(public_path(Initialize::DEFAULT_LOGO_DARK))) {
            $this->markTestSkipped('public/images is not published');
        }
        $this->assertSame(asset(Initialize::DEFAULT_LOGO), Initialize::loginDefaultLogo('#d2d6de'));
        $this->assertSame(asset(Initialize::DEFAULT_LOGO), Initialize::loginDefaultLogo(null));
        $this->assertSame(asset(Initialize::DEFAULT_LOGO_DARK), Initialize::loginDefaultLogo('#1f2937'));
        $this->assertSame(asset(Initialize::DEFAULT_LOGO_DARK), Initialize::loginDefaultLogo('#000'));
        // an image covers the color: the colored letters
        $this->assertSame(asset(Initialize::DEFAULT_LOGO), Initialize::loginDefaultLogo('#1f2937', true));

        System::login_background_color('#1f2937');
        System::clearCache();
        System::site_name('Claude test site');
        System::clearCache();
        $this->get(admin_url('auth/login'))->assertOk()
            ->assertSee('<img src="' . asset(Initialize::DEFAULT_LOGO_DARK) . '"', false)
            // the site name under it in light letters too
            ->assertSee('class="exment-login-on-dark"', false);
    }

    public function testTheSiteKeepsItsOwnLogo(): void
    {
        Storage::fake(config('admin.upload.disk'));
        System::site_logo(UploadedFile::fake()->image('site_logo_login.png', 200, 40));
        System::clearCache();
        $this->get(admin_url('auth/login'))->assertOk()
            ->assertSee('<img src="' . admin_url('auth/file/header') . '"', false)
            ->assertDontSee('class="exment-login-logo"', false);
    }

    public function testWithoutAFaviconOfItsOwnTheTabShowsTheExmentIcon(): void
    {
        $page = $this->get(admin_url('auth/login'))->assertOk();
        if (!is_file(public_path('images/exment_favicon.png'))) {
            $page->assertDontSee('images/exment_favicon.png', false);
            return;
        }
        $page->assertSee('<link rel="icon" href="' . asset('images/exment_favicon.png') . '"', false);
        $page->assertSee('<link rel="icon" href="' . asset(Initialize::DEFAULT_LOGO_MINI) . '" type="image/svg+xml"', false);
    }

    public function testThePageTellsItsLanguage(): void
    {
        // as the other pages: the language of the page for the browser and the screen readers
        $this->get(admin_url('auth/login'))->assertOk()
            ->assertSee('<html lang="' . str_replace('_', '-', app()->getLocale()) . '">', false);
    }
}
