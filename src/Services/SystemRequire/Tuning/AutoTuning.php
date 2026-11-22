<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Services\EnvService;

/**
 * The tuning that Exment can do by itself, offered at the setup (the initialize screen)
 * and on the tuning page, each item to choose. The rest of the tuning (OPcache, cron, the
 * queue, the PHP version...) is the server's, and stays advice on the tuning page.
 *
 * - exment_cache:    EXMENT_USE_CACHE=true in .env
 * - assets:          put back the JS and CSS that are missing (exment:publish)
 * - file_permission: .env and the config cache readable by their owner only (640),
 *                    only for a file that the PHP of the site owns, so it can still read it
 */
class AutoTuning
{
    public const ITEMS = ['exment_cache', 'assets', 'file_permission'];

    /** @var array<string, TuningBase> */
    protected $checks = [];

    /** @var array<string>|null the files for file_permission (tests); null: .env and the config cache */
    public $files;

    /**
     * The items that would change something here, in the order of ITEMS.
     *
     * @return array<string, string> key => label
     */
    public function available(): array
    {
        $result = [];
        foreach (static::ITEMS as $key) {
            try {
                if ($this->needs($key)) {
                    $result[$key] = exmtrans('tuning.auto.item.' . $key);
                }
            } catch (\Throwable $e) {
                \Log::warning('Exment auto tuning: ' . $key . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    /**
     * Do the chosen items that are needed. Each one on its own: one that fails is logged
     * and the others still run.
     *
     * @param array<mixed> $keys
     * @return array{done: array<string>, failed: array<string>}
     */
    public function apply(array $keys): array
    {
        $done = [];
        $failed = [];
        foreach (static::ITEMS as $key) {
            if (!in_array($key, $keys, true)) {
                continue;
            }
            try {
                if (!$this->needs($key)) {
                    continue;
                }
                $this->{'apply' . studly_case($key)}();
                $done[] = $key;
            } catch (\Throwable $e) {
                \Log::error('Exment auto tuning: ' . $key . ': ' . $e->getMessage());
                $failed[] = $key;
            }
        }
        return ['done' => $done, 'failed' => $failed];
    }

    protected function needs(string $key): bool
    {
        switch ($key) {
            case 'exment_cache':
                // the file EnvService writes
                return !boolval(config('exment.use_cache')) && is_writable(path_join(base_path(), '.env'));
            case 'assets':
                return count($this->assets()->missing()) > 0;
            case 'file_permission':
                return count($this->ownFiles()) > 0;
        }
        return false;
    }

    protected function applyExmentCache(): void
    {
        EnvService::setEnv(['EXMENT_USE_CACHE' => 'true']);
        config(['exment.use_cache' => true]);
    }

    protected function applyAssets(): void
    {
        \Artisan::call('exment:publish');
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
}
