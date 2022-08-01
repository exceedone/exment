<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class TimeoutTime extends SystemRequireBase
{
    public function __construct()
    {
        $this->result = ini_get('max_execution_time');
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.timeout_time.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.timeout_time.explain');
    }

    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        if ($this->result == 0) {
            return $this->result .  '(Unlimited)';
        }
        return $this->result . exmtrans('common.second');
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        if ($this->result >= 180 || $this->result == 0) {
            return SystemRequireResult::OK;
        }
        return SystemRequireResult::WARNING;
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('system_require.type.timeout_time.warning');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('additional_php_ini');
    }
}
