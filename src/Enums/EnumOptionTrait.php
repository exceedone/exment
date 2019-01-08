<?php

namespace Exceedone\Exment\Enums;

trait EnumOptionTrait
{
    protected function option(){
        return array_get(static::options, $this, null);
    }

    public static function getOptions($filters = []){
        $options = static::$options;
        foreach ($filters as $key => $value) {
            $options = collect($options)->filter(function($option) use($key, $value){
                return array_get($option, $key) == $value;
            });
        }
        return collect($options)->toArray();
    }
    
    public static function getOption($filters = []){
        return collect(static::getOptions($filters))->first() ?? null;
    }

}
