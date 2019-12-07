<?php

namespace Exceedone\Exment\Enums;

class ErrorCode extends EnumBase
{
    const PERMISSION_DENY = '101';
    const INVALID_PARAMS = '102';
    const NOT_INDEX_ENABLED = '102';
    const VALIDATION_ERROR = '103';
    const FORM_ACTION_DISABLED = '104';
    const DELETE_DISABLED = '105';
    const WRONG_SCOPE = '106';
    const DATA_NOT_FOUND = '107';
    const ACCESS_DENIED = '108';
    const WORKFLOW_LOCK = '201';
    const WORKFLOW_NOSTART = '202';
    const WORKFLOW_END = '203';
    const WORKFLOW_ACTION_DISABLED = '204';
    const DISAPPROVAL_IP = '301';

    public function getMessage()
    {
        if ($this == static::PERMISSION_DENY) {
            return trans('admin.deny');
        }
        if ($this == static::WORKFLOW_LOCK) {
            return exmtrans('workflow.message.locked');
        }

        $key = $this->lowerKey();
        return exmtrans('api.errors.' . $key);
    }
}
