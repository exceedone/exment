<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

class SelectValtext extends Select 
{
    protected function getReturnsValue($select_options, $val, $label){
        // switch column_type and get return value
        $returns = [];
        // loop keyvalue
        foreach ($val as $v) {
            // set whether $label
            $returns[] = $label ? array_get($select_options, $v) : $v;
        }
        return $returns;
    }
}
