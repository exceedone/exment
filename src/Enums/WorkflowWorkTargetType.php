<?php

namespace Exceedone\Exment\Enums;

/**
 * Workflow target Difinition.
 *
 * @method static WorkflowWorkTargetType ACTION_SELECT()
 * @method static WorkflowWorkTargetType GET_BY_USERINFO()
 * @method static WorkflowWorkTargetType FIX()
 */
class WorkflowWorkTargetType extends EnumBase
{
    public const ACTION_SELECT = "action_select";
    public const GET_BY_USERINFO = "get_by_userinfo";
    public const FIX = "fix";


    public static function getTargetTypeDefault($index)
    {
        $result = [
            'work_target_type' => static::FIX // ($index === 0 ? static::FIX : static::ACTION_SELECT)
        ];

        if ($index === 0) {
            $result[ConditionTypeDetail::SYSTEM()->lowerKey()] = WorkflowTargetSystem::CREATED_USER;
        }

        return json_encode($result);
    }

    public static function getTargetTypeNameDefault($index)
    {
        $targetTypeDefault = jsonToArray(static::getTargetTypeDefault($index));

        if (array_has($targetTypeDefault, ConditionTypeDetail::SYSTEM()->lowerKey())) {
            $enum = WorkflowTargetSystem::getEnum(array_get($targetTypeDefault, ConditionTypeDetail::SYSTEM()->lowerKey()));
            return exmtrans('common.' . $enum->lowerkey());
        }

        // $enum = static::getEnum(array_get($targetTypeDefault, 'work_target_type'));
        // return $enum->transKey('workflow.work_target_type_options');
        return exmtrans("common.no_setting");
    }
}
