<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\ConditionItems\ConditionItemBase;

/**
 * Condition type item
 */
trait ConditionTypeTrait
{
    /**
     * condition_type_key name. default is "view_column_type"
     *
     * @var string
     */
    //protected $condition_type_key = "view_column_type";

    /**
     * condition_type_key name. default is "view_column_target_id"
     *
     * @var string|null
     */
    //protected $condition_column_key = "view_column_target_id";

    private $_condition_item;

    /**
     * Undocumented function
     *
     * @return mixed|null
     */
    public function getConditionItemAttribute()
    {
        if (!is_null($this->_condition_item)) {
            return $this->_condition_item;
        }

        if (!property_exists($this, 'condition_type_key')) {
            $condition_type_key = "view_column_type";
        } else {
            $condition_type_key = $this->condition_type_key;
        }
        if (!property_exists($this, 'condition_column_key')) {
            $condition_column_key = "view_column_target_id";
        } else {
            $condition_column_key = $this->condition_column_key;
        }

        $this->_condition_item = ConditionItemBase::getItem($this->custom_table_cache, $this->{$condition_type_key}, $this->{$condition_column_key});
        return $this->_condition_item;
    }
}
