<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * OPcache keeps compiled PHP in memory. Exment and its packages are many files,
 * so the number of files and the memory must be large enough to hold them all.
 * When it is full (ISS02823: opcache_get_status), files are compiled again on every request.
 * (The command line has its own OPcache, opcache.enable_cli: "php artisan exment:tuning"
 * shows the one of the command, the tuning page the one of the site.)
 */
class OpcacheTuning extends TuningBase
{
    public const MIN_MEMORY = 128;
    public const MIN_FILES = 20000;

    /** Used past this rate, OPcache is (nearly) full. */
    public const FULL_RATE = 0.9;

    /** @var array<string, mixed>|false|null opcache_get_status(false) (a test sets it) */
    public $status;

    protected function key(): string
    {
        return 'opcache';
    }

    protected function enabled(): bool
    {
        return extension_loaded('Zend OPcache') && boolval(ini_get('opcache.enable'));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function status(): ?array
    {
        if (is_null($this->status)) {
            // restricted by opcache.restrict_api, or off in this PHP: false
            $this->status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        }
        return is_array($this->status) ? $this->status : null;
    }

    /**
     * How full it is: ['memory' => rate, 'keys' => rate, 'used_mb' => int, 'total_mb' => int,
     * 'scripts' => int, 'max_keys' => int, 'oom_restarts' => int], or null when unknown.
     *
     * @return array<string, int|float>|null
     */
    public function usage(): ?array
    {
        $status = $this->status();
        if (is_null($status) || !isset($status['memory_usage'], $status['opcache_statistics'])) {
            return null;
        }
        $memory = $status['memory_usage'];
        $stats = $status['opcache_statistics'];
        $used = floatval($memory['used_memory'] ?? 0) + floatval($memory['wasted_memory'] ?? 0);
        $total = $used + floatval($memory['free_memory'] ?? 0);
        $maxKeys = intval($stats['max_cached_keys'] ?? 0);
        return [
            'memory' => $total > 0 ? $used / $total : 0,
            'keys' => $maxKeys > 0 ? intval($stats['num_cached_keys'] ?? 0) / $maxKeys : 0,
            'used_mb' => intval(round($used / 1048576)),
            'total_mb' => intval(round($total / 1048576)),
            'scripts' => intval($stats['num_cached_scripts'] ?? 0),
            'max_keys' => $maxKeys,
            'oom_restarts' => intval($stats['oom_restarts'] ?? 0),
        ];
    }

    public function full(): bool
    {
        $usage = $this->usage();
        return !is_null($usage)
            && ($usage['memory'] >= static::FULL_RATE || $usage['keys'] >= static::FULL_RATE || $usage['oom_restarts'] > 0);
    }

    public function checkResult(): string
    {
        if (!$this->enabled()) {
            return SystemRequireResult::WARNING;
        }
        if (intval(ini_get('opcache.memory_consumption')) < static::MIN_MEMORY
            || intval(ini_get('opcache.max_accelerated_files')) < static::MIN_FILES) {
            return SystemRequireResult::WARNING;
        }
        if ($this->full()) {
            return SystemRequireResult::WARNING;
        }
        return SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $text = parent::getResultText();
        $usage = $this->usage();
        if ($this->enabled() && !is_null($usage)) {
            $text .= ' / ' . exmtrans('tuning.item.opcache.usage', [
                'used' => $usage['used_mb'],
                'total' => $usage['total_mb'],
                'scripts' => $usage['scripts'],
                'max' => $usage['max_keys'],
            ]);
        }
        return $text;
    }

    protected function getMessageWarning(): ?string
    {
        if ($this->enabled() && $this->full()
            && intval(ini_get('opcache.memory_consumption')) >= static::MIN_MEMORY
            && intval(ini_get('opcache.max_accelerated_files')) >= static::MIN_FILES) {
            return exmtrans('tuning.item.opcache.warning_full');
        }
        return parent::getMessageWarning();
    }

    public function getFixText(): ?string
    {
        $memory = 256;
        $files = static::MIN_FILES;
        // full: double what is set now
        if ($this->full()) {
            $memory = max($memory, intval(ini_get('opcache.memory_consumption')) * 2);
            $files = max($files, intval(ini_get('opcache.max_accelerated_files')) * 2);
        }
        return implode("\n", [
            '; php.ini',
            'opcache.enable=1',
            'opcache.memory_consumption=' . $memory,
            'opcache.interned_strings_buffer=16',
            'opcache.max_accelerated_files=' . $files,
            'opcache.revalidate_freq=60',
        ]);
    }
}
