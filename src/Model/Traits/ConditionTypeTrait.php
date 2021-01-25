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
    protected $condition_type_key = "view_column_type";

    private $_condition_item;

    /**
     * Undocumented function
     *
     * @return void
     */
    public function getConditionItemAttribute(){
        if(!is_null($this->_condition_item)){
            return $this->_condition_item;
        }

        $this->_condition_item = ConditionItemBase::make($this->custom_table_cache, $this->{$this->condition_type_key});
        return $this->_condition_item;
    }
}
