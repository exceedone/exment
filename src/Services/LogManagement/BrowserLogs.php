<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Middleware\CheckLoggingEnabled;
use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The log of the browsers (ISS02904), for the questions of the users ("the page was wrong", "the
 * button did nothing"): the errors of the scripts, the requests that failed and, when it is chosen,
 * the pages opened with their time. Off until it is turned on in the feature management (and the
 * role groups chosen); a user without it gets no script and the page that takes the rows refuses.
 *
 * The browser gathers them and sends them every few seconds (browser_log.js); here they are
 * checked, cut, hidden again and written after the response (LogWriter). Never a value typed, a
 * text of the page, a body, the values of a query, a cookie or a token.
 */
class BrowserLogs
{
    public const FEATURE = 'browser_log';

    public const DEFAULT_DAYS = 30;

    /** The rows of a user in WINDOW_SECONDS at most; the others are dropped. */
    public const MAX_ROWS = 300;
    public const WINDOW_SECONDS = 600;

    /** The rows of one sending at most. */
    public const MAX_BATCH = 50;

    public const LENGTHS = ['path' => 255, 'message' => 500, 'source' => 255, 'browser' => 50, 'os' => 50, 'screen' => 20, 'lang' => 20];

    public const COLUMNS = ['id', 'created_at', 'base_user_id', 'kind', 'path', 'status', 'duration_ms', 'message', 'source', 'count', 'tab', 'browser', 'os', 'screen', 'lang'];

    /**
     * Whether the log is on for the user (the feature management and its role groups).
     *
     * @param mixed $user the login user (null: the one logged in)
     */
    public static function active($user = null): bool
    {
        try {
            return BrowserLog::tableExists() && Features::allowed(static::FEATURE, $user ?? \Exment::user());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether the pages opened are kept too (the system setting; off: the errors and the failures only). */
    public static function keepsPages(): bool
    {
        try {
            return boolval(System::browser_log_pages());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * What the script of the page is given.
     *
     * @return array<string, mixed>
     */
    public static function scriptConfig(): array
    {
        return [
            'url' => admin_urls('webapi', 'browser_log'),
            'pages' => static::keepsPages(),
            'interval' => 5000,
            'batch' => 20,
            'max' => 200,
            'safeKeys' => CheckLoggingEnabled::URL_SAFE_KEYS,
        ];
    }

    /**
     * Take the rows a browser sent: checked, cut, hidden, at most MAX_ROWS a user in
     * WINDOW_SECONDS. Written after the response.
     *
     * @param mixed $user the login user
     * @param mixed $events the rows (decoded JSON)
     * @param mixed $env the browser, its OS, the screen, the language, the tab
     * @return int the rows taken
     */
    public static function accept($user, $events, $env): int
    {
        if (!is_array($events) || !isset($user)) {
            return 0;
        }
        $env = is_array($env) ? $env : [];
        $common = [
            'base_user_id' => $user->base_user_id ?? null,
            'tab' => preg_match('/^[0-9a-f]{16}$/', strval($env['tab'] ?? '')) === 1 ? strval($env['tab']) : null,
            'browser' => static::text($env['browser'] ?? null, 'browser'),
            'os' => static::text($env['os'] ?? null, 'os'),
            'screen' => preg_match('/^\d{1,5}x\d{1,5}( \d{1,5}x\d{1,5})?$/', strval($env['screen'] ?? '')) === 1 ? strval($env['screen']) : null,
            'lang' => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', strval($env['lang'] ?? '')) === 1 ? strval($env['lang']) : null,
        ];
        $key = 'exment-browser-log:' . strval($user->base_user_id ?? $user->id ?? '');
        $pages = static::keepsPages();
        $taken = 0;
        foreach (array_slice($events, 0, static::MAX_BATCH) as $event) {
            $row = static::row($event, $pages);
            if (is_null($row)) {
                continue;
            }
            if (RateLimiter::tooManyAttempts($key, static::MAX_ROWS)) {
                break;
            }
            RateLimiter::hit($key, static::WINDOW_SECONDS);
            LogWriter::insert(BrowserLog::class, $row + $common + ['created_at' => now()]);
            $taken++;
        }
        return $taken;
    }

    /**
     * A row from what the browser sent, null when it is not one to keep.
     *
     * @param mixed $event
     * @return array<string, mixed>|null
     */
    protected static function row($event, bool $pages): ?array
    {
        if (!is_array($event)) {
            return null;
        }
        $kind = $event['kind'] ?? null;
        if (!in_array($kind, BrowserLog::KINDS, true) || ($kind === BrowserLog::KIND_PAGE && !$pages)) {
            return null;
        }
        $number = function ($value, int $max): ?int {
            return is_numeric($value) ? max(0, min($max, intval($value))) : null;
        };
        $row = [
            'kind' => $kind,
            'path' => static::path($event['path'] ?? null),
            'status' => $kind === BrowserLog::KIND_REQUEST ? $number($event['status'] ?? null, 999) : null,
            'duration_ms' => $number($event['duration'] ?? null, 3600000),
            'message' => $kind === BrowserLog::KIND_ERROR ? static::message($event['message'] ?? null) : null,
            'source' => $kind === BrowserLog::KIND_ERROR ? static::source($event['source'] ?? null) : null,
            'count' => max(1, $number($event['count'] ?? 1, 1000) ?? 1),
        ];
        if ($kind === BrowserLog::KIND_ERROR && is_null($row['message'])) {
            return null;
        }
        return $row;
    }

    /**
     * The path of a page or a request: no scheme, no host, the values of the query hidden (as the
     * performance log hides them), no fragment.
     *
     * @param mixed $value
     */
    public static function path($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $parts = parse_url(trim($value));
        if ($parts === false) {
            return null;
        }
        $path = '/' . ltrim(strval($parts['path'] ?? ''), '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path = CheckLoggingEnabled::maskUrl($path . '?' . $parts['query']);
        }
        return mb_substr($path, 0, static::LENGTHS['path']);
    }

    /**
     * The message of an error: one line, hidden as the application log hides it (the mail
     * addresses, the values in a text of SQL), cut.
     *
     * @param mixed $value
     */
    public static function message($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($text === '') {
            return null;
        }
        $text = LogPrivacy::maskText(mb_substr($text, 0, 2000));
        // a quoted text may be a value: only a name of the code stays ('foo', "x.y")
        $text = preg_replace_callback('/([\'"`])(.*?)\1/u', function ($m) {
            return preg_match('/^[A-Za-z_$][\w$.\-]{0,63}$/', $m[2]) === 1 || $m[2] === LogPrivacy::MASK ? $m[0] : $m[1] . LogPrivacy::MASK . $m[1];
        }, $text) ?? $text;
        // numbers of 6 or more digits (a phone, a card, a code)
        $text = preg_replace('/\d{6,}/', LogPrivacy::MASK, $text) ?? $text;
        return mb_substr($text, 0, static::LENGTHS['message']);
    }

    /**
     * Where an error was: the lines "file:line:column" of the script and the stack, the files as
     * paths (no host, no query).
     *
     * @param mixed $value
     */
    public static function source($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $lines = [];
        foreach (array_slice(preg_split('/[\r\n]+/', $value) ?: [], 0, 6) as $line) {
            if (preg_match('~(?:[a-z]+://[^/\s]+)?(/[^\s?#:()]*)(?:\?[^\s#:()]*)?(?:#[^\s:()]*)?(:\d+(?::\d+)?)~i', $line, $m) === 1) {
                $lines[] = $m[1] . $m[2];
            }
        }
        $text = implode(' < ', array_values(array_unique($lines)));
        return $text === '' ? null : mb_substr($text, 0, static::LENGTHS['source']);
    }

    /**
     * @param mixed $value
     */
    protected static function text($value, string $column): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(preg_replace('/[^\p{L}\p{N} ._\/()-]/u', '', $value) ?? '');
        return $text === '' ? null : mb_substr($text, 0, static::LENGTHS[$column]);
    }
}
