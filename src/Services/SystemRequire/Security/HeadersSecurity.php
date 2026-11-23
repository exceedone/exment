<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The headers for safety on the site's pages (read from the login page).
 * HSTS only when the site is on https.
 */
class HeadersSecurity extends SecurityBase
{
    /** header => the line to add (nginx) */
    public const HEADERS = [
        'x-frame-options' => 'add_header X-Frame-Options "SAMEORIGIN" always;',
        'x-content-type-options' => 'add_header X-Content-Type-Options "nosniff" always;',
        'referrer-policy' => 'add_header Referrer-Policy "strict-origin-when-cross-origin" always;',
        'strict-transport-security' => 'add_header Strict-Transport-Security "max-age=31536000" always;',
    ];

    protected function key(): string
    {
        return 'headers';
    }

    /**
     * @return array<string>|null the headers missing, null when the page did not answer
     */
    public function missing(): ?array
    {
        $page = SiteProbe::get('admin/auth/login');
        if (!isset($page)) {
            return null;
        }
        // only the login page itself tells: a 401 (Basic auth in front of the site), a
        // redirect or an error is the answer of something else ("could not check")
        if ($page['status'] !== 200) {
            return null;
        }
        $missing = [];
        foreach (array_keys(static::HEADERS) as $header) {
            if ($header === 'strict-transport-security' && !HttpsSecurity::isHttps()) {
                continue;
            }
            if (!isset($page['headers'][$header]) || trim($page['headers'][$header]) === '') {
                $missing[] = strval($header);
            }
        }
        return $missing;
    }

    public function checked(): bool
    {
        return $this->missing() !== null;
    }

    public function checkResult(): string
    {
        $missing = $this->missing();
        return is_array($missing) && count($missing) === 0 ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        $missing = $this->missing();
        if ($missing === null) {
            return exmtrans('security_check.not_checked');
        }
        return count($missing) === 0 ? exmtrans('security_check.result_ok') : exmtrans('security_check.missing', ['items' => implode(', ', $missing)]);
    }

    public function getFixText(): ?string
    {
        if (!$this->checked()) {
            return SiteProbe::base() . '/admin/auth/login';
        }
        $missing = $this->missing();
        if (empty($missing)) {
            return null;
        }
        return "# nginx (server { ... })\n" . implode("\n", array_map(function ($header) {
            return static::HEADERS[$header];
        }, $missing));
    }
}
