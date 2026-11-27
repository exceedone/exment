<?php

namespace Exceedone\Exment\Services\Installer;

/**
 * The install of the web installer, run in steps: the screen calls install/step again and
 * again, and each call does a part of the work for a few seconds at most (the files to
 * publish, the migrations, the first data, the end). A call never runs for long, so a proxy
 * with a short timeout (nginx answers 504 after 60 seconds by default) does not stop it.
 *
 * - The state is a file (storage/app/.exment_install_run.json): the session is not kept
 *   across the install (the key of the application is made in it), and the database is
 *   what is being made.
 * - It goes on from where it stopped: the migrations done are those in the table of the
 *   migrations; a call that stopped (a timeout, a reload, a closed browser) is taken up by the
 *   next one. A migration that failed half way may have left a table behind (MySQL does not
 *   undo its DDL): the error names the migration, and such a table may have to be removed by
 *   hand before "run again".
 * - One runner at a time: a lock on a file (flock), let go by the system when the process
 *   ends in any way. A call that finds it taken answers the progress only ("busy").
 * - The browser that started the install runs it: the state keeps the hash of a random
 *   token, the browser the token (the cookie COOKIE). Other browsers are only told that an
 *   install is going on.
 *
 * The command "php artisan exment:install" does not use it.
 */
class InstallRunner
{
    public const PHASE_PUBLISH = 'publish';
    public const PHASE_MIGRATE = 'migrate';
    public const PHASE_SEED = 'seed';
    public const PHASE_FINISH = 'finish';

    public const PHASES = [
        self::PHASE_PUBLISH,
        self::PHASE_MIGRATE,
        self::PHASE_SEED,
        self::PHASE_FINISH,
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUCCEEDED = 'succeeded';

    /** The state file, under storage_path(). */
    public const STATE = 'app/.exment_install_run.json';

    /** The lock file, under storage_path(). */
    public const LOCK = 'app/.exment_install_run.lock';

    /**
     * The cookie with the token of the browser that started the install. Not encrypted
     * (EncryptCookies): the key of the application is made in the request that sets it.
     */
    public const COOKIE = 'exment_install_run';

    /** @var string|null for tests: the state file (null: the real one) */
    public static $statePath = null;

    /** @var string|null for tests: the lock file (null: the real one) */
    public static $lockPath = null;

    /** @var (callable(string): void)|null for tests: runs a phase other than the migrations instead of the command */
    public static $phaseRunner = null;

    /** @var (callable(): array{0: string[], 1: string[]})|null for tests: [all the migrations, the ones done] */
    public static $migrationLister = null;

    /** @var (callable(string): void)|null for tests: runs one migration */
    public static $migrationRunner = null;

    /** @var resource|null the lock held by this process */
    protected $lock = null;

    public static function statePath(): string
    {
        return static::$statePath ?? storage_path(static::STATE);
    }

    public static function lockPath(): string
    {
        return static::$lockPath ?? storage_path(static::LOCK);
    }

    /**
     * The seconds a call works before it answers (it ends the migration it is in).
     */
    public static function budgetSeconds(): int
    {
        return max(1, intval(config('exment.install_step_seconds', 15)));
    }

    /**
     * An install of the web installer is started and not finished: the screen of its progress
     * is shown, and the site is not used as an installed one while its tables are being made.
     */
    public static function inProgress(): bool
    {
        if (!is_file(static::statePath())) {
            return false;
        }
        $state = static::read();
        return !is_null($state) && $state['status'] !== static::STATUS_SUCCEEDED && !InstalledState::isInstalled();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        $path = static::statePath();
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        $state = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($state) || !in_array($state['phase'] ?? null, static::PHASES, true)) {
            return null;
        }
        return array_merge(static::emptyState(), $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    protected static function write(array $state): void
    {
        $state['updated_at'] = time();
        $path = static::statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // a file of its own, then renamed: a reader never sees a file written half way
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException('Cannot write ' . $tmp . ': check that storage/app can be written.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $path . ': check that storage/app can be written.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyState(): array
    {
        return [
            'phase' => static::PHASE_PUBLISH,
            'status' => static::STATUS_RUNNING,
            'error' => null,
            'token_hash' => null,
            'migration' => null,
            'migrations_done' => 0,
            'migrations_total' => 0,
            'started_at' => time(),
            'updated_at' => time(),
        ];
    }

    /**
     * Start the install (the form of the installer, after .env is written).
     *
     * @return string|null the token for the cookie of the browser (cookie()); null: an install
     *         is there already, it is kept (the same install, not a second one)
     */
    public static function start(): ?string
    {
        if (!is_null(static::read())) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $state = static::emptyState();
        $state['token_hash'] = static::hashToken($token);
        static::write($state);
        return $token;
    }

    protected static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The browser with this token started the install that is there.
     */
    public static function isOwner(?string $token): bool
    {
        $state = static::read();
        if (is_null($state) || !is_string($state['token_hash']) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($state['token_hash'], static::hashToken($token));
    }

    /**
     * The cookie with the token: for the folder of the site, until the browser is closed,
     * not read by scripts.
     */
    public static function cookie(string $token, ?string $basePath = null): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->make(static::COOKIE, $token, 0, static::cookiePath($basePath), null, request()->isSecure(), true, false, 'lax');
    }

    /**
     * The cookie removed (the install ended, or was reset).
     */
    public static function forgetCookie(?string $basePath = null): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget(static::COOKIE, static::cookiePath($basePath));
    }

    protected static function cookiePath(?string $basePath): string
    {
        $basePath = rtrim($basePath ?? request()->getBasePath(), '/');
        return $basePath === '' ? '/' : $basePath;
    }

    /**
     * Forget an install that failed (the "reset" of the installer). Never one going on.
     */
    public static function forgetFailed(): bool
    {
        $runner = new self();
        if (!$runner->acquire()) {
            return false;
        }
        try {
            $state = static::read();
            if (is_null($state) || $state['status'] !== static::STATUS_FAILED) {
                return false;
            }
            @unlink(static::statePath());
            return true;
        } finally {
            $runner->release();
        }
    }

    /**
     * A call is working on the install now. A shared lock: it does not keep a runner out.
     */
    public static function isBusy(): bool
    {
        $runner = new self();
        if (!$runner->acquire(LOCK_SH)) {
            return true;
        }
        $runner->release();
        return false;
    }

    /**
     * The progress, without work (the screen reads it while a call works).
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $state = static::read();
        if (is_null($state) || InstalledState::isInstalled()) {
            return static::toProgress(null);
        }
        return static::toProgress($state, static::isBusy());
    }

    /**
     * One call of the screen: work for a few seconds at most, then answer the progress.
     *
     * @param bool $retry after a failure: try the step that failed again
     * @return array<string, mixed> the progress (see toProgress)
     */
    public function step(bool $retry = false): array
    {
        $state = static::read();
        // nothing to do; or an installed site (a state left from before): never the install again
        if (is_null($state) || InstalledState::isInstalled()) {
            return static::toProgress(null);
        }

        if (!$this->acquire()) {
            return static::toProgress($state, true);
        }

        try {
            // read again under the lock: the call that had it may have moved on (at the end
            // it removes the file: the site is installed)
            $state = static::read();
            if (is_null($state)) {
                return static::toProgress(null);
            }
            if ($state['status'] === static::STATUS_FAILED) {
                if (!$retry) {
                    return static::toProgress($state);
                }
                $state['status'] = static::STATUS_RUNNING;
                $state['error'] = null;
                static::write($state);
            }
            if ($state['status'] === static::STATUS_SUCCEEDED) {
                return static::toProgress($state);
            }

            \Exment::setTimeLimitLong();
            // the work goes on to its end when the browser or the proxy stops waiting
            ignore_user_abort(true);

            $started = microtime(true);
            try {
                $state = $this->runPhase($state, $started);
            } catch (\Throwable $ex) {
                // the progress written up to the failure (the migrations done in this call, the one failed)
                $state = static::read() ?? $state;
                $migration = $state['phase'] === static::PHASE_MIGRATE && is_string($state['migration']) ? $state['migration'] : null;
                \Log::error('Exment install (' . $state['phase'] . (is_null($migration) ? '' : ', migration ' . $migration) . ') failed: ' . $ex->getMessage(), ['exception' => $ex]);
                $state['status'] = static::STATUS_FAILED;
                $state['error'] = (is_null($migration) ? '' : $migration . ': ') . static::errorText($ex->getMessage());
            }

            if ($state['status'] === static::STATUS_SUCCEEDED) {
                // done: the file goes, the site is installed (InstalledState) from now on
                @unlink(static::statePath());
            } else {
                static::write($state);
            }
            return static::toProgress($state);
        } finally {
            $this->release();
        }
    }

    /**
     * Run (a part of) the current phase.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed> the state after it
     */
    protected function runPhase(array $state, float $started): array
    {
        switch ($state['phase']) {
            case static::PHASE_MIGRATE:
                return $this->runMigrations($state, $started);

            case static::PHASE_PUBLISH:
            case static::PHASE_SEED:
            case static::PHASE_FINISH:
                $this->runCommandPhase($state['phase']);
                if ($state['phase'] === static::PHASE_FINISH) {
                    $state['status'] = static::STATUS_SUCCEEDED;
                } else {
                    // the next phase in the next call: it reads the tables made here afresh
                    $state['phase'] = $state['phase'] === static::PHASE_PUBLISH ? static::PHASE_MIGRATE : static::PHASE_FINISH;
                }
                return $state;
        }
        return $state;
    }

    /**
     * The migrations not done yet, one after another, while the time of the call lasts.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    protected function runMigrations(array $state, float $started): array
    {
        [$all, $ran] = $this->listMigrations();
        $pending = array_values(array_diff(array_keys($all), $ran));
        $state['migrations_total'] = count($all);
        $state['migrations_done'] = count($all) - count($pending);
        $state['migration'] = null;
        static::write($state);

        foreach ($pending as $name) {
            if (microtime(true) - $started >= static::budgetSeconds()) {
                return $state;
            }
            // the migration going on, written: a failure reads it to name it
            $state['migration'] = $name;
            static::write($state);
            $this->runMigration($all[$name]);
            $state['migration'] = null;
            $state['migrations_done']++;
            // the progress for the calls that wait (busy)
            static::write($state);
        }

        $state['phase'] = static::PHASE_SEED;
        return $state;
    }

    /**
     * @return array{0: array<string, string>, 1: string[]} [name => file of all the migrations, the names done]
     */
    protected function listMigrations(): array
    {
        if (!is_null(static::$migrationLister)) {
            [$all, $ran] = call_user_func(static::$migrationLister);
            return [array_combine($all, $all), $ran];
        }

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (!$repository->repositoryExists()) {
            $repository->createRepository();
        }
        // as "php artisan migrate": the folder of the application, and those of the packages
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        return [$files, $repository->getRan()];
    }

    protected function runMigration(string $file): void
    {
        if (!is_null(static::$migrationRunner)) {
            call_user_func(static::$migrationRunner, $file);
            return;
        }

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        // what this request has kept of the tables (hasTable, hasColumn) is from before the migrations
        \Exceedone\Exment\Model\System::clearRequestSession();
        $migrator->requireFiles([$file]);
        // one migration: it is written down as soon as it is done (as "migrate --step")
        $migrator->runPending([$file], ['pretend' => false, 'step' => true]);
    }

    /**
     * The files to publish, the first data or the end, by the hidden command of the installer.
     */
    protected function runCommandPhase(string $phase): void
    {
        if (!is_null(static::$phaseRunner)) {
            call_user_func(static::$phaseRunner, $phase);
            return;
        }

        $exitCode = \Artisan::call('exment:install-step', ['step' => $phase]);
        if ($exitCode !== 0) {
            $output = trim(\Artisan::output());
            throw new \RuntimeException($output !== '' ? $output : "exment:install-step $phase: exit $exitCode");
        }
    }

    /**
     * The progress for the screen (of the browser that started the install).
     *
     * @param array<string, mixed>|null $state
     * @return array<string, mixed>
     */
    public static function toProgress(?array $state, bool $busy = false): array
    {
        if (is_null($state)) {
            // no install going on: done when the site is installed, else nothing to show
            $installed = InstalledState::isInstalled();
            return [
                'status' => $installed ? static::STATUS_SUCCEEDED : 'none',
                'phase' => $installed ? static::PHASE_FINISH : null,
                'busy' => false,
                'done' => $installed ? 1 : 0,
                'total' => 1,
                'percent' => $installed ? 100 : 0,
                'migrations_done' => 0,
                'migrations_total' => 0,
                'error' => null,
            ];
        }

        // the units of the progress: one each for the files, the first data and the end, one per migration
        $total = 3 + max(1, intval($state['migrations_total']));
        $done = match ($state['phase']) {
            static::PHASE_PUBLISH => 0,
            static::PHASE_MIGRATE => 1 + intval($state['migrations_done']),
            static::PHASE_SEED => 1 + max(1, intval($state['migrations_total'])),
            default => 2 + max(1, intval($state['migrations_total'])),
        };
        if ($state['status'] === static::STATUS_SUCCEEDED) {
            $done = $total;
        }

        return [
            'status' => $state['status'],
            'phase' => $state['phase'],
            'busy' => $busy,
            'done' => $done,
            'total' => $total,
            'percent' => intval(floor($done * 100 / $total)),
            'migrations_done' => intval($state['migrations_done']),
            'migrations_total' => intval($state['migrations_total']),
            'error' => $state['error'],
        ];
    }

    /**
     * The reason to show: its first line, without the details of the connection and the SQL
     * that Laravel adds ("(Connection: ..., Host: ..., SQL: ...)") and without the password of
     * the database. All of it is in the log.
     */
    public static function errorText(string $message): string
    {
        $line = trim(explode("\n", str_replace("\r", "\n", trim($message)))[0]);
        $line = trim(preg_replace('/\s*\(Connection: .*$/s', '', $line) ?? $line);
        $password = config('database.connections.' . config('database.default') . '.password');
        if (!is_nullorempty($password)) {
            $line = str_replace(strval($password), '********', $line);
        }
        return mb_strimwidth($line, 0, 300, '...');
    }

    /**
     * @param 1|2 $operation LOCK_SH (to see whether a call runs) or LOCK_EX (to run)
     * @return bool false: another process holds the lock
     */
    protected function acquire(int $operation = LOCK_EX): bool
    {
        $path = static::lockPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the lock file ' . $path . ': check that storage/app can be written.');
        }
        $wouldBlock = 0;
        if (!flock($handle, $operation | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock) {
                return false;
            }
            throw new \RuntimeException('Cannot lock the file ' . $path . ': the file system of storage/app may not support file locks.');
        }
        $this->lock = $handle;
        return true;
    }

    protected function release(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }
}
