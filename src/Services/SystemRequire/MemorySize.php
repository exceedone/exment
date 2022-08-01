<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class MemorySize extends SystemRequireBase
{
    public function __construct()
    {
        $memory_limit = \Exment::getFileMegaSizeValue(ini_get('memory_limit'));
        $this->result = $memory_limit;
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.memory.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.memory.explain');
    }

    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        if ($this->result == -1) {
            return $this->result . '(Unlimited)';
        }
        return $this->result . 'MB';
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        if ($this->result >= 512 || $this->result == -1) {
            return SystemRequireResult::OK;
        }
        return SystemRequireResult::WARNING;
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('system_require.type.memory.warning');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('additional_php_ini');
    }
}
