<?php

namespace Exceedone\Exment\Model\Traits;

trait DatabaseJsonTrait
{
    /**
     * get value from json
     */
    protected function getJson($dbcolumnname, $key, $default = null)
    {
        $json = $this->{$dbcolumnname};
        if(!isset($json)){return $default;}
        return array_get($json, $key, $default);
    }

    /**
     * set value from json
     * 
     */
    protected function setJson($dbcolumnname, $key, $val = null, $forgetIfNull = false){
        if (!isset($key)) {
            return $this;
        }
        // if key is array, loop key value
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->setValue($k, $v);
            }
            return $this;
        }

        // if $val is null and $forgetIfNull is true, forget value
        if($forgetIfNull && is_null($val)){
            return $this->forgetJson($dbcolumnname, $key);
        }

        $value = $this->{$dbcolumnname};
        if (is_null($value)) {
            $value = [];
        }
        $value[$key] = $val;
        $this->{$dbcolumnname} = $value;

        return $this;
    }
    
    /**
     * forget value from json
     * 
     */
    protected function forgetJson($dbcolumnname, $key){
        if (!isset($key)) {
            return $this;
        }
        
        $value = $this->{$dbcolumnname};
        if (is_null($value)) {
            $value = [];
        }
        array_forget($value, $key);
        $this->{$dbcolumnname} = $value;

        return $this;
    }
    /**
     * clear value from json
     * 
     */
    protected function clearJson($dbcolumnname){
        $this->{$dbcolumnname} = [];
        return $this;
    }
}
