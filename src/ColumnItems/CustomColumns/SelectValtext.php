<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

class SelectValtext extends Select
{
    use ImportValueTrait;
    
    protected function getReturnsValue($select_options, $val, $label)
    {
        // switch column_type and get return value
        $returns = [];
        // loop keyvalue
        foreach ($val as $v) {
            // set whether $label
            if (is_null($v)) {
                $returns[] = null;
            } else {
                $returns[] = $label ? array_get($select_options, $v) : $v;
            }
        }
        return $returns;
    }
    
    protected function getImportValueOption()
    {
        return $this->custom_column->createSelectOptions();
    }

    /**
     * Get Search queries for free text search
     *
     * @param [type] $mark
     * @param [type] $value
     * @param [type] $takeCount
     * @return void
     */
    public function getSearchQueries($mark, $value, $takeCount){
        $query = $this->custom_table->getValueModel()->query();
        $query->where($this->custom_column->getIndexColumnName(), $mark, $value)->select('id');
        $query->take($takeCount);

        return [$query]; 
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param [type] $mark
     * @param [type] $value
     * @param [type] $takeCount
     * @return void
     */
    public function setSearchOrWhere(&$query, $mark, $value, $q)
    {
        // loop for key index
        foreach($this->custom_column->createSelectOptions() as $key => $label){
            if($label == $q){
                $query->orWhere($this->custom_column->getIndexColumnName(), '=', $key);
                return;
            }
        }

        return parent::setSearchOrWhere($query, $mark, $value, $q);
    }

}
