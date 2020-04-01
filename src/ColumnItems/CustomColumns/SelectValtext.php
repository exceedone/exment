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
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param [type] $value
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label){
        foreach($this->custom_column->createSelectOptions() as $key => $q){
            if($label == $q){
                return $key;
            }
        }

        return null;
    }
}
