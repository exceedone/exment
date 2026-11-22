<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The session cookie: httponly, samesite lax or strict, and secure on an https site.
 * Secure is fixed only on https: on http it would stop every sign-in.
 */
class SessionCookieSecurity extends SecurityBase
{
    use EnvFixTrait;

    protected function key(): string
    {
        return 'session_cookie';
    }

    /**
     * @return array<string, string> .env key => the value to set, for what is not right
     */
    public function wanted(): array
    {
        $wanted = [];
        if (!boolval(config('session.http_only', true))) {
            $wanted['SESSION_HTTP_ONLY'] = 'true';
        }
        $sameSite = strtolower(strval(config('session.same_site')));
        if (!in_array($sameSite, ['lax', 'strict'], true)) {
            $wanted['SESSION_SAME_SITE'] = 'lax';
        }
        if (HttpsSecurity::isHttps() && !boolval(config('session.secure'))) {
            $wanted['SESSION_SECURE_COOKIE'] = 'true';
        }
        return $wanted;
    }

    public function checkResult(): string
    {
        return count($this->wanted()) === 0 ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return sprintf(
            'secure=%s, httponly=%s, samesite=%s',
            boolval(config('session.secure')) ? 'true' : 'false',
            boolval(config('session.http_only', true)) ? 'true' : 'false',
            strval(config('session.same_site')) ?: '-'
        );
    }

    public function getFixText(): ?string
    {
        $wanted = $this->wanted();
        if (count($wanted) === 0) {
            return null;
        }
        return "# .env\n" . collect($wanted)->map(function ($value, $key) {
            return "{$key}={$value}";
        })->implode("\n");
    }

    public function canFix(): bool
    {
        return count($this->wanted()) > 0 && $this->envWritable();
    }

    public function fix(): void
    {
        $this->writeEnv($this->wanted());
    }
}
