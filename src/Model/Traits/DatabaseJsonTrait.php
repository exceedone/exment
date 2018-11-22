<?php

namespace Exceedone\Exment\Model\Traits;

trait DatabaseJsonTrait
{
    /**
     * get value from json
     */
    protected function getJson($dbcolumnname, $key)
    {
        $json = $this->{$dbcolumnname};
        if(!isset($json)){return null;}
        return array_get($json, $key);
    }

    /**
     * set value from json
     * 
     */
    protected function setJson($dbcolumnname, $key, $val = null){
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
        if(!property_exists($this, $dbcolumnname)){
            return $this;
        }
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
}
