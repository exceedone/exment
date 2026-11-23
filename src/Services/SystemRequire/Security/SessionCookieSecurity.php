<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The session cookie: httponly, samesite lax or strict, and secure on an https site.
 * Secure is fixed only on https: on http it would stop every sign-in.
 *
 * SESSION_SECURE_COOKIE not set (the default of Laravel): the cookie is secure on a
 * request over https. On the page (a request over https) that is enough; where it
 * cannot be told (the command line, a proxy that PHP does not see as https), it is
 * still asked for.
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
        if (HttpsSecurity::isHttps() && !static::secure()) {
            $wanted['SESSION_SECURE_COOKIE'] = 'true';
        }
        return $wanted;
    }

    /**
     * Whether the session cookie is sent only over https: SESSION_SECURE_COOKIE, or when
     * it is not set, whether this request is over https (the cookie is secure then).
     */
    public static function secure(): bool
    {
        $secure = config('session.secure');
        if (!is_null($secure)) {
            return boolval($secure);
        }
        try {
            return request()->isSecure();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function checkResult(): string
    {
        return count($this->wanted()) === 0 ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return sprintf(
            'secure=%s, httponly=%s, samesite=%s',
            (static::secure() ? 'true' : 'false') . (is_null(config('session.secure')) ? ' (auto)' : ''),
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
