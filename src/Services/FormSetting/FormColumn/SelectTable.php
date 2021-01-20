<?php
namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Encore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Support\Collection;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;

/**
 */
class SelectTable extends Column
{
    public function isSelectTable() : bool
    {
        return true;
    }


    /**
     * prepare saving option.
     *
     * @return array
     */
    public function prepareSavingOptions(array $options) : array
    {
        // convert field_showing_type
        if(!is_null($key = $this->convertFieldDisplayType($options))){
            $options[$key] = 1;
        }
        
        return array_filter($options, function($option, $key){
            return in_array($key, [
                'form_column_view_name',
                'view_only',
                'read_only',
                'required',
                'hidden',
                'changedata_target_column_id',
                'changedata_column_id',
                'relation_filter_target_column_id',
            ]);
        }, ARRAY_FILTER_USE_BOTH);
    }


    /**
     * Get setting modal form 
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters) : WidgetForm
    {
        $form = parent::getSettingModalForm($block_item, $parameters);
        
        $relationColumns = $this->getRelationFileterColumns();
        if($relationColumns->count() > 0){
            $form->exmheader(exmtrans('custom_form.relation_filter'))->hr();
            $form->description(exmtrans('custom_form.help.relation_filter') . '<br/>' . exmtrans('common.help.more_help_here', getManualUrl('form#relation_filter_manual')))->escape(false);

            $form->select('relation_filter_target_column_id', exmtrans('custom_form.relation_filter'))
                ->options($relationColumns->mapWithKeys(function($column){
                    return [$column->parent_column->id => $column->parent_column->column_view_name];
                })->toArray())
                ;
        }

        return $form;
    }

    /**
     * Get relation fileter columns
     *
     * @return Collection
     */
    public function getRelationFileterColumns() : Collection
    {        
        // get relation columns.
        $relationColumns = Linkage::getLinkages(null, $this->custom_column);

        return $relationColumns;
    }
}
