<?php
namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;
use Exceedone\Exment\Providers\CustomUserProvider;
use Illuminate\Validation\Validator as AdminValidator;

/**
 * CAUTION:::
 * Don't please add new function. Please add new Rule.
 */
class ExmentCustomValidator extends AdminValidator
{
    use ColumnOptionQueryTrait;

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
    * Validation in table
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateSummaryCondition($attribute, $value, $parameters)
    {
        $field_name = str_replace('.view_summary_condition', '.view_column_target', $attribute);
        $view_column_target = array_get($this->data, $field_name);
        $view_column_target = static::getOptionParams($view_column_target, null);
        $column_target = array_get($view_column_target, 'column_target');
        if (is_numeric($column_target)) {
            // get column_type
            $column_type = CustomColumn::getEloquent($column_target)->column_type;
            // numeric column can select all summary condition.
            if (ColumnType::isCalc($column_type)) {
                return true;
            }
        }
        // system column and not numeric column only select no calculate condition.
        $option = SummaryCondition::getOption(['id' => $value]);
        if (array_get($option, 'numeric')) {
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
        $relation_id = count($parameters) >= 2 ? $parameters[1] : null;

        // check lower relation;
        if (!$this->HasRelation('parent_custom_table_id', 'child_custom_table_id', $custom_table_id, $value, $relation_id)) {
            return false;
        }

        // check upper relation;
        if (!$this->HasRelation('child_custom_table_id', 'parent_custom_table_id', $custom_table_id, $value, $relation_id)) {
            return false;
        }

        return true;
    }

    /**
     * check if exists custom relation.
     */
    protected function HasRelation($attr1, $attr2, $custom_table_id, $value, $relation_id = null)
    {
        // get count reverse relation in table;
        $query = CustomRelation::where($attr1, $custom_table_id);
        if (isset($relation_id)) {
            $query = $query->where('id', '<>', $relation_id);
        }
        $rows = $query->get();

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
    * Validation index column max count
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
        // get custom_column id
        $custom_column_id = $parameters[1];

        // get count index columns
        $count = CustomColumn::where('custom_table_id', $custom_table_id)
        ->where('id', '<>', $custom_column_id)
        ->whereIn('options->index_enabled', [1, "1"])
        ->count();

        if ($count >= 20) {
            return false;
        }

        return true;
    }

    /**
    * Validation if search-index column is refered by custom view
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateUsingIndexColumn($attribute, $value, $parameters)
    {
        // if value is on, no validate
        if ($value) {
            return true;
        }
        // if parameters are invalid, no validate
        if (count($parameters) < 1) {
            return true;
        }

        // get custom_column_id
        $custom_column_id = $parameters[0];

        // when new record, $custom_column_id is null
        if (!$custom_column_id) {
            return true;
        }

        // get group key column count of summary view
        $count = CustomView::where('view_kind_type', 1)
            ->whereHas('custom_view_columns', function ($query) use ($custom_column_id) {
                $query->where('view_column_type', 0)
                    ->where("view_column_target_id", $custom_column_id);
            })->count();

        if ($count > 0) {
            return false;
        }

        // get count index columns refered in view filters
        $count = CustomViewFilter::where('view_column_target_id', $custom_column_id)
            ->where('view_column_type', 0)
            ->count();

        if ($count > 0) {
            return false;
        }

        // get count index columns refered in view sorts
        $count = CustomViewSort::where('view_column_target_id', $custom_column_id)
            ->where('view_column_type', 0)
            ->count();

        if ($count > 0) {
            return false;
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
    public function validateChangeFieldValue($attribute, $value, &$parameters)
    {
        $field_label = null;

        if (isset($parameters) && count($parameters) > 0) {
            $field_label = $parameters[0];
        }

        $prefix = substr($attribute, 0, strrpos($attribute, '.'));

        $field = getCustomField(array_get($this->data, $prefix), $field_label);

        if (!$validator = $field->getValidator([$field->column() => $value])) {
            return true;
        }

        if (($validator instanceof AdminValidator) && !$validator->passes()) {
            $parameters[] = $validator->messages->first();
            return false;
        }

        return true;
    }
    protected function replaceChangeFieldValue($message, $attribute, $rule, $parameters)
    {
        if (count($parameters) > 0) {
            return $parameters[count($parameters) - 1];
        }
        return $message;
    }

    /**
    * Validation regular expression
    *
    * @param $attribute
    * @param $value
    * @param $parameters
    * @return bool
    */
    public function validateRegularExpression($attribute, $value, $parameters)
    {
        set_error_handler(
            function ($severity, $message) {
                throw new \RuntimeException($message);
            }
        );
        try {
            preg_match("/$value/", '');
        } catch (\RuntimeException $e) {
            return false;
        } finally {
            restore_error_handler();
        }
    
        return true;
    }
}
