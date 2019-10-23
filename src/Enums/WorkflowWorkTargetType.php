<?php

namespace Exceedone\Exment\Enums;

class WorkflowWorkTargetType extends EnumBase
{
    const ALL = "all";
    const ACTION_SELECT = "action_select";
    const FIX = "fix";


    public static function getTargetTypeDefault($index)
    {
        $result = [
            'work_target_type' => ($index === 0 ? static::FIX : static::ACTION_SELECT)
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

        $enum = static::getEnum(array_get($targetTypeDefault, 'work_target_type'));
        return $enum->transKey('workflow.work_target_type_options');
    }
}
