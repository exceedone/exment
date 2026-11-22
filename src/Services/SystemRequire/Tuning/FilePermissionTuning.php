<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The files that hold the secrets of the site (ISS02823): .env, and the config cache
 * (config:cache writes every value of .env into bootstrap/cache/config.php).
 * Readable by every user of the server (644), other users could read the passwords and APP_KEY;
 * 640 (or 600) keeps them to the owner and the group of the web server.
 * Only whether they can be read by others is shown, never what is in them.
 */
class FilePermissionTuning extends TuningBase
{
    /** @var array<string>|null the files to look at (a test sets them) */
    public $files;

    protected function key(): string
    {
        return 'file_permission';
    }

    /**
     * @return array<string>
     */
    protected function files(): array
    {
        if (isset($this->files)) {
            return $this->files;
        }
        return [
            app()->environmentFilePath(),
            app()->getCachedConfigPath(),
            app()->getCachedRoutesPath(),
        ];
    }

    /**
     * The files that every user of the server can read: path (relative to Exment) => mode ("644").
     *
     * @return array<string, string>
     */
    public function openFiles(): array
    {
        // Windows has no such permissions
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }
        $open = [];
        foreach ($this->files() as $file) {
            if (!is_file($file)) {
                continue;
            }
            clearstatcache(true, $file);
            $perms = @fileperms($file);
            if ($perms !== false && ($perms & 0004)) {
                $open[$this->relative($file)] = substr(sprintf('%o', $perms), -3);
            }
        }
        return $open;
    }

    protected function relative(string $file): string
    {
        $base = rtrim(base_path(), '/') . '/';
        return strpos($file, $base) === 0 ? substr($file, strlen($base)) : $file;
    }

    public function checkResult(): string
    {
        return count($this->openFiles()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $open = $this->openFiles();
        if (count($open) == 0) {
            return exmtrans('tuning.item.file_permission.ok');
        }
        return collect($open)->map(function ($mode, $file) {
            return "{$file} ({$mode})";
        })->implode(', ');
    }

    public function getFixText(): ?string
    {
        $files = array_keys($this->openFiles());
        return implode("\n", [
            'cd ' . base_path(),
            'chmod 640 ' . (count($files) > 0 ? implode(' ', $files) : '.env bootstrap/cache/config.php'),
            '# the group must be the one of the web server (apache, nginx, www-data)',
        ]);
    }
}
