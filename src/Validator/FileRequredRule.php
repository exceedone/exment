<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Contracts\Validation\ImplicitRule;

/**
 * FileRequredRule.
 * Required file. If has $custom_value, then alway return true.
 */
class FileRequredRule implements ImplicitRule
{
    protected $custom_column;

    protected $custom_value;

    public function __construct(CustomColumn $custom_column, ?CustomValue $custom_value)
    {
        $this->custom_column = $custom_column;
        $this->custom_value = $custom_value;
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if (!is_null($value)) {
            return true;
        }

        // if has custom_value, checking value
        if (isset($this->custom_value) && $this->custom_value->exists) {
            $v = array_get($this->custom_value->value, $this->custom_column->column_name);
            return !is_nullorempty($v);
        }
        
        // For HasMany nested forms - extract child record ID from attribute name
        // Attribute format examples:
        // - pivot__{hash}.{id}.value.{column_name}
        // - {relation_name}.{id}.value.{column_name}
        // Only numeric IDs that exist in DB are valid edit cases
        if (preg_match('/\.(\d+)\.value\.([^.]+)$/', $attribute, $matches)) {
            $childId = (int)$matches[1];
            $columnName = $matches[2];
            
            // Verify column name matches to avoid false positives
            if ($columnName !== $this->custom_column->column_name) {
                return false;
            }
            
            $customTable = $this->custom_column->custom_table;
            if ($customTable && $childId > 0) {
                $childRecord = $customTable->getValueModel($childId);
                
                // Record must exist and belong to correct table
                if ($childRecord && $childRecord->exists) {
                    $existingValue = array_get($childRecord->value, $this->custom_column->column_name);
                    return !is_nullorempty($existingValue);
                }
            }
        }

        return false;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.required');
    }
}
