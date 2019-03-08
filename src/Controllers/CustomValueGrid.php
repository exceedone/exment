<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Enums\RoleValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as Req;

trait CustomValueGrid
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $classname = $this->getModelNameDV();
        $grid = new Grid(new $classname);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');
        
        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();
    
        // create grid
        $this->custom_view->setGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // filter
        Admin::user()->filterModel($grid->model(), $this->custom_table->table_name, $this->custom_view);
        $this->setCustomGridFilters($grid, $search_enabled_columns);

        // manage tool button
        $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'grid_menubutton');
        $this->manageMenuToolButton($grid, $listButton);

        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }

    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $search_enabled_columns)
    {
        $grid->filter(function ($filter) use ($search_enabled_columns) {
            $filter->column(1/2, function ($filter) {
                $filter->between('created_at', exmtrans('common.created_at'))->date();
                $filter->between('updated_at', exmtrans('common.updated_at'))->date();
                
                // check 1:n relation
                $relation = CustomRelation::getRelationByChild($this->custom_table);
                // if set, create select
                if (isset($relation)) {
                    // get options and ajax url
                    $options = $relation->parent_custom_table->getOptions();
                    $ajax = $relation->parent_custom_table->getOptionAjaxUrl();
                    if (isset($ajax)) {
                        $filter->equal('parent_id', $relation->parent_custom_table->table_view_name)->select([])->ajax($ajax, 'id', 'label');
                    } else {
                        $filter->equal('parent_id', $relation->parent_custom_table->table_view_name)->select($options);
                    }
                }
            });

            // loop custom column
            $filter->column(1/2, function ($filter) use ($search_enabled_columns) {
                foreach ($search_enabled_columns as $search_column) {
                    $search_column->column_item->setAdminFilter($filter);
                }
            });
        });
    }

    /**
     * Manage Grid Tool Button
     * And Manage Batch Action
     */
    protected function manageMenuToolButton($grid, $listButton)
    {
        $custom_table = $this->custom_table;
        $grid->disableCreateButton();
        $grid->disableExport();
        
        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($listButton, $grid, $service) {
            // have edit flg
            $edit_flg = $this->custom_table->hasPermission(RoleValue::AVAILABLE_EDIT_CUSTOM_VALUE);
            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid));
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
                $tools->append($service->getImportModal());
            }
            
            // add page change button(contains view seting)
            $tools->append(new Tools\GridChangePageMenu('data', $this->custom_table, false));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));
            
            // add plugin button
            if ($listButton !== null && count($listButton) > 0) {
                foreach ($listButton as $plugin) {
                    $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table));
                }
            }
            
            // manage batch --------------------------------------------------
            // if cannot edit, disable delete
            if (!$edit_flg) {
                $tools->batch(function ($batch) {
                    $batch->disableDelete();
                });
            }
        });
    }

    /**
     * Management row action
     */
    protected function manageRowAction($grid)
    {
        if (isset($this->custom_table)) {
            // name
            $custom_table = $this->custom_table;
            $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table) {
                $form_id = Req::get('form');
                // if has $form_id, remove default edit link, and add new link added form query
                if (isset($form_id)) {
                    $actions->disableEdit();
                    $actions->prepend('<a href="'.admin_urls('data', $custom_table->table_name, $actions->getKey(), 'edit').'?form='.$form_id.'"><i class="fa fa-edit"></i></a>');
                }

                // if user does't edit permission disable edit row.
                if (!$custom_table->hasPermissionEditData($actions->getKey())) {
                    $actions->disableEdit();
                    $actions->disableDelete();
                }
            });
        }
    }
    
    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        $service = $this->getImportExportService()
            ->format($request->file('custom_table_file'));
        $result = $service->import($request);

        return getAjaxResponse($result);
    }

    // create import and exporter
    protected function getImportExportService($grid = null)
    {
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\CustomTableAction(
                [
                    'custom_table' => $this->custom_table,
                    'grid' => $grid,
                ]
            ))->importAction(new DataImportExport\Actions\Import\CustomTableAction(
                [
                    'custom_table' => $this->custom_table,
                    'primary_key' => app('request')->input('select_primary_key') ?? null,
                ]
            ));
        return $service;
    }
}
