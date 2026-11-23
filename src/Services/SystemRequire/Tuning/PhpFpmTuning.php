<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The pools of PHP-FPM (ISS02823), read from their files (pm is not in php.ini):
 * - pm = ondemand with a short pm.process_idle_timeout stops the PHP processes soon after a
 *   request, so the first request after a quiet time waits for a new process (and its OPcache);
 * - pm.max_children against the memory: too many and the server swaps under load,
 *   too few and requests wait in a line.
 * Where PHP-FPM is not used (mod_php) or its files cannot be read, there is nothing to advise.
 */
class PhpFpmTuning extends TuningBase
{
    /** An ondemand pool should keep its processes at least this long (seconds). */
    public const MIN_IDLE_SECONDS = 600;

    /** What one PHP process of Exment uses, roughly (MB). */
    public const PROCESS_MB = 80;

    /** Fewer children than this make requests wait. */
    public const MIN_CHILDREN = 4;

    /** @var array<string>|null the files of the pools (a test sets them) */
    public $files;

    /** @var int|null the memory of the server in MB (a test sets it) */
    public $memoryMb;

    /** @var string|null the PHP_SAPI of the site (a test sets it) */
    public $sapi;

    /** @var array<string, array<string, string>>|null */
    protected $pools;

    protected function key(): string
    {
        return 'php_fpm';
    }

    /**
     * Where the files of the pools usually are (Docker's php image, Amazon Linux / RHEL, Debian / Ubuntu).
     *
     * @return array<string>
     */
    protected function poolFiles(): array
    {
        if (isset($this->files)) {
            return $this->files;
        }
        $patterns = ['/usr/local/etc/php-fpm.d/*.conf', '/etc/php-fpm.d/*.conf', '/etc/php/*/fpm/pool.d/*.conf'];
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_readable($file)) {
                    $files[] = $file;
                }
            }
        }
        return $files;
    }

    /**
     * The pools: name => [setting => value], only those with a "pm".
     *
     * @return array<string, array<string, string>>
     */
    public function pools(): array
    {
        if (isset($this->pools)) {
            return $this->pools;
        }
        $this->pools = [];
        foreach ($this->poolFiles() as $file) {
            $ini = @parse_ini_string((string) @file_get_contents($file), true, INI_SCANNER_RAW);
            if (!is_array($ini)) {
                continue;
            }
            foreach ($ini as $name => $settings) {
                if (!is_array($settings) || !isset($settings['pm']) || $name === 'global') {
                    continue;
                }
                // php_admin_value[memory_limit] and the like come as arrays: not used here
                $values = [];
                foreach ($settings as $key => $value) {
                    if (is_scalar($value)) {
                        $values[(string) $key] = trim((string) $value);
                    }
                }
                $this->pools[(string) $name] = $values;
            }
        }
        return $this->pools;
    }

    /**
     * A time of FPM ("10s", "5m", "1h", "300") in seconds.
     */
    public static function seconds(string $value): int
    {
        if (!preg_match('/^(\d+)\s*([smhd]?)$/i', trim($value), $m)) {
            return 0;
        }
        $units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
        return intval($m[1]) * $units[strtolower($m[2])];
    }

    protected function memoryMb(): ?int
    {
        if (isset($this->memoryMb)) {
            return $this->memoryMb;
        }
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo) && preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $meminfo, $m)) {
            return intdiv(intval($m[1]), 1024);
        }
        return null;
    }

    /**
     * The ondemand pools that stop their processes too soon: name => idle seconds.
     *
     * @return array<string, int>
     */
    public function shortIdlePools(): array
    {
        $short = [];
        foreach ($this->pools() as $name => $pool) {
            if (strtolower($pool['pm']) !== 'ondemand') {
                continue;
            }
            // FPM's default is 10s
            $idle = static::seconds($pool['pm.process_idle_timeout'] ?? '10s');
            if ($idle < static::MIN_IDLE_SECONDS) {
                $short[$name] = $idle;
            }
        }
        return $short;
    }

    public function totalChildren(): int
    {
        return array_sum(array_map(function ($pool) {
            return intval($pool['pm.max_children'] ?? 0);
        }, $this->pools()));
    }

    /**
     * Whether pm.max_children of all the pools would use more memory than the server has.
     */
    public function tooManyChildren(): bool
    {
        $memory = $this->memoryMb();
        return !is_null($memory) && $this->totalChildren() * static::PROCESS_MB > $memory;
    }

    /**
     * Whether a pool lets fewer than MIN_CHILDREN requests run at once.
     */
    public function tooFewChildren(): bool
    {
        foreach ($this->pools() as $pool) {
            if (intval($pool['pm.max_children'] ?? 0) < static::MIN_CHILDREN) {
                return true;
            }
        }
        return false;
    }

    public function checkResult(): string
    {
        if (count($this->pools()) == 0) {
            return SystemRequireResult::OK;
        }
        return count($this->shortIdlePools()) > 0 || $this->tooManyChildren() || $this->tooFewChildren()
            ? SystemRequireResult::WARNING
            : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $pools = $this->pools();
        if (count($pools) == 0) {
            // the web of a rental server may use PHP-FPM with files this user cannot read:
            // "not used" only when this PHP is not PHP-FPM, else "could not read"
            $sapi = $this->sapi ?? PHP_SAPI;
            return exmtrans($sapi === 'fpm-fcgi' || $sapi === 'cli' ? 'tuning.item.php_fpm.not_read' : 'tuning.item.php_fpm.not_used');
        }
        $text = collect($pools)->map(function ($pool, $name) {
            $text = "{$name}: pm={$pool['pm']}, max_children=" . ($pool['pm.max_children'] ?? '-');
            if (strtolower($pool['pm']) === 'ondemand') {
                $text .= ', idle=' . ($pool['pm.process_idle_timeout'] ?? '10s');
            }
            return $text;
        })->take(5)->implode(' / ');
        if (count($pools) > 5) {
            $text .= ' / ... (' . count($pools) . ')';
        }
        $memory = $this->memoryMb();
        if (!is_null($memory)) {
            $text .= ' / ' . exmtrans('tuning.item.php_fpm.memory', ['memory' => $memory, 'children' => $this->totalChildren()]);
        }
        return $text;
    }

    protected function getMessageWarning(): ?string
    {
        $messages = [];
        if (count($this->shortIdlePools()) > 0) {
            $messages[] = exmtrans('tuning.item.php_fpm.warning_idle', ['pools' => implode(', ', array_keys($this->shortIdlePools()))]);
        }
        if ($this->tooManyChildren()) {
            $messages[] = exmtrans('tuning.item.php_fpm.warning_many');
        }
        if ($this->tooFewChildren()) {
            $messages[] = exmtrans('tuning.item.php_fpm.warning_few', ['min' => static::MIN_CHILDREN]);
        }
        return implode(' ', $messages);
    }

    public function getFixText(): ?string
    {
        $memory = $this->memoryMb();
        $children = is_null($memory) ? 10 : max(static::MIN_CHILDREN, intdiv(intval($memory * 0.7), static::PROCESS_MB));
        return implode("\n", [
            '; the file of the pool (www.conf), then restart PHP-FPM',
            '; a site that is used often: keep the processes',
            'pm = dynamic',
            'pm.max_children = ' . $children,
            'pm.start_servers = 2',
            'pm.min_spare_servers = 1',
            'pm.max_spare_servers = 3',
            '; or, with ondemand, keep them longer',
            'pm.process_idle_timeout = 3600s',
        ]);
    }
}
