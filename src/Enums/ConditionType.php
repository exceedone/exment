<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\ConditionItems;

class ConditionType extends EnumBase
{
    const COLUMN = "0";
    const SYSTEM = "1";
    const PARENT_ID = "2";
    const WORKFLOW = "3";
    const CONDITION = "4";
    
    
    public function getConditionItem($custom_table, $target, $target_column_id)
    {
        switch ($this) {
            case static::COLUMN:
                return new ConditionItems\ColumnItem($custom_table, $target);
            case static::SYSTEM:
                return new ConditionItems\SystemItem($custom_table, $target);
            case static::WORKFLOW:
                return new ConditionItems\WorlflowItem($custom_table, $target);
            case static::CONDITION:
                $detail = ConditionTypeDetail::getEnum($target_column_id);
                if(!isset($detail)){
                    return null;
                }
                return $detail->getConditionItem($custom_table, $target);
        }
    }
}
