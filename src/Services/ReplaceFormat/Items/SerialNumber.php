<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class SerialNumber extends ItemBase
{
    /**
     * Replace date
     */
    public function replace($format, $options = [])
    {
        $custom_column = array_get($options, 'custom_column');
        $matchString = array_get($options, 'matchString');
        $serial = null;

        if (isset($custom_column)) {
            $custom_table = $this->custom_value->custom_table;
            if ($matchString) {
                $search_str = strstr($format, $matchString, true);
                if ($search_str) {
                    $last_value = $custom_table->getValueModel()
                        ->withTrashed()
                        ->where('value->' . $custom_column->column_name, 'LIKE', $search_str . '%')
                        ->orderBy('value->' . $custom_column->column_name, 'desc')
                        ->first();

                    $serial = 1;
                    if ($last_value) {
                        $last_serial = $last_value->getValue($custom_column->column_name);
                        $last_serial = str_replace($search_str, '', $last_serial);
                        if (is_numeric($last_serial)) {
                            $serial = intval($last_serial) + 1;
                        }
                    }
                } else {
                    $serial = $this->custom_value->id;
                }
            }
        }
        // if user input length
        if (is_numeric($serial) && count($this->length_array) > 1) {
            $length = $this->length_array[1];

            if ($length < strlen($serial)) {
                $serial = substr($serial, -$length);
            } else {
                $serial = sprintf('%0'.$length.'d', $serial);
            }
        }

        return $serial;
    }
}
