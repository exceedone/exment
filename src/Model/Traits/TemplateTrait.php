<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\ModelBase;

trait TemplateTrait
{
    /**
     * search language data (by key matching).
     */
    public static function searchLangData($json, $lang)
    {
        $keys = collect(static::$templateItems)->filter(function ($setting) {
            return array_get($setting, 'key') == true;
        })->keys();
        $items = collect(static::$templateItems)->filter(function ($setting) {
            return array_get($setting, 'lang') == true;
        })->keys();

        $find = collect($lang)->first(function ($value) use($json, $keys) {
            foreach($keys as $key) {
                if (!array_key_exists($key, $json) || !array_key_exists($key, $value)) {
                    return false;
                }
                if ($json[$key] != $value[$key]) return false;
            }
            return true;
        });
        
        if (isset($find) && is_array($find)) {
            $find = collect($find)->filter(function ($value, $key) use($items){
                return $items->contains($key);
            })->all();
        }

        return $find;
    }

    /**
     * Get template Export Items.
     *
     * @return array template items
     */
    public function getTemplateExportItems($is_lang = false)
    {
        $array = $this->toArray();
        // if not exists 'templateItems', return array.
        if (!property_exists(get_called_class(), 'templateItems')) {
            return $array;
        }

        $templateItems = static::$templateItems;

        // if class_methods replaceTemplateSpecially, execute
        if(method_exists($this, 'replaceTemplateSpecially')){
            $array = $this->{'replaceTemplateSpecially'}($array);
        }

        // replace value id to name
        if (array_key_exists('uniqueKeyReplaces', $templateItems)) {
            foreach(array_get($templateItems, 'uniqueKeyReplaces', []) as $uniqueKeyReplace){
                // get replaced value
                $replaceNames = $uniqueKeyReplace['replaceNames'];
                $replacedValue = null;

                ///// if has uniqueKeyFunction, execute
                if (array_key_exists('uniqueKeyFunction', $uniqueKeyReplace)) {
                    // get unique key names
                    $funcName = $uniqueKeyReplace['uniqueKeyFunction'];
                    $replacedValue = $this->{$funcName}();
                }

                ///// if system enum, get system name
                elseif (array_key_exists('uniqueKeySystemEnum', $uniqueKeyReplace)) {
                    // get values for getEnum args
                    $getEnumArgs = collect($replaceNames)->map(function($replaceName) use($array){
                        return array_get($array, $replaceName['replacingName']);
                    })->toArray();
                    
                    // get enum
                    $enum = call_user_func_array([$uniqueKeyReplace['uniqueKeySystemEnum'], 'getEnum'], array_values($getEnumArgs));
                    if(isset($enum)){
                        $replacedValue = $enum->option();
                    }
                }

                ///// default: get eloquent
                else{
                    // get values for eloquent args
                    $eloquentArgs = collect($replaceNames)->map(function($replaceName) use($array){
                        return array_get($array, $replaceName['replacingName']);
                    })->toArray();

                    // call eloquent function
                    $replacedEloquent = call_user_func_array([$uniqueKeyReplace['uniqueKeyClassName'], 'getEloquent'], array_values($eloquentArgs));
                    if(isset($replacedEloquent)){
                        // get unique key names
                        $replacedValue = $replacedEloquent->getUniqueKeyNames();
                    }
                }

                // set array
                if(isset($replacedValue)){
                    foreach($replaceNames as $replaceName){
                        foreach(array_get($replaceName, 'replacedName', []) as $replacedNameKey => $replacedNameValue){
                            array_set($array, $replacedNameValue, array_get($replacedValue, $replacedNameKey));
                        }
                    }    
                }
                
                foreach($replaceNames as $replaceName){
                    array_forget($array, array_get($replaceName, 'replacingName'));
                }
            }
        }

        // set children values
        if (array_key_exists('children', $templateItems)) {
            foreach(array_get($templateItems, 'children', []) as $templateItemChild){
                // get children value
                $children = $this->{$templateItemChild};

                if(!isset($children)){
                    array_forget($array, $templateItemChild);
                    continue;
                }

                // get value's child
                $replacedChildren = [];
                foreach($children as $child){
                    $replacedChildren[] = $child->getTemplateExportItems($is_lang);
                }
                array_set($array, $templateItemChild, $replacedChildren);
            }
        }

        // except columns
        if (array_key_exists('excepts', $templateItems)) {
            $array = array_except($array, array_get($templateItems, 'excepts', []));
        }

        // remove if null
        $array = array_filter($array);

        // for outputing language, execute array_only
        if($is_lang){
            $lang_keys = array_merge(
                array_get($templateItems, 'keys', []),
                array_get($templateItems, 'langs', [])
            );
            $array = array_only($array, $lang_keys);
        }

        // return array
        return $array;
    }

    
    /**
     * get unique key name.
     * Ex1. CustomTable:table_name.
     * Ex2. CustomColumn: table_name and column_name.
     * 
     * @return array key is database column name, value is database name. 
     */
    public function getUniqueKeyNames()
    {
        if(!property_exists(get_called_class(), 'uniqueKeyName')){
            return [];
        }
        $keyName = static::$uniqueKeyName;

        // get key values
        $keyValues = [];
        foreach($keyName as $key){
            //$array_key is last of $key's dotted
            $array_keys = explode('.', $key);
            $array_key = $array_keys[count($array_keys) - 1];
            // set to keyValues
            $keyValues[$array_key] = array_get($this, $key);
        }

        return $keyValues;
    }

}
