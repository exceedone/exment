<?php

namespace Exceedone\Exment\Enums;

class WorkflowWorkTargetType extends EnumBase
{
    const ALL = "all";
    const ACTION_SELECT = "action_select";
    const FIX = "fix";


    public static function getTargetTypeDefault($index){
        return json_encode([
            'work_target_type' => ($index === 0 ? static::ALL : static::ACTION_SELECT)
        ]);
    }
    public static function getTargetTypeNameDefault($index){
        $enum = ($index === 0 ? static::ALL() : static::ACTION_SELECT());

        return $enum->transKey('workflow.work_target_type_options');
    }
}
