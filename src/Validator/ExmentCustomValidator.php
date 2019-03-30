<?php
namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;

class ExmentCustomValidator extends \Illuminate\Validation\Validator
{
    /**
    * Validation in table
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateUniqueInTable($attribute, $value, $parameters)
    {
        if (count($parameters) < 2) {
            return true;
        }

        // get classname for search
        $classname = $parameters[0];
        // get custom_table_id
        $custom_table_id = $parameters[1];

        // get count same value in table;
        $count = $classname::where('custom_table_id', $custom_table_id)
        ->where($attribute, $value)
        ->count();

        if ($count > 0) {
            return false;
        }

        return true;
    }

    /**
    * Validate relations between tables are circular reference
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateLoopRelation($attribute, $value, $parameters)
    {
        if (count($parameters) < 1) {
            return true;
        }

        // get custom_table_id
        $custom_table_id = $parameters[0];

        // check lower relation;
        if (!$this->HasRelation('parent_custom_table_id', 'child_custom_table_id', $custom_table_id, $value)) {
            return false;
        }

        // check upper relation;
        if (!$this->HasRelation('child_custom_table_id', 'parent_custom_table_id', $custom_table_id, $value)) {
            return false;
        }

        return true;
    }

    /**
     * check if exists custom relation.
     */
    protected function HasRelation($attr1, $attr2, $custom_table_id, $value)
    {
        // get count reverse relation in table;
        $rows = CustomRelation::where($attr1, $custom_table_id)
            ->get();

        foreach ($rows as $row) {
            $id = array_get($row, $attr2);
            if ($id == $value) {
                return false;
            } else {
                if (!$this->HasRelation($attr1, $attr2, $id, $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
    * Validation in table
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateMaxTableIndex($attribute, $value, $parameters)
    {
        // if value is off, no validate
        if (!$value) {
            return true;
        }
        // if parameters are invalid, no validate
        if (count($parameters) < 2) {
            return true;
        }

        // get custom_table_id
        $custom_table_id = $parameters[0];
        // get max index count
        $max_index_count = is_numeric($parameters[1])? intval($parameters[1]) : 10;

        // get count index columns
        $count = CustomColumn::where('custom_table_id', $custom_table_id)
        ->whereIn('options->index_enabled', [1, "1"])
        ->count();

        if ($count >= $max_index_count) {
            return false;
        }

        return true;
    }
    protected function replaceMaxTableIndex($message, $attribute, $rule, $parameters)
    {
        return str_replace(':maxlen', $parameters[1], $message);
    }
}
