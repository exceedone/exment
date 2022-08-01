<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class FilePermission extends SystemRequireBase
{
    protected $checkPaths = [
        'storage',
        'bootstrap/cache',
    ];

    public function __construct()
    {
        $this->result = [];

        foreach ($this->checkPaths as $path) {
            $fullPath = base_path($path);
            if (!is_writable($fullPath)) {
                $this->result[] = $path;
            }
        }
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.file_permission.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.file_permission.explain');
    }


    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        if (is_nullorempty($this->result)) {
            return exmtrans('common.success');
        }
        return exmtrans('system_require.type.file_permission.text_notwritable') . ' : ' .  implode(exmtrans('common.separate_word'), $this->result);
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        if (is_nullorempty($this->result)) {
            return SystemRequireResult::OK;
        }
        return SystemRequireResult::NG;
    }

    protected function getMessageNg(): ?string
    {
        return exmtrans('system_require.type.file_permission.ng');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('troubleshooting');
    }
}
