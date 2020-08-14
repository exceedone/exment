<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\PluginEventTrigger;

class SummaryGrid extends GridBase
{
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    public function grid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADING, $this->custom_table);

        $this->setSummaryGrid($grid);

        $grid->disableCreateButton();
        $grid->disableFilter();
        //$grid->disableActions();
        $grid->disableRowSelector();
        $grid->disableExport();

        $table_name = $this->custom_table->table_name;
        $isShowViewSummaryDetail = $this->isShowViewSummaryDetail();
        if (!$isShowViewSummaryDetail) {
            $grid->disableActions();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) use ($isShowViewSummaryDetail, $table_name) {
            $actions->disableDelete();
            $actions->disableEdit();
            $actions->disableView();

            $params = [];
            foreach ($actions->row->toArray() as $key => $value) {
                $keys = explode('_', $key);
                if (count($keys) == 3 && $keys[1] == ViewKindType::DEFAULT) {
                    $params[$keys[2]] = $value;
                }
            }

            if ($isShowViewSummaryDetail) {
                $linker = (new Grid\Linker)
                ->url(admin_urls_query('data', $table_name, ['view' => CustomView::getAllData($table_name)->suuid,'group_key' => json_encode($params)]))
                ->icon('fa-list')
                ->tooltip(exmtrans('custom_value.view_summary_detail'));
                $actions->prepend($linker);
            }
        });

        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\SummaryAction(
                [
                    'grid' => $grid,
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                    'is_summary' => true,
                ]
            ));
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            // have edit flg
            $edit_flg = $this->custom_table->enableEdit(true) === true;
            if ($edit_flg && $this->custom_table->enableExport() === true) {
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, false, true, false);
                $tools->append($button->setCustomTable($this->custom_table));
            }
            
            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }
            
            if ($this->custom_table->enableTableMenuButton()) {
                $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            }
            if ($this->custom_table->enableViewMenuButton()) {
                $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
            }
        });

        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADED, $this->custom_table);
        return $grid;
    }

    /**
     * set summary grid
     */
    protected function setSummaryGrid($grid)
    {
        $view = $this->custom_view;

        $query = $grid->model();
        return $view->getValueSummary($query, $this->custom_table, $grid);
    }

    protected function isShowViewSummaryDetail()
    {
        return !$this->custom_view->custom_view_columns->contains(function ($custom_view_column) {
            return $this->custom_table->id != $custom_view_column->view_column_table_id;
        }) && !$this->custom_view->custom_view_summaries->contains(function ($custom_view_summary) {
            return $this->custom_table->id != $custom_view_summary->view_column_table_id;
        }) && !$this->custom_view->custom_view_filters->contains(function ($custom_view_filter) {
            return $this->custom_table->id != $custom_view_filter->view_column_table_id;
        });
    }


    /**
     * Set custom view columns( group columns )form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        
        // group columns setting
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_groups"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'append_table' => true,
                    'index_enabled_only' => true,
                    'include_parent' => true,
                    'include_child' => true,
                    'include_workflow' => true,
                ]))
                ->attribute([
                    'data-linkage' => json_encode(['view_group_condition' => admin_urls('view', $custom_table->table_name, 'group-condition')]),
                    'data-change_field_target' => 'view_column_target',
                ]);
            
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);

            $form->select('view_group_condition', exmtrans("custom_view.view_group_condition"))
                ->options(function ($val, $form) {
                    if (is_null($data = $form->data())) {
                        return [];
                    }
                    if (is_null($view_column_target = array_get($data, 'view_column_target'))) {
                        return [];
                    }
                    return collect(SummaryGrid::getGroupCondition($view_column_target))->pluck('text', 'id')->toArray();
                });

            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->options(array_merge([''], range(1, 5)))
                ->help(exmtrans('custom_view.help.sort_order_summaries'));
            $form->select('sort_type', exmtrans("custom_view.sort"))
            ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->config('allowClear', false)->default(Enums\ViewColumnSort::ASC);
                
            $form->hidden('order')->default(0);
        })->required()->rowUpDown('order')->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_groups"), $manualUrl));

        // summary columns setting
        $form->hasManyTable('custom_view_summaries', exmtrans("custom_view.custom_view_summaries"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($custom_table->getSummaryColumnsSelectOptions())
                ->attribute(['data-linkage' => json_encode(['view_summary_condition' => admin_urls('view', $custom_table->table_name, 'summary-condition')])]);
            $form->select('view_summary_condition', exmtrans("custom_view.view_summary_condition"))
                ->options(function ($val, $form) {
                    $view_column_target = array_get($form->data(), 'view_column_target');
                    if (isset($view_column_target)) {
                        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
                        if (isset($columnItem)) {
                            // only numeric
                            if ($columnItem->isNumeric()) {
                                $options = SummaryCondition::getOptions();
                            } else {
                                $options = SummaryCondition::getOptions(['numeric' => false]);
                            }

                            return array_map(function ($array) {
                                return exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'));
                            }, $options);
                        }
                    }
                    return [];
                })
                ->required()->rules('summaryCondition');
            $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);
            $form->select('sort_order', exmtrans("custom_view.sort_order"))
                ->help(exmtrans('custom_view.help.sort_order_summaries'))
                ->options(array_merge([''], range(1, 5)));
            $form->select('sort_type', exmtrans("custom_view.sort"))
                ->help(exmtrans('custom_view.help.sort_type'))
                ->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->config('allowClear', false)->default(Enums\ViewColumnSort::ASC);
        })->setTableColumnWidth(4, 2, 2, 1, 2, 1)
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_summaries"), $manualUrl));

        // filter setting
        static::setFilterFields($form, $custom_table, true);
    }

    
    /**
     * get group condition
     */
    public static function getGroupCondition($view_column_target = null)
    {
        if (!isset($view_column_target)) {
            return [];
        }

        // get column item from $view_column_target
        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        if (!$columnItem->isDate()) {
            return [];
        }

        // if date, return option
        $options = GroupCondition::getOptions();
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.group_condition_options.'.array_get($array, 'name'))];
        });
    }
}
