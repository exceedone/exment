<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionTypeDetail;

class WorkflowAuthority extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    public function getAuthorityTextAttribute()
    {
        $condition_type = ConditionTypeDetail::getEnum($this->related_type);
        if (!isset($condition_type)) {
            return null;
        }

        $item = $condition_type->getConditionItem(null, null);
        if (!isset($item)) {
            return null;
        }

        $condition_type_label = $condition_type->transKey('condition.condition_type_options');
        
        return $item->getText($this->related_type, $this->related_id, false);
    }

    /**
     * Get workflow authorities from value array
     *
     * @param [type] $value
     * @return void
     */
    public static function getAuhoritiesFromValue($values, $action = null)
    {
        $values = jsonToArray($values);

        $items = [];
        foreach ($values as $key => $value) {
            foreach ((array)$value as $v) {
                $condition_type = ConditionTypeDetail::getEnum($key);
                if (!isset($condition_type)) {
                    continue;
                }
        
                $authority = new WorkflowAuthority();
                $authority->related_id = $v;
                $authority->related_type = $key;
                $authority->workflow_action_id = isset($action) ? $action->id : null;
    
                $items[] = $authority;
            }
        }

        return $items;
    }
}
