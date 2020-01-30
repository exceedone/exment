<?php
namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Model\Define;
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

        // define date array
        $dateStrings = [
            'ymdhms' => 'YmdHis',
            'ymdhm' => 'YmdHi',
            'ymdh' => 'YmdH',
            'ymd' => 'Ymd',
            'ym' => 'Ym',
            'hms' => 'His',
            'hm' => 'Hi',

            'ymdhis' => 'YmdHis',
            'ymdhi' => 'YmdHi',
            'his' => 'His',
            'hi' => 'Hi',
        ];
        $dateValues = [
            'year' => 'year',
            'month' => 'month',
            'day' => 'day',
            'hour' => 'hour',
            'minute' => 'minute',
            'second' => 'second',
            'y' => 'year',
            'm' => 'month',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        if (in_array($key, $systemValues)) {
            $classname = SystemValue::class;
        } elseif ($key == "value_url") {
            $classname = ValueUrl::class;
        } elseif (in_array($key, ["value", SystemTableName::BASEINFO])) {
            $classname = Value::class;
        } elseif ($key == "select_table") {
            $classname = SelectTableValue::class;
        } elseif ($key == "sum") {
            $classname = Sum::class;
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
        }

        // if has $datestrings, conbert using date string
        elseif (array_key_exists(strtolower($key), $dateStrings)) {
            $classname = DateString::class;
        }

        // if has $dateValues, conbert using date value
        elseif (array_key_exists(strtolower($key), $dateValues)) {
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
