<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Grid\Tools\BatchUpdate;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Services\PartialCrudService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as Req;

trait CustomValueGrid
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid($filter_func = null)
    {
        $classname = $this->getModelNameDV();
        $grid = new Grid(new $classname);
        Plugin::pluginPreparing($this->plugins, 'loading');
        
        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();

        // filter
        Admin::user()->filterModel($grid->model(), $this->custom_view, $filter_func);
        $this->setCustomGridFilters($grid, $search_enabled_columns);
    
        // create grid
        $this->custom_view->setGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // manage tool button
        $this->manageMenuToolButton($grid);

        Plugin::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }

    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $search_enabled_columns)
    {
        $grid->quickSearch(function ($model, $input) {
            $model->eloquent()->setSearchQueryOrWhere($model, $input);
        }, 'left');

        $grid->filter(function ($filter) use ($search_enabled_columns) {
            $filter->disableIdFilter();

            $filter->column(count($search_enabled_columns) == 0 ? 1 : 1/2, function ($filter) {
                $filter->equal('id', exmtrans('common.id'));
                $filter->betweendatetime('created_at', exmtrans('common.created_at'))->date();
                $filter->betweendatetime('updated_at', exmtrans('common.updated_at'))->date();
                
                // check 1:n relation
                $relation = CustomRelation::getRelationByChild($this->custom_table);
                // if set, create select
                if (isset($relation)) {
                    // get options and ajax url
                    $options = $relation->parent_custom_table->getSelectOptions();
                    $ajax = $relation->parent_custom_table->getOptionAjaxUrl();
                    $table_view_name = $relation->parent_custom_table->table_view_name;

                    // switch 1:n or n:n
                    if ($relation->relation_type == RelationType::ONE_TO_MANY) {
                        if (isset($ajax)) {
                            $filter->equal('parent_id', $table_view_name)->select([])->ajax($ajax, 'id', 'text');
                        } else {
                            $filter->equal('parent_id', $table_view_name)->select($options);
                        }
                    } else {
                        $relationQuery = function ($query) use ($relation) {
                            $query->whereHas($relation->getRelationName(), function ($query) use ($relation) {
                                $query->where($relation->getRelationName() . '.parent_id', $this->input);
                            });
                        };

                        // set relation
                        if (isset($ajax)) {
                            $filter->where($relationQuery, $table_view_name)->select([])->ajax($ajax, 'id', 'text');
                        } else {
                            $filter->where($relationQuery, $table_view_name)->select($options);
                        }
                    }
                }

                // filter workflow
                if (!is_null($workflow = Workflow::getWorkflowByTable($this->custom_table))) {
                    $custom_table = $this->custom_table;
                    $field = $filter->where(function ($query) use ($custom_table) {
                        WorkflowItem::scopeWorkflowStatus($query, $custom_table, FilterOption::EQ, $this->input);
                    }, $workflow->workflow_view_name)->select($workflow->getStatusOptions());
                    if (boolval(request()->get($field->getFilter()->getId()))) {
                        System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK, true);
                    }
                    
                    $field = $filter->where(function ($query) use ($custom_table) {
                    }, exmtrans('workflow.login_work_user'))->checkbox([1 => 'YES']);

                    if (boolval(request()->get($field->getFilter()->getId()))) {
                        System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK, true);
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
    protected function manageMenuToolButton($grid)
    {
        $custom_table = $this->custom_table;
        $grid->disableCreateButton();
        $grid->disableExport();

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($grid, $service) {
            $listButtons = Plugin::pluginPreparingButton($this->plugins, 'grid_menubutton');
            
            if (($import = $this->custom_table->enableImport()) === true || $this->custom_table->enableExport() === true) {
                $tools->append(new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, $import !== true));
            }
            
            if ($this->custom_table->enableCreate(true) === true) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }

            // add page change button(contains view seting)
            $tools->append(new Tools\GridChangePageMenu('data', $this->custom_table, false));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));
            
            // add plugin button
            if ($listButtons !== null && count($listButtons) > 0) {
                foreach ($listButtons as $listButton) {
                    $tools->append(new Tools\PluginMenuButton($listButton, $this->custom_table));
                }
            }
            
            // manage batch --------------------------------------------------
            $tools->batch(function ($batch) {
                // if cannot edit, disable delete and update operations
                if ($this->custom_table->enableEdit()) {
                    foreach ($this->custom_table->custom_operations as $custom_operation) {
                        $batch->add($custom_operation->operation_name, new BatchUpdate($custom_operation));
                    }
                } else {
                    $batch->disableDelete();
                }
            });
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
            $relationTables = $custom_table->getRelationTables();
            $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table, $relationTables) {
                $form_id = Req::get('form');
                // if has $form_id, remove default edit link, and add new link added form query
                if (isset($form_id)) {
                    $actions->disableEdit();
                    // add new edit link
                    $linker = (new Linker)
                        ->url(admin_urls('data', $custom_table->table_name, $actions->getKey(), 'edit').'?form='.$form_id)
                        ->icon('fa-edit')
                        ->tooltip(trans('admin.edit'));
                    $actions->prepend($linker);
                }

                // if has relations, add link
                if (count($relationTables) > 0) {
                    $linker = (new Linker)
                        ->url($this->row->getRelationSearchUrl())
                        ->icon('fa-compress')
                        ->tooltip(exmtrans('search.header_relation'));
                    $actions->prepend($linker);
                }
                
                // if user does't edit permission disable edit row.
                if ($actions->row->enableEdit(true) !== true) {
                    $actions->disableEdit();
                }
                
                if ($actions->row->enableDelete(true) !== true) {
                    $actions->disableDelete();
                }
                
                if(!is_null($parent_value = $actions->row->getParentValue()) && $parent_value->enableEdit(true) !== true){
                    $actions->disableEdit();
                    $actions->disableDelete();
                }

                PartialCrudService::setGridRowAction($custom_table, $actions);
            });
        }
    }
    
    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        $service = $this->getImportExportService()
            ->format($request->file('custom_table_file'))
            ->filebasename($this->custom_table->table_name);
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

    /**
     * update read_flg when row checked
     *
     * @param mixed   $id
     */
    public function rowUpdate(Request $request, $tableKey = null, $id = null, $rowid = null)
    {
        if (!isset($id) || !isset($rowid)) {
            abort(404);
        }

        $operation = CustomOperation::with(['custom_operation_columns'])->find($id);

        $models = $this->getModelNameDV()::whereIn('id', explode(',', $rowid));

        if (!isset($models) || $models->count() == 0) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('custom_value.message.operation_notfound'),
            ]);
        }

        $updates = collect($operation->custom_operation_columns)->mapWithKeys(function ($operation_column) {
            $column_name= 'value->'.$operation_column->custom_column->column_name;
            return [$column_name => $operation_column['update_value_text']];
        })->toArray();

        $models->update($updates);
        
        return getAjaxResponse([
            'result'  => true,
            'toastr' => exmtrans('custom_value.message.operation_succeeded'),
        ]);
    }
}
