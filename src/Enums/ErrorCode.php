<?php

namespace Exceedone\Exment\Enums;

/**
 * Error Code Difinition.
 *
 * @method static ErrorCode PERMISSION_DENY()
 * @method static ErrorCode INVALID_PARAMS()
 * @method static ErrorCode NOT_INDEX_ENABLED()
 * @method static ErrorCode VALIDATION_ERROR()
 * @method static ErrorCode FORM_ACTION_DISABLED()
 * @method static ErrorCode DELETE_DISABLED()
 * @method static ErrorCode WRONG_SCOPE()
 * @method static ErrorCode DATA_NOT_FOUND()
 * @method static ErrorCode ACCESS_DENIED()
 * @method static ErrorCode OVER_LENGTH()
 * @method static ErrorCode ALREADY_DELETED()
 * @method static ErrorCode PLUGIN_NOT_FOUND()
 * @method static ErrorCode WRONG_VIEW_AND_TABLE()
 * @method static ErrorCode UNSUPPORTED_VIEW_KIND_TYPE()
 * @method static ErrorCode WORKFLOW_LOCK()
 * @method static ErrorCode WORKFLOW_NOSTART()
 * @method static ErrorCode WORKFLOW_END()
 * @method static ErrorCode WORKFLOW_ACTION_DISABLED()
 * @method static ErrorCode DISAPPROVAL_IP()
 */
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
    const OVER_LENGTH = '109';
    const ALREADY_DELETED = '110';
    const PLUGIN_NOT_FOUND = '111';
    const WRONG_VIEW_AND_TABLE = '112';
    const UNSUPPORTED_VIEW_KIND_TYPE = '113';
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
