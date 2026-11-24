<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Controllers\TuningController;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Services\Features;

/**
 * The tuning that Exment can do by itself, offered at the setup (the initialize screen)
 * and on the tuning page, each item to choose (checked by default). The rest of the tuning
 * (OPcache, PHP-FPM, cron, the queue, the PHP version...) is the server's, and stays advice
 * on the tuning page.
 *
 * - exment_cache:    EXMENT_USE_CACHE=true in .env
 * - assets:          put back the JS and CSS that are missing (exment:publish)
 * - cache_driver:    the cache on Redis, when the client is usable and the server answers
 * - session_driver:  the session on Redis, the same; not at the setup (everyone signs in
 *                    again, and the login of the setup itself would be lost)
 * - route_cache:     remove a route cache: the routes of the plugins are added on every
 *                    request (RouteCacheTuning), so none is ever made
 * - config_cache:    the config cache, written with umask 027 (640), after the items that
 *                    write .env (writing .env removes it, EnvService::setEnv)
 * - file_permission: .env and the config cache readable by their owner only (640),
 *                    only for a file that the PHP of the site owns, so it can still read it
 *
 * A server that keeps Tuning from its sites (EXMENT_FEATURES_LOCKED=tuning, a SaaS) gets
 * nothing here; one that keeps the server-wide buttons off (EXMENT_TUNING_SERVER_ACTIONS=false)
 * gets no config or route cache. The last result is kept and shown on the tuning page.
 */
class AutoTuning
{
    public const ITEMS = ['exment_cache', 'assets', 'cache_driver', 'session_driver', 'route_cache', 'config_cache', 'file_permission'];

    /** the items that write .env (they remove the config cache) */
    public const ENV_ITEMS = ['exment_cache', 'cache_driver', 'session_driver'];

    public const DONE = 'done';
    public const ALREADY = 'already';
    public const FAILED = 'failed';

    /** @var array<string, TuningBase> */
    protected $checks = [];

    /** @var array<string>|null the files for file_permission (tests); null: .env and the config cache */
    public $files;

    /** @var bool at the setup (the initialize screen): the session is not offered */
    public $setup;

    /** @var string|null where the last result is kept (tests) */
    public static $resultPath;
    /** @var \Closure|null fn(): bool, whether Redis answers (tests) */
    public static $redisAnswers;
    /** @var \Closure|null fn(string $command): void, runs an artisan command (tests) */
    public static $artisan;
    /** @var \Closure|null fn(array $values): void, writes .env (tests) */
    public static $envWriter;

    public function __construct(bool $setup = false)
    {
        $this->setup = $setup;
    }

    /**
     * Whether this server lets the site tune itself at all.
     */
    public static function enabled(): bool
    {
        // a SaaS keeps Tuning from its sites; a server can keep the buttons off (several sites on one code)
        return !Features::locked(Features::TUNING) && TuningController::serverActionsAllowed();
    }

    /**
     * The items that would change something here, in the order of ITEMS.
     *
     * @return array<string, string> key => label
     */
    public function available(): array
    {
        if (!static::enabled()) {
            return [];
        }
        $result = [];
        foreach (static::ITEMS as $key) {
            try {
                if ($this->offers($key, $result)) {
                    $result[$key] = exmtrans('tuning.auto.item.' . $key);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Exment auto tuning: ' . $key . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    /**
     * Do the chosen items that are needed. Each one on its own: one that fails is logged
     * and the others still run. The result is kept for the tuning page, and what was done
     * goes to the operation log.
     *
     * @param array<mixed> $keys
     * @param int|null $userId the login user who asked (the new administrator at the setup); null: the one logged in
     * @return array{done: array<string>, failed: array<string>}
     */
    public function apply(array $keys, ?int $userId = null): array
    {
        $done = [];
        $failed = [];
        $results = [];
        if (!static::enabled()) {
            return ['done' => $done, 'failed' => $failed];
        }
        foreach (static::ITEMS as $key) {
            if (!in_array($key, $keys, true) || ($key === 'session_driver' && $this->setup)) {
                continue;
            }
            try {
                // asked at its turn: an item before may have changed it (.env removes the config cache)
                if (!$this->needs($key)) {
                    $results[] = ['key' => $key, 'status' => static::ALREADY, 'message' => ''];
                    continue;
                }
                $this->{'apply' . studly_case($key)}();
                $done[] = $key;
                $results[] = ['key' => $key, 'status' => static::DONE, 'message' => ''];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Exment auto tuning: ' . $key . ': ' . $e->getMessage());
                $failed[] = $key;
                $results[] = ['key' => $key, 'status' => static::FAILED, 'message' => $e->getMessage()];
            }
        }
        if (count($results) > 0) {
            static::save($results, $this->setup);
            static::log($results, $this->setup, $userId);
        }
        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Whether the item is offered: needed now, or (the config cache) needed once the items
     * offered before it have written .env.
     *
     * @param array<string, string> $before the items offered before it
     */
    protected function offers(string $key, array $before): bool
    {
        if ($key === 'session_driver' && $this->setup) {
            return false;
        }
        if ($key === 'config_cache' && TuningController::serverActionsAllowed()
            && count(array_intersect(array_keys($before), static::ENV_ITEMS)) > 0) {
            return true;
        }
        return $this->needs($key);
    }

    protected function needs(string $key): bool
    {
        switch ($key) {
            case 'exment_cache':
                // the file EnvService writes
                return !boolval(config('exment.use_cache')) && is_writable(path_join(base_path(), '.env'));
            case 'assets':
                return count($this->assets()->missing()) > 0;
            case 'cache_driver':
                $store = (string) config('cache.default');
                $driver = (string) config("cache.stores.{$store}.driver", $store);
                return !in_array($driver, CacheDriverTuning::FAST_DRIVERS, true)
                    && !is_null(config('cache.stores.redis')) && static::redisAnswers();
            case 'session_driver':
                return !in_array((string) config('session.driver'), ['redis', 'memcached'], true) && static::redisAnswers();
            case 'route_cache':
                return TuningController::serverActionsAllowed() && RouteCacheTuning::cached();
            case 'config_cache':
                return TuningController::serverActionsAllowed() && !app()->configurationIsCached();
            case 'file_permission':
                return count($this->ownFiles()) > 0;
        }
        return false;
    }

    protected function applyExmentCache(): void
    {
        static::writeEnv(['EXMENT_USE_CACHE' => 'true']);
        config(['exment.use_cache' => true]);
    }

    protected function applyAssets(): void
    {
        static::artisan('exment:publish');
    }

    protected function applyCacheDriver(): void
    {
        // CACHE_DRIVER (the boilerplate of Exment) and CACHE_STORE (Laravel 11 and later)
        static::writeEnv(['CACHE_DRIVER' => 'redis', 'CACHE_STORE' => 'redis']);
    }

    protected function applySessionDriver(): void
    {
        static::writeEnv(['SESSION_DRIVER' => 'redis']);
    }

    protected function applyRouteCache(): void
    {
        static::artisan('route:clear');
    }

    protected function applyConfigCache(): void
    {
        static::cacheConfig();
    }

    /**
     * config:cache, the file readable by the owner and the group only (also the button of
     * the tuning page).
     */
    public static function cacheConfig(): void
    {
        $umask = umask(027);
        try {
            static::artisan('config:cache');
        } finally {
            umask($umask);
        }
        $path = app()->getCachedConfigPath();
        if (DIRECTORY_SEPARATOR !== '\\' && is_file($path)) {
            @chmod($path, 0640);
        }
    }

    protected function applyFilePermission(): void
    {
        foreach ($this->ownFiles() as $file) {
            if (!@chmod($file, 0640)) {
                throw new \RuntimeException('chmod 640 ' . $file);
            }
        }
    }

    protected function assets(): AssetsTuning
    {
        if (!isset($this->checks['assets'])) {
            $this->checks['assets'] = new AssetsTuning();
        }
        /** @var AssetsTuning */
        return $this->checks['assets'];
    }

    /**
     * The files that others may read and that the PHP of this site owns: making them
     * 640 leaves them readable here. A file of another user (the web server reading it
     * through its group, say) is left to the administrator.
     *
     * @return array<string>
     */
    public function ownFiles(): array
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_geteuid')) {
            return [];
        }
        $uid = posix_geteuid();
        $result = [];
        foreach ($this->files ?? [path_join(base_path(), '.env'), app()->getCachedConfigPath()] as $file) {
            if (!is_file($file)) {
                continue;
            }
            clearstatcache(true, $file);
            $perms = @fileperms($file);
            if ($perms !== false && ($perms & 0004) && @fileowner($file) === $uid) {
                $result[] = $file;
            }
        }
        return $result;
    }

    /**
     * Whether Redis is there: the client of the settings is usable and the server answers soon.
     */
    public static function redisAnswers(): bool
    {
        if (static::$redisAnswers) {
            return (bool) call_user_func(static::$redisAnswers);
        }
        $client = (string) config('database.redis.client', 'phpredis');
        if ($client === 'phpredis' && !extension_loaded('redis')) {
            return false;
        }
        if ($client === 'predis' && !class_exists(\Predis\Client::class)) {
            return false;
        }
        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (int) config('database.redis.default.port', 6379);
        // a quick knock first, so a missing server does not keep the page waiting
        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
            return true;
        } catch (\Throwable $ex) {
            return false;
        }
    }

    /**
     * The end of the install: EXMENT_INITIALIZE, APP_ENV=production (exment.zip ships
     * "local"), and .env readable by the owner and the group only (the owner is kept).
     * Other sites' APP_ENV (testing, staging...) is kept.
     *
     * @param array<string>|null $files .env (tests)
     */
    public static function installedEnv(?array $files = null): void
    {
        $values = ['EXMENT_INITIALIZE' => '1'];
        if (in_array(strval(config('app.env')), ['', 'local'], true)) {
            $values['APP_ENV'] = 'production';
        }
        static::writeEnv($values);

        $tuning = new self(true);
        $tuning->files = $files ?? [path_join(base_path(), '.env')];
        foreach ($tuning->ownFiles() as $file) {
            @chmod($file, 0640);
        }
    }

    /**
     * @param array<string, string> $values
     */
    protected static function writeEnv(array $values): void
    {
        if (static::$envWriter) {
            call_user_func(static::$envWriter, $values);
            return;
        }
        EnvService::setEnv($values);
    }

    /**
     * An artisan command inside a web request. config:cache boots a second application to read
     * the configuration, and that one becomes app() and the application of the facades: the
     * session and the login of the rest of the request (the setup logs the new administrator in
     * after this) would go to it and be lost. Put back the application of this request.
     */
    public static function artisan(string $command): void
    {
        if (static::$artisan) {
            call_user_func(static::$artisan, $command);
            return;
        }
        $app = app();
        try {
            \Illuminate\Support\Facades\Artisan::call($command);
        } finally {
            \Illuminate\Container\Container::setInstance($app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        }
    }

    /**
     * What was done, in the operation log (Admin setting → operation log) and the log file.
     *
     * @param array<int, array{key: string, status: string, message: string}> $results
     */
    protected static function log(array $results, bool $setup, ?int $userId): void
    {
        $detail = ['setup' => $setup, 'results' => $results];
        \Illuminate\Support\Facades\Log::info('[auto tuning]', $detail);
        try {
            if (is_null($userId)) {
                $user = \Exceedone\Exment\Facades\ExmentFacade::user();
                $userId = $user ? intval($user->id) : 0;
            }
            $request = app()->runningInConsole() ? null : request();
            \ExmentAdminCore\Admin\Auth\Database\OperationLog::create([
                'user_id' => $userId,
                'path' => 'tuning/auto' . ($setup ? '/setup' : ''),
                'method' => isset($request) ? substr($request->method(), 0, 10) : 'CLI',
                'ip' => isset($request) ? strval($request->getClientIp()) : '127.0.0.1',
                'input' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // the log file has it
        }
    }

    public static function path(): string
    {
        return static::$resultPath ?? storage_path('framework/exment_auto_tuning.json');
    }

    /**
     * @param array<int, array{key: string, status: string, message: string}> $results
     */
    protected static function save(array $results, bool $setup): void
    {
        try {
            @file_put_contents(static::path(), json_encode([
                'time' => time(),
                'setup' => $setup,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Exment auto tuning: could not keep the result: ' . $ex->getMessage());
        }
    }

    /**
     * The last result, or null. It reads the file each time.
     *
     * @phpstan-impure
     * @return array{time: int, setup: bool, results: array<int, array<string, mixed>>}|null
     */
    public static function last(): ?array
    {
        $path = static::path();
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            return null;
        }
        return [
            'time' => intval($json['time'] ?? 0),
            'setup' => boolval($json['setup'] ?? false),
            'results' => array_values(array_filter($json['results'], 'is_array')),
        ];
    }
}
