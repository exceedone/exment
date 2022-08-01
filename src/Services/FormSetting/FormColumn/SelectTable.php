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
    public function isSelectTable(): bool
    {
        return true;
    }


    /**
     * Get prepare options keys
     *
     * @return array
     */
    protected function prepareSavingOptionsKeys()
    {
        return array_merge(parent::prepareSavingOptionsKeys(), [
            'changedata_target_column_id',
            'changedata_column_id',
            'relation_filter_target_column_id',
        ]);
    }

    /**
     * Get setting modal form
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): WidgetForm
    {
        $form = parent::getSettingModalForm($block_item, $parameters);

        $relationColumns = $this->getRelationFileterColumns();
        if ($relationColumns->count() > 0) {
            $form->exmheader(exmtrans('custom_form.relation_filter'))->hr();
            $manualUrl = getManualUrl('form?id='.exmtrans('custom_form.relation_filter_manual'));
            $form->description(exmtrans('custom_form.help.relation_filter') . '<br/>' . exmtrans('common.help.more_help_here', $manualUrl))->escape(false);

            $form->select('relation_filter_target_column_id', exmtrans('custom_form.relation_filter'))
                ->options($relationColumns->mapWithKeys(function ($column) {
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
    public function getRelationFileterColumns(): Collection
    {
        // get relation columns.
        $relationColumns = Linkage::getLinkages(null, $this->custom_column);

        return $relationColumns;
    }
}
