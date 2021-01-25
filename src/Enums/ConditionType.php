<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\ConditionItems;

/**
 * Condition type. This enum is parent, child enum is CONDITION->detail.
 * CONDITION
 *      USER
 *      ORGANIZATION
 *      ROLE
 *      FORM
 */
class ConditionType extends EnumBase
{
    const COLUMN = "0";
    const SYSTEM = "1";
    const PARENT_ID = "2";
    const WORKFLOW = "3";
    const CONDITION = "4";
    
    public static function isTableItem($condition_type)
    {
        return in_array($condition_type, [
            ConditionType::COLUMN,
            ConditionType::SYSTEM,
            ConditionType::PARENT_ID,
        ]);
    }


    /**
     * Get enum by tatget query key
     *
     * @return string|null
     */
    public static function getEnumByTargetKey($target) : ?string
    {
        $systemEnum = SystemColumn::getEnum($target);
        if($systemEnum){
            if(in_array($systemEnum, [SystemColumn::WORKFLOW_STATUS, SystemColumn::WORKFLOW_WORK_USERS])){
                return static::WORKFLOW;
            }
            if(in_array($systemEnum, [SystemColumn::PARENT_ID])){
                return static::PARENT_ID;
            }
            return static::SYSTEM;
        }
        if(is_numeric($target)){
            return static::COLUMN;
        }
        
        return static::CONDITION;
    }
}
