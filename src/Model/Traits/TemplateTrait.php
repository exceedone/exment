<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Enums\TemplateImportResult;

/**
 * Template logics
 *
 * @method static string|null|void importReplaceJson(&$json, $options = [])
 * @method static void exportReplaceJson(&$json)
 */
trait TemplateTrait
{
    protected static $defaultExcepts = ['id', 'created_at', 'updated_at', 'created_user_id', 'updated_user_id', 'disabled_delete'];

    // Description for $templateItems.
    // This is template setting for template import and export.
    // public static $templateItems = [
    //     // 'excepts': ignore field. in this columns, not exporting and importing columns.
    //     'excepts' => ['suuid'],
    //
    //     // 'uniqueKeys': filtering Eloquent model for import, and template json's value for export.
    //     // (1) if it's the same value export and import, write this.
    //     'uniqueKeys' => [
    //         'table_name'
    //     ],
    //     // (2) if it's the different value export and import, write this.
    //     'uniqueKeys' => [
    //          'export' => [
    //              'custom_table.table_name', 'column_name'
    //          ],
    //          'import' => [
    //              'custom_table_id', 'column_name'
    //          ],
    //     ],

    //     // 'langs': for exporting langage value.
    //     // keys is filtering json value(unique).
    //     // keys is replacing language value.
    //     'langs' => [
    //         'keys' => ['table_name'],
    //         'values' => ['table_view_name', 'description'],
    //     ],
    //
    //     // 'children': for exporting children values.
    //     // if contains this fields, exporting json.
    //     'children' =>[
    //         'custom_columns',
    //     ],
    // ];


    /**
     * search language data (by key matching).
     */
    public static function searchLangData($json, $lang)
    {
        $keys = array_get(static::$templateItems, 'langs.keys', []);
        $items = array_get(static::$templateItems, 'langs.values', []);

        $find = collect($lang)->first(function ($value) use ($json, $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $json) || !array_key_exists($key, $value)) {
                    return false;
                }
                if ($json[$key] != $value[$key]) {
                    return false;
                }
            }
            return true;
        });

        if (isset($find) && is_array($find)) {
            $find = array_dot_only($find, $items);
        }

        return $find;
    }

    /**
     * Get template Export Items.
     *
     * @return array is_lang whether for language file
     */
    public function getTemplateExportItems($is_lang = false)
    {
        $array = $this->toArray();
        // if not exists 'templateItems', return array.
        if (!property_exists(get_called_class(), 'templateItems')) {
            return $array;
        }

        $templateItems = static::$templateItems;

        // replace value id to name
        if (array_key_exists('uniqueKeyReplaces', $templateItems)) {
            foreach (array_get($templateItems, 'uniqueKeyReplaces', []) as $uniqueKeyReplace) {
                // get replaced value
                $replaceNames = $uniqueKeyReplace['replaceNames'];
                $replacedValue = null;

                ///// if has uniqueKeyFunction, execute
                if (array_key_exists('uniqueKeyFunction', $uniqueKeyReplace)) {
                    // get unique key names
                    $funcName = $uniqueKeyReplace['uniqueKeyFunction'];
                    $funcArgs = array_get($uniqueKeyReplace, 'uniqueKeyFunctionArgs', []);
                    $replacedValue = $this->{$funcName}($funcArgs);

                    foreach ($funcArgs as $funcArg) {
                        array_forget($array, $funcArg);
                    }
                }

                ///// if system enum, get system name
                elseif (array_key_exists('uniqueKeySystemEnum', $uniqueKeyReplace)) {
                    // get values for getEnum args
                    $getEnumArgs = collect($replaceNames)->map(function ($replaceName) use ($array) {
                        return array_get($array, $replaceName['replacingName']);
                    })->toArray();

                    // get enum
                    $enum = call_user_func_array([$uniqueKeyReplace['uniqueKeySystemEnum'], 'getEnum'], array_values($getEnumArgs));
                    if (isset($enum)) {
                        $replacedValue = $enum->option();
                    }
                }

                ///// default: get eloquent
                else {
                    // get values for eloquent args
                    $eloquentArgs = collect($replaceNames)->map(function ($replaceName) use ($array) {
                        return array_get($array, $replaceName['replacingName']);
                    })->toArray();

                    // call eloquent function
                    $replacedEloquent = call_user_func_array([$uniqueKeyReplace['uniqueKeyClassName'], 'getEloquent'], array_values($eloquentArgs));
                    if (isset($replacedEloquent)) {
                        // get unique key names
                        $replacedValue = $replacedEloquent->getUniqueKeyNames();
                    }
                }

                // set array
                if (isset($replacedValue)) {
                    foreach ($replaceNames as $replaceName) {
                        foreach (array_get($replaceName, 'replacedName', []) as $replacedNameKey => $replacedNameValue) {
                            array_set($array, $replacedNameValue, array_get($replacedValue, $replacedNameKey));
                        }
                    }
                }

                foreach ($replaceNames as $replaceName) {
                    array_forget($array, array_get($replaceName, 'replacingName'));
                }
            }
        }

        // set children values
        if (array_key_exists('children', $templateItems)) {
            foreach (array_get($templateItems, 'children') as $templateItemChild => $classname) {
                // get children value
                $children = $this->{$templateItemChild};

                if (!isset($children)) {
                    array_forget($array, $templateItemChild);
                    continue;
                }

                // get value's child
                $replacedChildren = [];
                foreach ($children as $child) {
                    $replacedChildren[] = $child->getTemplateExportItems($is_lang);
                }
                array_set($array, $templateItemChild, $replacedChildren);
            }
        }

        // except columns
        if (array_key_exists('excepts', $templateItems)) {
            $expects = is_vector($templateItems['excepts']) ? array_get($templateItems, 'excepts', []) : array_get($templateItems, 'excepts.export', []);
            $array = array_except($array, $expects);
        }
        $array = array_except($array, static::$defaultExcepts);

        // remove parent's value
        if (array_key_exists('parent', $templateItems)) {
            $array = array_except($array, array_get($templateItems, 'parent', []));
        }

        // for outputing language, execute array_only
        if ($is_lang) {
            $lang_keys = array_merge(
                array_get($templateItems, 'langs.keys', []),
                array_get($templateItems, 'langs.values', [])
            );
            $array = array_only($array, $lang_keys);
        }

        // replace json if method exists
        if (method_exists(get_called_class(), 'exportReplaceJson')) {
            static::exportReplaceJson($array);
        }

        // return array
        return $array;
    }

    /**
     * set template Export Items.
     *
     * @return \Illuminate\Database\Eloquent\Model|void template items
     */
    public static function importTemplate($array, $is_update, $options = [])
    {
        //copy array for replacing items
        $json = $array;

        // if not exists 'templateItems', return array.
        if (!property_exists(get_called_class(), 'templateItems')) {
            //TODO:Exception
            return;
        }
        $templateItems = static::$templateItems;

        // replace json if method exists
        if (method_exists(get_called_class(), 'importReplaceJson')) {
            $result = static::importReplaceJson($json, $options);
            if ($result == TemplateImportResult::ERROR) {
                //TODO:error
                return;
            }
            if ($result == TemplateImportResult::CONITNUE) {
                return;
            }
        }

        // replace value name name to id
        if (array_key_exists('uniqueKeyReplaces', $templateItems)) {
            foreach (array_get($templateItems, 'uniqueKeyReplaces', []) as $uniqueKeyReplace) {
                // get replaced value
                $replaceNames = $uniqueKeyReplace['replaceNames'];

                foreach ($replaceNames as $replaceName) {
                    // get value from key 'replacedName'
                    $replacedKeyValues = array_get($replaceName, 'replacedName', []);
                    if (array_has($replacedKeyValues, 'import')) {
                        $replacedKeyValues = $replacedKeyValues['import'];
                    }

                    // get targeted value using Eloquent
                    if (!array_has($uniqueKeyReplace, 'uniqueKeyClassName')) {
                        continue;
                    }
                    $modelname = $uniqueKeyReplace['uniqueKeyClassName'];
                    $eloquentQuery = $modelname::query();
                    foreach ($replacedKeyValues as $replacedKey => $replacedValue) {
                        $eloquentQuery->where($replacedKey, array_get($json, $replacedValue));
                    }
                    $eloquent = $eloquentQuery->first();
                    // if has $eloquent, replace json value
                    if (isset($eloquent)) {
                        array_set($json, $replaceName['replacingName'], $eloquent['id']);
                    }
                    //remove replaced value
                    foreach ($replacedKeyValues as $replacedKey => $replacedValue) {
                        array_forget($json, $replacedValue);
                    }
                }
            }
        }

        // set json default value
        if (array_has($templateItems, 'defaults')) {
            $defaults = $templateItems['defaults'];
            foreach ($defaults as $key => $default) {
                data_fill($json, $key, $default);
            }
        }

        // get keylist and value
        $keys = array_get($templateItems, 'uniqueKeys', []);
        $obj_keys = [];
        // if this array is not vector, get for export
        if (!is_vector($keys)) {
            $keys = array_get($keys, 'import', []);
        }
        foreach ($keys as $key) {
            $obj_keys[$key] = array_get($json, $key);
        }

        // if contains 'parent' in $options
        if (array_has($options, 'parent') && array_has($templateItems, 'parent')) {
            // get parent model's id
            $parent_id = $options['parent']->id;
            // replace parent id
            $obj_keys[array_get($templateItems, 'parent')] = $parent_id;
        }

        // create model
        $obj = static::firstOrNew($obj_keys);

        // if record is exists already skip process, when update
        if ($is_update && $obj->exists) {
            return $obj;
        }

        // replace especially datalists
        $excepts = method_exists($obj, 'importSetValue') ? $obj->importSetValue($json, $options) : [];

        // loop json value
        foreach ($json as $key => $value) {
            // if contains excepts(by function), skip
            if ($excepts && in_array($key, $excepts)) {
                continue;
            }

            // if default excepts, skip
            if (in_array($key, static::$defaultExcepts)) {
                continue;
            }

            // if contains excepts, skip
            if (array_key_exists('excepts', $templateItems)) {
                $expects = is_vector($templateItems['excepts']) ? array_get($templateItems, 'excepts', []) : array_get($templateItems, 'excepts.import', []);
                if (in_array($key, $expects)) {
                    continue;
                }
            }

            // if contains children, skip
            if (array_key_exists('children', $templateItems) && array_has($templateItems['children'], $key)) {
                continue;
            }

            // if has enums, set as enum value
            if (array_has($templateItems, 'enums') && array_has($templateItems['enums'], $key)) {
                $enumclass = $templateItems['enums'][$key];
                $obj->{$key} = $enumclass::getEnumValue($value);
            } else {
                $obj->{$key} = $value;
            }
        }

        $obj->saveOrFail();

        // executing children's import, but not call if contains 'ignoreImportChildren'
        if (array_key_exists('children', $templateItems)) {
            $children = $templateItems['children'];
            foreach ($templateItems['children'] as $key => $classname) {
                if (array_key_exists('ignoreImportChildren', $templateItems) && in_array($key, $templateItems['ignoreImportChildren'])) {
                    continue;
                }

                // Create children
                foreach (array_get($json, $key, []) as $count => $child) {
                    $classname::importTemplate($child, $is_update, [
                        'parent' => $obj,
                        'count' => ($count + 1)
                    ]);
                }
            }
        }

        return $obj;
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
        if (!property_exists(get_called_class(), 'templateItems') || !array_has(static::$templateItems, 'uniqueKeys')) {
            return [];
        }

        $keys = array_get(static::$templateItems, 'uniqueKeys', []);
        // if this array is not vector, get for export
        if (!is_vector($keys)) {
            $keys = array_get($keys, 'export', []);
        }

        // get key values
        $keyValues = [];
        foreach ($keys as $key) {
            //$array_key is last of $key's dotted
            $array_keys = explode('.', $key);
            $array_key = $array_keys[count($array_keys) - 1];
            // set to keyValues
            $keyValues[$array_key] = array_get($this, $key);
        }

        return $keyValues;
    }
}
