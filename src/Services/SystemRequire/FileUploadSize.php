<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

class FileUploadSize extends SystemRequireBase
{
    public function __construct()
    {
        $maxSize = \Exment::getUploadMaxFileSize();
        $this->result = $maxSize;
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.file_upload_size.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.file_upload_size.explain');
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
        return bytesToHuman($this->result);
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        $limitsize = 5 * 1024 * 1024; // 5MB
        if ($this->result >= $limitsize || $this->result == -1) {
            return SystemRequireResult::OK;
        }
        return SystemRequireResult::WARNING;
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('system_require.type.file_upload_size.warning');
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('additional_php_ini');
    }
}
