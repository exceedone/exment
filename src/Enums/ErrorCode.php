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
 * @method static ErrorCode NOT_CONTAINS_CUSTOM_FORM()
 * @method static ErrorCode WORKFLOW_NOT_HAS_NEXT_USER()
 */
class ErrorCode extends EnumBase
{
    public const PERMISSION_DENY = '101';
    public const INVALID_PARAMS = '102';
    public const NOT_INDEX_ENABLED = '102';
    public const VALIDATION_ERROR = '103';
    public const FORM_ACTION_DISABLED = '104';
    public const DELETE_DISABLED = '105';
    public const WRONG_SCOPE = '106';
    public const DATA_NOT_FOUND = '107';
    public const ACCESS_DENIED = '108';
    public const OVER_LENGTH = '109';
    public const ALREADY_DELETED = '110';
    public const PLUGIN_NOT_FOUND = '111';
    public const WRONG_VIEW_AND_TABLE = '112';
    public const UNSUPPORTED_VIEW_KIND_TYPE = '113';
    public const WORKFLOW_LOCK = '201';
    public const WORKFLOW_NOSTART = '202';
    public const WORKFLOW_END = '203';
    public const WORKFLOW_ACTION_DISABLED = '204';
    public const WORKFLOW_NOT_HAS_NEXT_USER = '205';
    public const DISAPPROVAL_IP = '301';
    public const NOT_CONTAINS_CUSTOM_FORM = '401';

    public function getMessage()
    {
        if ($this == static::PERMISSION_DENY) {
            return trans('admin.deny');
        }
        if ($this == static::WORKFLOW_LOCK) {
            return exmtrans('workflow.message.locked');
        }
        if ($this == static::WORKFLOW_NOT_HAS_NEXT_USER) {
            return exmtrans('workflow.message.nextuser_not_found');
        }

        $key = $this->lowerKey();
        return exmtrans('api.errors.' . $key);
    }
}
