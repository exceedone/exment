<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Enums\ViewKindType;
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
}
