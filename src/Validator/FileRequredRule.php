<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Contracts\Validation\ImplicitRule;

/**
 * FileRequredRule.
 * Required file. If has $custom_value, then alway return true.
 */
class FileRequredRule implements ImplicitRule
{
    /** @var mixed */
    protected $custom_column;

    /** @var mixed */
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
        if (preg_match('/^(.+)\.(\d+)\.value\.([^.]+)$/', $attribute, $matches)) {
            list(, $relationName, $childId, $columnName) = $matches;

            // Verify column name matches to avoid false positives
            if ($columnName !== $this->custom_column->column_name) {
                return false;
            }

            // The row key comes from the request. Saving uses the row's posted "id",
            // so both must point to the same child record.
            if (!isMatchString(request()->input("{$relationName}.{$childId}.id"), $childId)) {
                return false;
            }

            // The child must belong to the parent record being edited (none when creating).
            $parentId = request()->route('id');
            $parentTable = CustomTable::getEloquent(request()->route('tableKey'));
            if (is_nullorempty($parentId) || is_null($parentTable)) {
                return false;
            }

            $childRecord = $this->custom_column->custom_table->getValueModel((int)$childId);
            if (!$childRecord || !$childRecord->exists) {
                return false;
            }
            if (!isMatchString($childRecord->parent_id, $parentId) || !isMatchString($childRecord->parent_type, $parentTable->table_name)) {
                return false;
            }

            return !is_nullorempty(array_get($childRecord->value, $this->custom_column->column_name));
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
