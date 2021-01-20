<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Encore\Admin\Form;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;

/**
 */
class Column extends ColumnBase
{
    /**
     * @var CustomColumn
     */
    protected $custom_column;


    public function __construct(CustomFormColumn $custom_form_column)
    {
        parent::__construct($custom_form_column);
        $this->custom_column = $custom_form_column->custom_column_cache;
        
        // get from form_column_target_id
        if (!isset($this->custom_column)) {
            $this->custom_column = CustomColumn::getEloquent(array_get($custom_form_column, 'form_column_target_id'));
        }
    }

    public static function make(CustomFormColumn $custom_form_column) : ColumnBase
    {
        $custom_column = $custom_form_column->custom_column_cache;
        if (ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
            return new SelectTable($custom_form_column);
        }
        return new Column($custom_form_column);
    }
    
    /**
     * Get column's view name
     *
     * @return string|null
     */
    public function getColumnViewName() : ?string
    {
        if (!isset($this->custom_column)) {
            return null;
        }
        return $this->custom_column->column_view_name;
    }

    
    /**
     * Whether this column is required
     *
     * @return boolean
     */
    public function isRequired() : bool
    {
        return boolval(array_get($this->custom_form_column, 'required')) || boolval(array_get($this->custom_column, 'required'));
    }
}
