<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemColumn;

/**
 * replace value
 */
abstract class ItemBase
{
    protected $custom_value;
    protected $length_array;
    protected $matchOptions;
    protected $key;

    public function __construct($custom_value, $length_array, $matchOptions)
    {
        $this->custom_value = $custom_value;
        $this->length_array = $length_array;
        $this->matchOptions = $matchOptions;
        $this->key = $length_array[0];
    }

    public static function getItem($custom_value, $length_array, $matchOptions)
    {
        $key = $length_array[0];

        $systemValues = collect(SystemColumn::getOptions())->pluck('name')->toArray();

        if (in_array($key, $systemValues)) {
            $classname = SystemValue::class;
        } elseif ($key == "value_url") {
            $classname = ValueUrl::class;
        } elseif (in_array($key, ["table_name", 'table_view_name'])) {
            $classname = CustomTable::class;
        } elseif (in_array($key, ["value", SystemTableName::BASEINFO])) {
            $classname = Value::class;
        } elseif ($key == "select_table") {
            $classname = SelectTableValue::class;
        // } elseif ($key == "sum") {
        //     $classname = Sum::class;
        } elseif ($key == "child") {
            $classname = Child::class;
        } elseif ($key == "parent") {
            $classname = ParentValue::class;
        } elseif ($key == "workflow") {
            $classname = Workflow::class;
        } elseif ($key == "uuid") {
            $classname = UuidValue::class;
        } elseif ($key == "system") {
            $classname = System::class;
        } elseif ($key == "login_user") {
            $classname = LoginUser::class;
        } elseif ($key == "now") {
            $classname = Now::class;
        } elseif (in_array($key, ["file", 'documents'])) {
            $classname = File::class;
        }

        // if has $datestrings, conbert using date string
        elseif (array_key_exists(strtolower($key), DateString::dateStrings)) {
            $classname = DateString::class;
        }

        // if has $dateValues, conbert using date value
        elseif (array_key_exists(strtolower($key), DateValue::dateValues)) {
            $classname = DateValue::class;
        }

        if (isset($classname)) {
            return new $classname($custom_value, $length_array, $matchOptions);
        }
    }

    public function getLink($str)
    {
        return "<a href='$str'>$str</a>";
    }
}
