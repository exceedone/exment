<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The site on https (APP_URL, or the request that opened the page).
 */
class HttpsSecurity extends SecurityBase
{
    protected function key(): string
    {
        return 'https';
    }

    public static function isHttps(): bool
    {
        $url = strval(config('app.url'));
        if (stripos($url, 'https://') === 0) {
            return true;
        }
        try {
            return app()->bound('request') && request()->isSecure();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function checkResult(): string
    {
        return static::isHttps() ? SystemRequireResult::OK : SystemRequireResult::NG;
    }

    public function getResultText(): ?string
    {
        return strval(config('app.url')) ?: SiteProbe::base();
    }

    public function getFixText(): ?string
    {
        return exmtrans('security_check.item.https.fix');
    }
}
