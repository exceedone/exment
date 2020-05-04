<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Grid;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\PluginEventTrigger;

trait CustomValueSummary
{
    protected function gridSummary()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        Plugin::pluginPreparing($this->plugins, PluginEventTrigger::LOADING);

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
                ->url(admin_urls('data', $table_name).'?group_key='.json_encode($params))
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
            
            $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
        });

        Plugin::pluginPreparing($this->plugins, PluginEventTrigger::LOADED);
        return $grid;
    }

    protected function getSummaryDetailFilter($group_keys)
    {
        // save summary view
        $custom_view = $this->custom_view;
        // replace view
        $this->custom_view = CustomView::getAllData($this->custom_table);
        $filters = [];
        foreach ($group_keys as $key => $value) {
            $custom_view_column = CustomViewColumn::find($key);
            $custom_view_filter = new CustomViewFilter;
            $custom_view_filter->custom_view_id = $custom_view_column->custom_view_id;
            $custom_view_filter->view_column_type = $custom_view_column->view_column_type;
            $custom_view_filter->view_column_target = $custom_view_column->view_column_target;
            $custom_view_filter->view_group_condition = $custom_view_column->view_group_condition;
            $custom_view_filter->view_filter_condition = FilterOption::EQ;
            $custom_view_filter->view_filter_condition_value_text = $value;
            $filters[] = $custom_view_filter;
        }
        $filter_func = function ($model) use ($filters, $custom_view) {
            foreach ($filters as $filter) {
                $model = $filter->setValueFilter($model);
            }
            $custom_view->setValueFilters($model);
            return $model;
        };
        return $filter_func;
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
        });
    }
}
