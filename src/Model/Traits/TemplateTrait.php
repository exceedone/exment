<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;

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
     * filter export items.
     */
    public static function filterExportItems($array, $is_lang)
    {
        if ($is_lang) {
            $items = [];
            foreach($array as $key => $value) 
            {
                if (!array_key_exists($key, static::$templateItems)) {
                    continue;
                }
                $filter = static::$templateItems[$key];

                if (array_get($filter, 'key') != true && array_get($filter, 'lang') != true) {
                    continue;
                }

                if (array_key_exists('filter', $filter)) {
                    $value = array_only($value, $filter['filter']);
                }
                if (array_get($filter, 'emptyskip') == true) {
                    if (is_null($value)) {
                        continue;
                    }
                    if (is_array($value)) {
                        if (!collect($value)->contains(function ($data) {
                            return !empty($data) || (!is_array($data) && isset($data));
                        }))
                        continue;
                    }
                }
                $items[$key] = $value;
            }
            return $items;
        } else {
            $items = collect($array)->filter(function ($value, $key) {
                return array_key_exists($key, static::$templateItems);
            });
            return array_only($array, $items->keys()->all());
        }
    }
}
