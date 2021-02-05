<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
class OtherBase extends ColumnBase
{
    public static function make(CustomFormColumn $custom_form_column) : ColumnBase
    {
        $column_form_column_name = FormColumnType::getOption(['id' => array_get($custom_form_column, 'form_column_target_id')])['column_name'] ?? null;
        switch($column_form_column_name){
            case 'header':
                return new Header($custom_form_column);
            case 'explain':
                return new Explain($custom_form_column);
            case 'html':
            case 'exhtml':
                return new Html($custom_form_column);
            case 'image':
                return new Image($custom_form_column);
        }
        
        return new OtherBase($custom_form_column);
    }


    /**
     * Get object for suggest
     *
     * @return self
     */
    public static function makeBySuggest($form_column_type_id) : ColumnBase
    {
        $form_column = new CustomFormColumn;
        $form_column->form_column_type = FormColumnType::OTHER;
        $form_column->form_column_target_id = $form_column_type_id;

        return static::make($form_column);
    }


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

    
    /**
     * Get setting modal form 
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters) : WidgetForm
    {
        $form = new WidgetForm($parameters);
        return $form;
    }
    

    /**
     * prepare saving option.
     *
     * @return array
     */
    public function prepareSavingOptions(array $options) : array
    {
        return $options;
    }
}
