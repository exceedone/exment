<?php

namespace Exceedone\Exment\Enums;

use MyCLabs\Enum\Enum;

class EnumBase extends Enum
{
    /**
     * get lower key Name
     */
    public function lowerKey(){
        return strtolower($this->getKey());
    }
    
    public function toString(){
        return $this->__toString();
    }

    /**
     * convert trans Array. 
     * value is enum value.
     * text translates using enum value.
     */
    public static function transArray($base_key, $isExment = true){
        return getTransArray(static::arrays(), $base_key, $isExment);
    }

    /**
     * convert trans Array. 
     * value is enum value.
     * text translates using enum key.
     */
    public static function transKeyArray($base_key, $isExment = true){
        $array = [];
        foreach(static::toArray() as $key => $value){
            $array[$value] = strtolower($key);
        }
        return getTransArrayValue($array, $base_key, $isExment);
    }

    /**
     * convert trans. use enum key (and convert key snake_case)
     */
    public function transKey($base_key, $isExment = true){
        $key = $base_key.'.'.$this->lowerKey();
        if($isExment){
            return exmtrans($key);
        }
        return trans($key);
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
            
            $key = strtolower($enum->getKey());
            if($key == $value){
                return $enum;
            }
        }
        return null;
    }
}
