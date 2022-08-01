<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class MaxInputVars extends SystemRequireBase
{
    public function __construct()
    {
        $input_vars_limit = ini_get('max_input_vars');
        $this->result = $input_vars_limit;
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.max_input_vars.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.max_input_vars.explain');
    }

    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        return $this->result;
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        if ($this->result >= 3000) {
            return SystemRequireResult::OK;
        }
        return SystemRequireResult::WARNING;
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('system_require.type.max_input_vars.warning');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('additional_php_ini');
    }
}
