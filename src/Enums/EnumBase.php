<?php

namespace Exceedone\Exment\Enums;

use MyCLabs\Enum\Enum;

class EnumBase extends Enum
{
    /**
     * check whether enum_value equal $value
     */
    public function match($value){
        return $this->value == $value;
    }

    public function toString(){
        return $this->__toString();
    }

    /**
     * convert trans 
     */
    public static function trans($base_key, $isExment = true){
        return getTransArray(array_flatten(static::toArray()), $base_key, $isExment);
    }


}
