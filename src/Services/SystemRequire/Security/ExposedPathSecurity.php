<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * Files of the server that must not be served: the site asks itself for them and
 * expects a refusal (403 / 404). Served = the web server's document root is not
 * public/, or it serves hidden files.
 */
class ExposedPathSecurity extends SecurityBase
{
    /** what => a path that must not answer 200 */
    public const PATHS = [
        '.env' => '.env',
        '.git' => '.git/HEAD',
        'storage' => 'storage/logs/laravel.log',
        'vendor' => 'vendor/composer/installed.json',
    ];

    protected function key(): string
    {
        return 'exposed_path';
    }

    /**
     * @return array<string>|null what is served, null when the site did not answer
     */
    public function exposed(): ?array
    {
        $result = [];
        $answered = false;
        foreach (static::PATHS as $name => $path) {
            $page = SiteProbe::get($path);
            if (!isset($page)) {
                continue;
            }
            $answered = true;
            if ($page['status'] === 200) {
                $result[] = $name;
            }
        }
        return $answered ? $result : null;
    }

    /** .env or .git served */
    public function isHigh(): bool
    {
        return count(array_intersect(['.env', '.git'], $this->exposed() ?? [])) > 0;
    }

    public function checked(): bool
    {
        return $this->exposed() !== null;
    }

    public function checkResult(): string
    {
        $exposed = $this->exposed();
        if ($exposed === null) {
            return SystemRequireResult::WARNING;
        }
        return count($exposed) === 0 ? SystemRequireResult::OK : SystemRequireResult::NG;
    }

    public function getResultText(): ?string
    {
        $exposed = $this->exposed();
        if ($exposed === null) {
            return exmtrans('security_check.not_checked');
        }
        return count($exposed) === 0 ? exmtrans('security_check.result_ok') : exmtrans('security_check.exposed', ['items' => implode(', ', $exposed)]);
    }

    public function getFixText(): ?string
    {
        if (!$this->checked()) {
            // by hand: the addresses, from a computer outside the server
            return implode("
", array_map(function ($path) {
                return SiteProbe::base() . '/' . $path;
            }, array_values(static::PATHS)));
        }
        if ($this->checkResult() == SystemRequireResult::OK) {
            return null;
        }
        return exmtrans('security_check.item.exposed_path.fix', ['public' => public_path()]);
    }
}
