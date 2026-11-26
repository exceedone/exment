<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\LogManagement\LogPrivacy;

/**
 * The logs and personal data (ISS02839, part 4): a warning when the logs keep the input, when a
 * debug mode writes every request or every SQL with its values, or when the files of the logs or
 * of their archive can be read by everyone on the server. NG when the debug mode of the requests
 * is on in production (it writes what was sent, and the headers).
 */
class LogSecurity extends SecurityBase
{
    /** @var array<int, string>|null the paths checked (tests) */
    public static $paths;

    protected function key(): string
    {
        return 'logs';
    }

    /**
     * What is wrong: keys of the texts security_check.item.logs.*.
     *
     * @return array<int, string>
     */
    public function findings(): array
    {
        $found = [];
        if (LogPrivacy::storeInput()) {
            $found[] = 'store_input';
        }
        if (boolval(config('exment.debugmode_request')) || boolval(config('exment.debugmode_sql')) || boolval(config('exment.debugmode'))) {
            $found[] = 'debug';
        }
        if ($this->readableByEveryone()) {
            $found[] = 'readable';
        }
        // the logs larger than the limit (ISS02905)
        if (\Exceedone\Exment\Services\LogManagement\LogSize::over()) {
            $found[] = 'too_large';
        }
        return $found;
    }

    protected function readableByEveryone(): bool
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return false;
        }
        foreach (static::$paths ?? [storage_path('logs'), storage_path('app/log_archive')] as $path) {
            if (!file_exists($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $items = [$path];
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $item) {
                    $items[] = $item;
                }
            }
            foreach ($items as $item) {
                $perms = @fileperms($item);
                if ($perms !== false && ($perms & 0004) !== 0) {
                    return true;
                }
            }
        }
        return false;
    }

    public function checkResult(): string
    {
        if (boolval(config('exment.debugmode_request')) && app()->environment('production')) {
            return SystemRequireResult::NG;
        }
        return count($this->findings()) > 0 ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    protected function getMessageWarning(): ?string
    {
        return implode(' ', array_map(function ($key) {
            if ($key === 'too_large') {
                return strval(\Exceedone\Exment\Services\LogManagement\LogSize::warning('security_check.item.logs.too_large'));
            }
            return strval(exmtrans('security_check.item.logs.' . $key));
        }, $this->findings()));
    }

    public function getFixText(): ?string
    {
        if ($this->checkResult() == SystemRequireResult::OK) {
            return null;
        }
        return exmtrans('security_check.item.logs.fix', ['storage' => storage_path()]);
    }

    public function getSettingUrl(): ?string
    {
        return admin_url('log_management');
    }
}
