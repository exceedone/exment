<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Form;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\PluginButtonType;
use Exceedone\Exment\Form\Tools;

class CalendarGrid extends GridBase
{
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    public function grid()
    {
        $table_name = $this->custom_table->table_name;
        $model = $this->custom_table->getValueQuery();
        $this->custom_view->filterSortModel($model);

        $tools = [];
        $this->setNewButton($tools);
        $this->setTableMenuButton($tools);
        $this->setViewMenuButton($tools);

        $listButtons = Plugin::pluginPreparingButton(PluginButtonType::CALENDAR_MENUBUTTON, $this->custom_table);
        foreach ($listButtons as $listButton) {
            $button = new Tools\PluginMenuButton($listButton, $this->custom_table);
            $tools[] = $button->render();
        }

        return view('exment::widgets.calendar', [
            'view_id' => $this->custom_view->suuid,
            'data_url' => admin_url('webapi/data', [$this->custom_table->table_name, 'calendar']),
            // 'createUrl' => admin_url("data/$table_name/create"),
            // 'new' => trans('admin.new'),
            'tools' => $tools,
            'locale' => \App::getLocale(),
        ]);
    }


    /**
     * Set custom view columns form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        static::setViewInfoboxFields($form);

        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // columns setting
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_start_date"))
                ->required()
                ->options($custom_table->getDateColumnsSelectOptions());
            $form->select('view_column_end_date', exmtrans("custom_view.view_column_end_date"))
                ->options($custom_table->getDateColumnsSelectOptions());
            $form->color('view_column_color', exmtrans("custom_view.color"))
                ->required()
                ->default(config('exment.calendor_color_default', '#00008B'));
            $form->color('view_column_font_color', exmtrans("custom_view.font_color"))
                ->required()
                ->default(config('exment.calendor_font_color_default', '#FFFFFF'));
        })->required()->setTableColumnWidth(4, 3, 2, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_calendar_columns"), $manualUrl));

        // filter setting
        static::setFilterFields($form, $custom_table);
    }

    /**
     * Set filter fileds form
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param boolean $is_aggregate
     * @return void
     */
    public static function setFilterFields(&$form, $custom_table, $is_aggregate = false)
    {
        parent::setFilterFields($form, $custom_table, $is_aggregate);

        $form->checkboxone('condition_reverse', exmtrans("condition.condition_reverse"))
            ->option(exmtrans("condition.condition_reverse_options"));
    }
}
