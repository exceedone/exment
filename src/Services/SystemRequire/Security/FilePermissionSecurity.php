<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\FilePermissionTuning;

/**
 * .env, the config cache and storage not readable by the other users of the server.
 * The files: the check of the tuning page (FilePermissionTuning); the fix: AutoTuning's
 * file_permission (640, only for what the site's PHP owns), not a second chmod here.
 * storage: only reported (its folders are written by the web server and by cron): the
 * folder itself open to everyone, or the logs, the sessions and the cache readable by the
 * other users of the server (a shared rental server: a session file is a login).
 */
class FilePermissionSecurity extends SecurityBase
{
    /** @var FilePermissionTuning|null */
    public $files;

    /** @var string|null the storage folder (tests) */
    public $storage;

    protected function key(): string
    {
        return 'file_permission';
    }

    protected function files(): FilePermissionTuning
    {
        return $this->files ?? ($this->files = new FilePermissionTuning());
    }

    /** The folders under storage whose files hold secrets (the logs, the sessions, the cache). */
    public const SECRET_DIRS = ['logs', 'framework/sessions', 'framework/cache'];

    /** At most this many files are looked at in a folder (a big cache is not walked). */
    public const MAX_FILES = 200;

    /**
     * The folders and files under SECRET_DIRS that the other users of the server can read
     * (o+r, or a folder o+x): path under storage => mode.
     *
     * @return array<string, string>
     */
    public function storageReadable(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }
        $base = $this->storage ?? storage_path();
        $result = [];
        foreach (static::SECRET_DIRS as $dir) {
            $path = $base . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0005) !== 0) {
                $result[$dir] = substr(sprintf('%o', $perms), -3);
            }
            $count = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (++$count > static::MAX_FILES) {
                    break;
                }
                if (!$file->isFile() || $file->getFilename() === '.gitignore') {
                    continue;
                }
                $mode = @fileperms($file->getPathname());
                if ($mode !== false && ($mode & 0004) !== 0) {
                    // one file tells the folder
                    $result[$dir . '/*'] = substr(sprintf('%o', $mode), -3);
                    break;
                }
            }
        }
        return $result;
    }

    /** storage readable and writable by everyone (0007 all set) */
    public function storageOpen(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return false;
        }
        $path = $this->storage ?? storage_path();
        clearstatcache(true, $path);
        $perms = @fileperms($path);
        return $perms !== false && ($perms & 0007) === 0007;
    }

    public function checkResult(): string
    {
        if (count($this->files()->openFiles()) > 0) {
            return SystemRequireResult::NG;
        }
        return $this->storageOpen() || count($this->storageReadable()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    /** .env or the config cache readable by others (storage alone is not high) */
    public function isHigh(): bool
    {
        return count($this->files()->openFiles()) > 0;
    }

    public function getResultText(): ?string
    {
        $items = collect($this->files()->openFiles())->map(function ($mode, $file) {
            return "{$file} ({$mode})";
        })->values()->all();
        if ($this->storageOpen()) {
            $items[] = 'storage (' . substr(sprintf('%o', @fileperms($this->storage ?? storage_path())), -3) . ')';
        }
        foreach ($this->storageReadable() as $path => $mode) {
            $items[] = "storage/{$path} ({$mode})";
        }
        return count($items) > 0 ? implode(', ', $items) : exmtrans('security_check.result_ok');
    }

    public function getFixText(): ?string
    {
        if ($this->checkResult() == SystemRequireResult::OK) {
            return null;
        }
        return implode("\n", [
            'cd ' . base_path(),
            'chmod 640 .env bootstrap/cache/config.php',
            '# the logs, the sessions and the cache: not readable by the other users',
            'chmod -R o-rwx storage',
            '# the group must be the one of the web server (apache, nginx, www-data)',
            '# the files written later: LOG_CHANNEL permission / the umask of PHP (027) keep them so',
        ]);
    }

    public function canFix(): bool
    {
        return count((new AutoTuning())->ownFiles()) > 0;
    }

    public function fix(): void
    {
        $result = (new AutoTuning())->apply(['file_permission']);
        if (count($result['failed']) > 0) {
            throw new \RuntimeException('file_permission');
        }
    }
}
