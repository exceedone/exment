<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;

class BackupRestore extends SystemRequireBase
{
    protected $exceptionMessage;

    public function __construct()
    {
        // check backup execute
        try {
            $backup = new Backup();
            $backup->check();
            $this->result = SystemRequireResult::OK;
        } catch (BackupRestoreNotSupportedException $ex) {
            $this->result = SystemRequireResult::WARNING;
            $this->exceptionMessage = $ex->getMessage();
        } catch (BackupRestoreCheckException $ex) {
            $this->result = SystemRequireResult::NG;
            $this->exceptionMessage = $ex->getMessage();
        }
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.backup_restore.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.backup_restore.explain');
    }

    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        switch ($this->result) {
            case SystemRequireResult::OK:
                return exmtrans('common.success');

            case SystemRequireResult::WARNING:
                return exmtrans('common.warning');

            case SystemRequireResult::NG:
                return exmtrans('common.error');
        }
        return null;
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        return $this->result;
    }

    protected function getMessageWarning(): ?string
    {
        return $this->exceptionMessage;
    }

    protected function getMessageNg(): ?string
    {
        return $this->exceptionMessage;
    }

    public function getSettingUrl(): ?string
    {
        switch ($this->result) {
            case SystemRequireResult::WARNING:
                return \Exment::getManualUrl('backup_sqlserver');
            case SystemRequireResult::NG:
                return \Exment::getManualUrl('troubleshooting');
        }
        return null;
    }
}
