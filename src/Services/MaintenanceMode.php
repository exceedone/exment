<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode that the system administrators turn on and off (ISS01396).
 *
 * Two kinds, the operator's first:
 * - The operator's maintenance: a file on the server (config
 *   "exment.maintenance_operator_file"), written only by the command
 *   `php artisan exment:maintenance on --operator`. The screens of the site
 *   cannot change it, so the administrators of a tenant cannot end it. It works
 *   whether the feature is used or not. Only the allowed IP addresses get in
 *   (and the system administrators, with --allow-admins).
 * - The site's maintenance: the system setting "maintenance", when the feature
 *   "maintenance_mode" is used (Feature management). Started now or planned
 *   (start and end). The system administrators, the administrator who started
 *   it and the allowed IP addresses get in.
 *
 * In maintenance the others get the maintenance page (HTTP 503, Retry-After).
 * What else stops is chosen: the API, the public forms and share links, the
 * scheduled commands and the queue. A maintenance with an end ends by itself
 * when the end has passed.
 *
 * Not the same as `php artisan down` (the update from the screen and the
 * restore use it): that one stops everything for everyone and is not changed.
 *
 * From outside, as the public forms ("stop_public", always in the operator's):
 * the public forms, the share links of the file server, the LINE webhook and
 * the answer page of the safety check (a link in a mail).
 *
 * The operator's maintenance is checked first for every request
 * (MaintenanceOperatorGuard: files and IP addresses only), the site's after
 * the login (MaintenanceGuard). A skipped exment:schedule runs once after it
 * (the hours are in the log); what is set to a fixed hour in a skipped hour
 * (the automatic backup, the clean of the operation log) waits for its next
 * time, and the other scheduled commands are not run after. The health check
 * of a load balancer is not stopped (config "exment.maintenance_exempt_paths").
 *
 * The allowed IP addresses are the address Laravel sees (TrustProxies): a
 * proxy that is trusted may set it with X-Forwarded-For. Where other code
 * runs on the same server (a tenant of a SaaS), it can call the site from
 * the server itself with any X-Forwarded-For, so do not allow the server's
 * own address or a range of the trusted proxies.
 */
class MaintenanceMode
{
    public const FEATURE = 'maintenance_mode';

    public const SOURCE_SITE = 'site';
    public const SOURCE_OPERATOR = 'operator';

    /** The API: open, the API keys and tokens of system administrators only, or stopped. */
    public const API_OPEN = 'open';
    public const API_ADMIN = 'admin';
    public const API_STOP = 'stop';

    /** The kinds of pages the middleware guards. */
    public const KIND_WEB = 'web';
    public const KIND_WEBAPI = 'webapi';
    public const KIND_ANONYMOUS = 'anonymous';
    public const KIND_API = 'api';
    public const KIND_API_ANONYMOUS = 'api_anonymous';
    public const KIND_PUBLIC = 'public';

    /** Retry-After when the end is not planned (seconds). */
    public const RETRY_AFTER_DEFAULT = 600;

    /** The header that tells a response is the maintenance page. */
    public const HEADER = 'X-Exment-Maintenance';

    /** @var array<string, mixed>|null the maintenance of this request (null: not looked at yet) */
    protected static ?array $current = null;
    protected static bool $looked = false;

    // ---------------------------------------------------------------- the settings

    /**
     * The site's setting, with every key.
     *
     * @return array{active: bool, start_at: ?string, end_at: ?string, messages: array<string, string>, allow_ips: string[], api: string, stop_public: bool, stop_schedule: bool, stop_queue: bool, started_by: ?int, started_at: ?string}
     */
    public static function settings(): array
    {
        return static::normalize(static::readSetting());
    }

    /**
     * The operator's maintenance (the file), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function operator(): ?array
    {
        foreach (static::operatorFiles() as $path) {
            // a directory or anything else is not a file of the maintenance
            if (!is_file($path)) {
                continue;
            }
            $raw = json_decode(strval(@file_get_contents($path)), true);
            // a file that cannot be read is still the operator's maintenance (the safe side)
            $raw = is_array($raw) ? $raw : [];
            $value = static::normalize(['active' => true] + $raw);
            // past its end: as if it were not there (the next file may still count)
            if (static::passed($value['end_at'])) {
                continue;
            }
            return static::operatorState($value, $raw, $path);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    protected static function operatorState(array $value, array $raw, string $path): array
    {
        $value['allow_admins'] = boolval(array_get($raw, 'allow_admins', false));
        $value['file'] = $path;
        // the operator's stops everything but the allowed IP addresses
        $value['api'] = static::API_STOP;
        $value['stop_public'] = true;
        $value['stop_schedule'] = true;
        $value['stop_queue'] = true;
        return $value;
    }

    /**
     * The files of the operator's maintenance, the first that is there counts
     * (config "exment.maintenance_operator_file": a comma list, as one file
     * of the site and one of all the sites of a server).
     *
     * @return string[]
     */
    public static function operatorFiles(): array
    {
        $value = config('exment.maintenance_operator_file');
        $files = is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
        return count($files) > 0 ? $files : [storage_path('framework/exment-maintenance.json')];
    }

    /**
     * The file the command writes and removes (the first one).
     */
    public static function operatorFile(): string
    {
        return static::operatorFiles()[0];
    }

    /**
     * Save the site's setting (the keys given; the others are kept).
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        static::locked(function () use ($values) {
            $settings = array_merge(static::settings(), $values);
            System::maintenance(static::normalize($settings));
            static::reset();
        });
    }

    protected static bool $inLock = false;

    /**
     * Run a read-modify-write of the setting under a lock (a request and the
     * tick may change it at the same moment). Reentrant in one process; when
     * the cache has no locks, it runs without one.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    protected static function locked(\Closure $callback)
    {
        if (static::$inLock) {
            return $callback();
        }
        $lock = null;
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('exment:maintenance', 10);
            $lock->block(5);
        } catch (\Throwable $e) {
            $lock = null;
        }
        static::$inLock = true;
        try {
            return $callback();
        } finally {
            static::$inLock = false;
            try {
                optional($lock)->release();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return array{active: bool, start_at: ?string, end_at: ?string, messages: array<string, string>, allow_ips: string[], api: string, stop_public: bool, stop_schedule: bool, stop_queue: bool, started_by: ?int, started_at: ?string}
     */
    protected static function normalize(array $value): array
    {
        $date = function ($v): ?string {
            if (!isset($v) || $v === '') {
                return null;
            }
            try {
                return Carbon::parse(strval($v))->toIso8601String();
            } catch (\Throwable $e) {
                return null;
            }
        };
        $messages = [];
        foreach ((array) array_get($value, 'messages', []) as $locale => $text) {
            if (is_string($locale) && is_scalar($text) && trim(strval($text)) !== '') {
                $messages[$locale] = strval($text);
            }
        }
        $ips = array_get($value, 'allow_ips', []);
        if (is_string($ips)) {
            $ips = preg_split('/[\s,]+/', $ips) ?: [];
        }
        $ips = array_values(array_unique(array_filter(array_map(function ($ip) {
            return is_scalar($ip) ? trim(strval($ip)) : '';
        }, (array) $ips), function ($ip) {
            return $ip !== '' && static::validIp($ip);
        })));
        $api = strval(array_get($value, 'api', static::API_ADMIN));
        if (!in_array($api, [static::API_OPEN, static::API_ADMIN, static::API_STOP], true)) {
            $api = static::API_ADMIN;
        }
        $startedBy = array_get($value, 'started_by');

        return [
            'active' => boolval(array_get($value, 'active', false)),
            'start_at' => $date(array_get($value, 'start_at')),
            'end_at' => $date(array_get($value, 'end_at')),
            'messages' => $messages,
            'allow_ips' => $ips,
            'api' => $api,
            'stop_public' => boolval(array_get($value, 'stop_public', true)),
            'stop_schedule' => boolval(array_get($value, 'stop_schedule', true)),
            'stop_queue' => boolval(array_get($value, 'stop_queue', true)),
            'started_by' => is_numeric($startedBy) ? intval($startedBy) : null,
            'started_at' => $date(array_get($value, 'started_at')),
        ];
    }

    /**
     * An IP address or a range (CIDR), as the IP filter of the system setting.
     */
    public static function validIp(string $ip): bool
    {
        // IPv4 and IPv6 (ISS02909); "/0" lets every address in: not an allow list
        return IpAddress::isValidRule($ip, false);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function readSetting(bool $fresh = false): array
    {
        try {
            if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
                return [];
            }
            if ($fresh || app()->runningInConsole()) {
                // a queue worker and the scheduler run long: not the cache of the request.
                // (A web request reads it through System, whose cache a change clears; the
                // commands change it through System too, so both see the same.)
                $raw = System::query()->where('system_name', 'maintenance')->value('system_value');
                $value = is_string($raw) ? json_decode($raw, true) : null;
            } else {
                $value = System::maintenance();
            }
        } catch (\Throwable $e) {
            return [];
        }
        $value = json_decode(strval(json_encode($value ?? [])), true);
        return is_array($value) ? $value : [];
    }

    // ---------------------------------------------------------------- now

    /**
     * The maintenance now, or null. The operator's comes first. The site's is
     * brought up to date first (a planned start that has come, an end that
     * has passed).
     *
     * @return array<string, mixed>|null with "source" (site or operator)
     */
    public static function current(): ?array
    {
        if (static::$looked) {
            // a request looks once; a queue worker or the scheduler again after a few seconds
            if (!app()->runningInConsole() || (microtime(true) - static::$lookedAt) < static::CONSOLE_LOOK_SECONDS) {
                return static::$current;
            }
        }
        static::$looked = true;
        static::$lookedAt = microtime(true);
        static::$current = static::look();
        return static::$current;
    }

    /** How long a command (a queue worker, the scheduler) keeps what it looked at. */
    public const CONSOLE_LOOK_SECONDS = 10;

    protected static float $lookedAt = 0.0;

    /**
     * @return array<string, mixed>|null
     */
    protected static function look(): ?array
    {
        $operator = static::operator();
        if (isset($operator)) {
            return ['source' => static::SOURCE_OPERATOR] + $operator;
        }
        if (!static::featureUsed()) {
            return null;
        }
        $settings = static::sync();
        if (!$settings['active']) {
            return null;
        }
        return ['source' => static::SOURCE_SITE] + $settings;
    }

    public static function isActive(): bool
    {
        return static::current() !== null;
    }

    protected static function featureUsed(): bool
    {
        try {
            return Features::exists(static::FEATURE) && Features::enabled(static::FEATURE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A planned maintenance that has not started yet (to tell the users).
     *
     * @return array<string, mixed>|null
     */
    public static function upcoming(): ?array
    {
        if (!static::featureUsed()) {
            return null;
        }
        $settings = static::settings();
        if ($settings['active'] || !isset($settings['start_at']) || static::passed($settings['start_at'])) {
            return null;
        }
        return $settings;
    }

    /**
     * Bring the site's setting up to date: a planned start that has come
     * starts it, an end that has passed ends it. Each is written once (and
     * logged), by the first request or by `exment:maintenance tick`.
     *
     * @return array<string, mixed> the setting after it
     */
    public static function sync(): array
    {
        // nothing is due (as almost always): no lock, no write, the caches kept
        $settings = static::settings();
        if (!static::transitionDue($settings)) {
            return $settings;
        }
        return static::locked(function () {
            return static::syncLocked();
        });
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected static function transitionDue(array $settings): bool
    {
        if ($settings['active']) {
            return static::passed($settings['end_at']);
        }
        return isset($settings['start_at']) && static::passed($settings['start_at']);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function syncLocked(): array
    {
        // read again from the database under the lock: another process may have done it already (no second log)
        $settings = static::normalize(static::readSetting(true));
        if (!static::transitionDue($settings)) {
            return $settings;
        }
        if ($settings['active']) {
            if (static::passed($settings['end_at'])) {
                return static::end(null, 'auto_end');
            }
            return $settings;
        }
        if (isset($settings['start_at']) && static::passed($settings['start_at'])) {
            if (static::passed($settings['end_at'])) {
                // the whole plan has passed (nobody came in that time): only clear it
                static::save(['start_at' => null, 'end_at' => null]);
                static::log('schedule_passed', null);
                return static::settings();
            }
            static::save(['active' => true, 'start_at' => null, 'started_by' => null, 'started_at' => $settings['start_at']]);
            static::log('scheduled_start', null);
            return static::settings();
        }
        return $settings;
    }

    protected static function passed(?string $at): bool
    {
        if (!isset($at)) {
            return false;
        }
        try {
            return Carbon::parse($at)->lte(Carbon::now());
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------- start and end

    /**
     * Start the site's maintenance now.
     *
     * @param array<string, mixed> $options the other keys of the setting (end_at, messages, allow_ips, api, stop_*)
     * @param mixed $user the administrator who starts it (always gets in)
     * @return array<string, mixed>
     */
    public static function start(array $options = [], $user = null): array
    {
        $userId = static::userId($user);
        static::save(array_merge($options, [
            'active' => true,
            'start_at' => null,
            'started_by' => $userId,
            'started_at' => Carbon::now()->toIso8601String(),
        ]));
        static::log('start', $userId, array_only(static::settings(), ['end_at', 'api', 'stop_public', 'stop_schedule', 'stop_queue']));
        return static::settings();
    }

    /**
     * End the site's maintenance (and its plan).
     *
     * @param mixed $user
     * @return array<string, mixed>
     */
    public static function end($user = null, string $action = 'end'): array
    {
        $userId = static::userId($user);
        static::save(['active' => false, 'start_at' => null, 'end_at' => null, 'started_by' => null, 'started_at' => null]);
        static::log($action, $userId);
        return static::settings();
    }

    /**
     * Start the operator's maintenance (writes the file).
     *
     * @param array<string, mixed> $options end_at, messages, allow_ips, allow_admins
     */
    public static function startOperator(array $options = []): void
    {
        $value = static::normalize(array_merge($options, ['active' => true, 'started_at' => Carbon::now()->toIso8601String()]));
        $value = array_only($value, ['active', 'end_at', 'messages', 'allow_ips', 'started_at']);
        $value['allow_admins'] = boolval(array_get($options, 'allow_admins', false));
        $path = static::operatorFile();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        if (@file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException('The file of the maintenance cannot be written: ' . $path);
        }
        static::reset();
        static::log('operator_start', null);
    }

    public static function endOperator(): void
    {
        $path = static::operatorFile();
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('The file of the maintenance cannot be removed: ' . $path);
        }
        static::reset();
        static::log('operator_end', null);
    }

    /**
     * @param mixed $user
     */
    protected static function userId($user): ?int
    {
        if (is_numeric($user)) {
            return intval($user);
        }
        if (is_object($user) && isset($user->id)) {
            return intval($user->id);
        }
        return null;
    }

    // ---------------------------------------------------------------- who gets in

    /**
     * Whether the request gets in during the maintenance.
     *
     * @param array<string, mixed> $state from current()
     * @param mixed $user the login user (null: not logged in)
     */
    public static function passes(array $state, Request $request, string $kind, $user = null): bool
    {
        if (static::ipAllowed($state, $request)) {
            return true;
        }
        $operator = $state['source'] === static::SOURCE_OPERATOR;

        switch ($kind) {
            case static::KIND_PUBLIC:
                return !$operator && !$state['stop_public'];

            case static::KIND_ANONYMOUS:
                // the login page: the administrators must be able to log in
                return !$operator || boolval($state['allow_admins'] ?? false);

            case static::KIND_API_ANONYMOUS:
                // getting a token: who it is for is asked when the API is called
                return !$operator && $state['api'] !== static::API_STOP;

            case static::KIND_API:
                if ($operator) {
                    // the operator's: only the allowed IP addresses (above)
                    return false;
                }
                if ($state['api'] === static::API_OPEN) {
                    return true;
                }
                if ($state['api'] === static::API_STOP) {
                    return false;
                }
                return static::isAdministrator($user);

            default:
                // the pages (and their web API)
                if ($operator) {
                    return boolval($state['allow_admins'] ?? false) && static::isAdministrator($user);
                }
                if (static::isAdministrator($user)) {
                    return true;
                }
                // who started it always gets in (not to shut oneself out)
                $id = static::userId($user);
                return isset($id) && isset($state['started_by']) && $id === intval($state['started_by']);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    protected static function ipAllowed(array $state, Request $request): bool
    {
        $ips = (array) ($state['allow_ips'] ?? []);
        if (count($ips) === 0) {
            return false;
        }
        $ip = $request->ip();
        return is_string($ip) && IpAddress::matches($ip, array_map('strval', $ips));
    }

    /**
     * @param mixed $user
     */
    protected static function isAdministrator($user): bool
    {
        if (!is_object($user)) {
            return false;
        }
        try {
            if (method_exists($user, 'isAdministrator')) {
                return boolval($user->isAdministrator());
            }
            $base = $user->base_user ?? null;
            if (is_object($base) && method_exists($base, 'isAdministrator')) {
                return boolval($base->isAdministrator());
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Whether the scheduled commands of Exment are skipped now.
     */
    public static function stopsSchedule(): bool
    {
        $state = static::current();
        return isset($state) && boolval($state['stop_schedule']);
    }

    /**
     * The skip of a scheduled event: in maintenance it is skipped, and a
     * skipped exment:schedule (the hourly one) is run once after it
     * (exment:maintenance tick).
     */
    public static function skipsScheduled(string $command): bool
    {
        if (!static::stopsSchedule()) {
            return false;
        }
        if (str_contains($command, 'exment:schedule')) {
            $hours = static::missedHours();
            $hour = Carbon::now()->format('Y-m-d H:00');
            if (!in_array($hour, $hours, true)) {
                $hours[] = $hour;
                static::systemValue('maintenance_missed_schedule', json_encode(array_slice($hours, -48)));
            }
        }
        return true;
    }

    /**
     * Run the exment:schedule skipped in the maintenance, once, after it.
     */
    public static function runMissedSchedule(): bool
    {
        $hours = static::missedHours();
        if (count($hours) === 0 || static::isActive()) {
            return false;
        }
        static::systemValue('maintenance_missed_schedule', null);
        // once, now: what is set to a fixed hour (the automatic backup, the clean of the
        // operation log) in a skipped hour waits for its next time; the other scheduled
        // commands (file-server-purge, the repair of the search) are not run after
        static::log('missed_schedule', null, ['hours' => $hours]);
        \Illuminate\Support\Facades\Artisan::call('exment:schedule');
        return true;
    }

    /**
     * The hours whose exment:schedule a maintenance skipped.
     *
     * @return string[]
     */
    public static function missedHours(): array
    {
        $value = static::systemValue('maintenance_missed_schedule');
        $hours = is_string($value) ? json_decode($value, true) : null;
        return is_array($hours) ? array_values(array_filter($hours, 'is_string')) : [];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected static function systemValue(string $name, $value = null)
    {
        try {
            if (func_num_args() > 1) {
                System::{$name}($value);
                System::clearRequestSession();
                return null;
            }
            if (app()->runningInConsole()) {
                return System::query()->where('system_name', $name)->value('system_value');
            }
            return System::{$name}();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The feature was turned off: a maintenance of the site (or its plan) ends
     * now, with a log. It does not come back when the feature is turned on.
     *
     * @param mixed $user
     */
    public static function featureTurnedOff($user = null): void
    {
        $settings = static::settings();
        if ($settings['active'] || isset($settings['start_at'])) {
            static::end($user, 'feature_off');
        }
    }

    /**
     * The operator's maintenance, checked before anything else (no database,
     * no login: a version switch may have them broken). Null: go on (not in
     * the operator's maintenance, an allowed IP address, or the administrators
     * may come in and the guards of the pages decide).
     */
    public static function earlyResponse(Request $request): ?Response
    {
        // the health check of a load balancer: kept, or every server would be taken out
        if (static::exempt($request)) {
            return null;
        }
        $state = static::operator();
        if (!isset($state)) {
            return null;
        }
        $state = ['source' => static::SOURCE_OPERATOR] + $state;
        if (static::ipAllowed($state, $request) || boolval($state['allow_admins'])) {
            return null;
        }
        $path = '/' . ltrim($request->path(), '/');
        $prefix = '/' . trim(strval(config('admin.route.prefix')), '/');
        $rest = $prefix === '/' ? $path : (str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path);
        $api = preg_match('#\A/(api|webapi|oauth)(/|\z)#', $rest) === 1;
        return static::response($state, $request, $api ? static::KIND_API : static::KIND_WEB);
    }

    /**
     * The paths (exact) that a maintenance does not stop: the health check of
     * a load balancer (config "exment.maintenance_exempt_paths", a comma list;
     * by default "/" that only sends on to the login, and Laravel's "/up").
     */
    public static function exempt(Request $request): bool
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $value = config('exment.maintenance_exempt_paths', '/,/up');
        $paths = array_map(function ($p) {
            return '/' . trim(trim($p), '/');
        }, array_filter(explode(',', strval($value)), function ($p) {
            return trim($p) !== '';
        }));
        return in_array('/' . trim($request->path(), '/'), $paths, true);
    }

    /**
     * When the check itself failed: closed if a file of the operator is there
     * (a plain 503), else open (nothing says there is a maintenance).
     */
    public static function failedResponse(): ?Response
    {
        try {
            foreach (static::operatorFiles() as $path) {
                if (@is_file($path)) {
                    return new \Symfony\Component\HttpFoundation\Response('Service Unavailable (maintenance)', 503, [
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'Retry-After' => strval(static::RETRY_AFTER_DEFAULT),
                        static::HEADER => '1',
                    ]);
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * Whether the queue workers wait now.
     */
    public static function stopsQueue(): bool
    {
        $state = static::current();
        return isset($state) && boolval($state['stop_queue']);
    }

    // ---------------------------------------------------------------- the answer

    /**
     * The message of the maintenance in the language of the request.
     *
     * @param array<string, mixed> $state
     */
    public static function message(array $state): string
    {
        $messages = (array) ($state['messages'] ?? []);
        $locale = app()->getLocale();
        if (isset($messages[$locale]) && trim(strval($messages[$locale])) !== '') {
            return strval($messages[$locale]);
        }
        foreach ($messages as $text) {
            if (trim(strval($text)) !== '') {
                return strval($text);
            }
        }
        return exmtrans('maintenance.default_message');
    }

    /**
     * Seconds until the planned end (at least a minute), or the default.
     *
     * @param array<string, mixed> $state
     */
    public static function retryAfter(array $state): int
    {
        if (!empty($state['end_at'])) {
            try {
                return max(60, intval(ceil(Carbon::now()->diffInSeconds(Carbon::parse(strval($state['end_at'])), false))));
            } catch (\Throwable $e) {
            }
        }
        return static::RETRY_AFTER_DEFAULT;
    }

    /**
     * The maintenance page (HTTP 503): JSON for the API, the web API and ajax.
     *
     * @param array<string, mixed> $state
     */
    public static function response(array $state, Request $request, string $kind): Response
    {
        $message = static::message($state);
        $endAt = null;
        if (!empty($state['end_at'])) {
            try {
                $endAt = Carbon::parse(strval($state['end_at']))->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
            } catch (\Throwable $e) {
            }
        }
        $headers = [
            'Retry-After' => strval(static::retryAfter($state)),
            static::HEADER => '1',
            'Cache-Control' => 'no-store',
        ];
        if (in_array($kind, [static::KIND_API, static::KIND_API_ANONYMOUS, static::KIND_WEBAPI], true) || $request->expectsJson() || $request->ajax()) {
            return response()->json([
                'code' => 'maintenance',
                'message' => $message,
                'end_at' => $state['end_at'] ?? null,
            ], 503, $headers);
        }
        return response(view('exment::exception.maintenance_mode', [
            'message' => $message,
            'end_at' => $endAt,
        ])->render(), 503, $headers);
    }

    // ---------------------------------------------------------------- the log

    /**
     * Keep the change in the operation log (Admin setting → operation log) and in the log file.
     *
     * @param array<string, mixed> $detail
     */
    public static function log(string $action, ?int $userId, array $detail = []): void
    {
        $request = app()->runningInConsole() ? null : request();
        Log::info('[maintenance] ' . $action, ['user_id' => $userId] + $detail);
        try {
            OperationLogModel::create([
                'user_id' => $userId ?? 0,
                'path' => 'maintenance/' . $action,
                'method' => isset($request) ? substr($request->method(), 0, 10) : 'CLI',
                'ip' => isset($request) ? strval(\Exceedone\Exment\Services\LogManagement\LogPrivacy::ip($request->getClientIp())) : '127.0.0.1',
                'input' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // the log file has it
        }
    }

    /**
     * Forget what was read in this request (after a change, and in the tests).
     */
    public static function reset(): void
    {
        static::$looked = false;
        static::$lookedAt = 0.0;
        static::$current = null;
        System::clearRequestSession();
    }
}
