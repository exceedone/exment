<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
abstract class ColumnBase
{
    /**
     * @var CustomFormColumn
     */
    protected $custom_form_column;


    /**
     * Whether is selected column. 
     * 
     * If suggest and already selected as column, set true.
     * Otherwise, set false.
     *
     * @var bool
     */
    protected $isSelected = false;


    public function __construct(CustomFormColumn $custom_form_column)
    {
        $this->custom_form_column = $custom_form_column;
    }

    public static function make(CustomFormColumn $custom_form_column) : ColumnBase
    {
        switch (array_get($custom_form_column, 'form_column_type', FormColumnType::COLUMN)) {
            case FormColumnType::COLUMN:
                return Column::make($custom_form_column);
                
            case FormColumnType::OTHER:
                return OtherBase::make($custom_form_column);
        }

        return null;
    }


    /**
     * Get object using form_column_type
     *
     * @return self
     */
    public static function makeByParams($form_column_type, $form_column_target_id) : ColumnBase
    {
        $form_column = new CustomFormColumn;
        $form_column->form_column_type = $form_column_type;
        $form_column->form_column_target_id = $form_column_target_id;

        return static::make($form_column);
    }


    /**
     * Get items for display
     *
     * @return array
     */
    public function getItemsForDisplay() : array
    {
        return [
            'form_column_type' => $this->custom_form_column->form_column_type ?? FormColumnType::COLUMN,
            'row_no' => $this->custom_form_column->row_no ?? 1,
            'column_no' => $this->custom_form_column->column_no ?? 1,
            'form_column_target_id' => $this->custom_form_column->form_column_target_id ?? null,
            
            'options' =>  collect($this->custom_form_column->options ?? [])->toJson(),
            
            'is_select_table' => $this->isSelectTable(),
            'required' => $this->isRequired(),
            'column_view_name' => $this->getColumnViewName(),
            'header_column_name' => $this->getHtmlHeaderName(),
            'toggle_key_name' => make_uuid(),
            'has_custom_forms' => $this->isSelected,
        ];
    }


    public function isSelected(bool $isSelected){
        $this->isSelected = $isSelected;
        return $this;
    }


    /**
     * Get html header name
     *
     * @return void
     */
    protected function getHtmlHeaderName()
    {
        // add header name
        return '[custom_form_columns]['
            .(isset($this->custom_form_column['id']) ? $this->custom_form_column['id'] : 'NEW__'.make_uuid())
            .']';
    }
    
    public function isSelectTable() : bool
    {
        return false;
    }

    /**
     * Get column's view name
     *
     * @return string|null
     */
    abstract public function getColumnViewName() : ?string;

    
    /**
     * Whether this column is required
     *
     * @return boolean
     */
    abstract public function isRequired() : bool;

    /**
     * Get setting modal form 
     *
     * @return WidgetForm
     */
    abstract public function getSettingModalForm(BlockBase $block_item, array $parameters) : WidgetForm;
}
