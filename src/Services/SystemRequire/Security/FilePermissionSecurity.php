<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\FilePermissionTuning;

/**
 * .env, the config cache and storage not readable by the other users of the server.
 * The files: the check of the tuning page (FilePermissionTuning); the fix: AutoTuning's
 * file_permission (640, only for what the site's PHP owns), not a second chmod here.
 * storage: only reported (its folders are written by the web server and by cron).
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
        return $this->storageOpen() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
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
        return count($items) > 0 ? implode(', ', $items) : exmtrans('security_check.result_ok');
    }

    public function getFixText(): ?string
    {
        if ($this->checkResult() == SystemRequireResult::OK) {
            return null;
        }
        return "cd " . base_path() . "\nchmod 640 .env bootstrap/cache/config.php\nchmod -R o-rwx storage\n# the group must be the one of the web server (apache, nginx, www-data)";
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
