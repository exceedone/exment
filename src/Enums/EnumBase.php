<?php

namespace Exceedone\Exment\Enums;

use MyCLabs\Enum\Enum;

class EnumBase extends Enum
{
    public function toString(){
        return $this->__toString();
    }

    /**
     * convert trans 
     */
    public static function trans($base_key, $isExment = true){
        return getTransArray(static::arrays(), $base_key, $isExment);
    }

    public static function arrays(){
        return array_flatten(static::toArray()); 
    }

    public static function getEnum($value){
        $enums = static::values();
        foreach($enums as $enum){
            if($enum == $value){
                return $enum;
            }
        }
        return $enum;
    }
}
