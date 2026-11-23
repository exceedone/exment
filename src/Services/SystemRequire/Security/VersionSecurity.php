<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Services\PluginDistribution\AdminNotices;

/**
 * Exment the latest version, and no official plugin with a fix for the safety waiting
 * (the release information of the plugin distribution, AdminNotices::security).
 */
class VersionSecurity extends SecurityBase
{
    /** @var int|null */
    public $exment;

    /** @var array<int, array<string, string>>|null */
    public $plugins;

    protected function key(): string
    {
        return 'version';
    }

    protected function exment(): int
    {
        if (!isset($this->exment)) {
            try {
                $this->exment = \Exment::checkLatestVersion();
            } catch (\Throwable $e) {
                $this->exment = SystemVersion::ERROR;
            }
        }
        return $this->exment;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function plugins(): array
    {
        if (!isset($this->plugins)) {
            try {
                $this->plugins = AdminNotices::security();
            } catch (\Throwable $e) {
                $this->plugins = [];
            }
        }
        return $this->plugins;
    }

    public function checkResult(): string
    {
        if (count($this->plugins()) > 0) {
            return SystemRequireResult::NG;
        }
        return $this->exment() == SystemVersion::HAS_NEXT ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $items = [];
        list($latest, $current) = [null, null];
        try {
            list($latest, $current) = \Exment::getExmentVersion();
        } catch (\Throwable $e) {
        }
        $items[] = 'Exment ' . ($current ?? '-') . ($this->exment() == SystemVersion::HAS_NEXT ? ' → ' . $latest : '');
        foreach ($this->plugins() as $plugin) {
            $items[] = $plugin['label'] . ' ' . $plugin['installed'] . ' → ' . $plugin['version'];
        }
        return implode(', ', $items);
    }

    public function getFixText(): ?string
    {
        return $this->checkResult() == SystemRequireResult::OK ? null : exmtrans('security_check.item.version.fix');
    }

    public function getSettingUrl(): ?string
    {
        return count($this->plugins()) > 0 ? admin_url('feature') : admin_url('system');
    }
}
