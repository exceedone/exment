<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Enums\FormColumnType;

/**
 */
class OtherBase extends ColumnBase
{
    /**
     * Get column's view name
     *
     * @return string|null
     */
    public function getColumnViewName() : ?string
    {
        // get column name
        $column_form_column_name = FormColumnType::getOption(['id' => array_get($this->custom_form_column, 'form_column_target_id')])['column_name'] ?? null;
        return exmtrans("custom_form.form_column_type_other_options.$column_form_column_name");
    }

    /**
     * Whether this column is required
     *
     * @return boolean
     */
    public function isRequired() : bool
    {
        return false;
    }
}
