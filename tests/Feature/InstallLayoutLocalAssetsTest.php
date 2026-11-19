<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemLocale;
use Exceedone\Exment\Enums\Timezone;
use Illuminate\Support\ViewErrorBag;

/**
 * The install wizard must load its CSS/JS from the Exment server itself.
 *
 * Bug: install/layout.blade.php loaded Bootstrap 5 and Font Awesome from public CDNs
 * (cdn.jsdelivr.net, cdnjs.cloudflare.com). On a server without internet access
 * (intranet, CDN blocked) the install screens rendered unstyled. v6 used local assets.
 * Fix: the layout uses the assets published by exment-admin-core (vendor/open-admin).
 */
class InstallLayoutLocalAssetsTest extends FeatureTestBase
{
    /**
     * Every stylesheet and script of the install screen is served by the application.
     */
    public function testInstallScreenLoadsNoExternalAssets(): void
    {
        $html = $this->renderLangScreen();
        $appHost = parse_url(admin_asset('/'), PHP_URL_HOST);

        preg_match_all('/<(?:link|script)\b[^>]*\b(?:href|src)="([^"]+)"/i', $html, $matches);
        $this->assertNotEmpty($matches[1], 'The install screen must load stylesheets and scripts');

        foreach ($matches[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->assertTrue(
                $host === null || $host === $appHost,
                "The install screen must not load assets from another host: {$url}"
            );
        }
    }

    /**
     * Bootstrap 5 (css + js) and Font Awesome come from exment-admin-core's published assets.
     */
    public function testInstallScreenUsesAdminCoreBootstrapAndFontAwesome(): void
    {
        $html = $this->renderLangScreen();

        foreach ([
            '/vendor/open-admin/AdminLTE4/css/bootstrap.min.css',
            '/vendor/open-admin/font-awesome/css/all.min.css',
            '/vendor/open-admin/font-awesome/css/v4-shims.min.css',
            '/vendor/open-admin/open-admin/js/bootstrap.bundle.min.js',
        ] as $path) {
            $this->assertStringContainsString(admin_asset($path), $html);
        }
    }

    /**
     * Render the first wizard step the way LangForm::index() does, without running
     * exment:publish (LangForm publishes the static files before rendering).
     */
    private function renderLangScreen(): string
    {
        view()->share('errors', new ViewErrorBag());

        return view('exment::install.lang', [
            'locale_options' => SystemLocale::getLocaleOptions(),
            'timezone_options' => Timezone::TIMEZONE,
            'locale_default' => 'ja',
            'timezone_default' => 'Asia_Tokyo',
        ])->render();
    }
}
