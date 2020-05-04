<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Grid\Tools as GridTools;
use Exceedone\Exment\Form\Tools;
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
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;
use Illuminate\Http\Request;

trait CustomValueGrid
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid($filter_func = null)
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        Plugin::pluginPreparing($this->plugins, PluginEventTrigger::LOADING);
        
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

        Plugin::pluginPreparing($this->plugins, PluginEventTrigger::LOADED);
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
            if ($this->custom_table->enableShowTrashed() === true) {
                $filter->scope('trashed', exmtrans('custom_value.soft_deleted_data'))->onlyTrashed();
            }

            $filter->disableIdFilter();


            $filterItems = [];

            foreach ([
                'id' => 'equal',
                'created_at' => 'date',
                'updated_at' => 'date',
            ] as $filterKey => $filterType) {
                if ($this->custom_table->gridFilterDisable($filterKey)) {
                    continue;
                }

                $filterItems[] = function ($filter) use ($filterKey, $filterType) {
                    if ($filterType == 'date') {
                        $filter->betweendatetime($filterKey, exmtrans("common.$filterKey"))->date();
                    } else {
                        $filter->equal($filterKey, exmtrans("common.$filterKey"));
                    }
                };
            }

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
                        $filterItems[] = function ($filter) use ($table_view_name, $ajax) {
                            $filter->equal('parent_id', $table_view_name)->select([])->ajax($ajax, 'id', 'text');
                        };
                    } else {
                        $filterItems[] = function ($filter) use ($table_view_name, $options) {
                            $filter->equal('parent_id', $table_view_name)->select($options);
                        };
                    }
                } else {
                    $relationQuery = function ($query) use ($relation) {
                        $query->whereHas($relation->getRelationName(), function ($query) use ($relation) {
                            $query->where($relation->getRelationName() . '.parent_id', $this->input);
                        });
                    };

                    // set relation
                    if (isset($ajax)) {
                        $filterItems[] = function ($filter) use ($relationQuery, $table_view_name, $ajax) {
                            $filter->where($relationQuery, $table_view_name)->select([])->ajax($ajax, 'id', 'text');
                        };
                    } else {
                        $filterItems[] = function ($filter) use ($relationQuery, $table_view_name, $options) {
                            $filter->where($relationQuery, $table_view_name)->select($options);
                        };
                    }
                }
            }

            // filter workflow
            if (!is_null($workflow = Workflow::getWorkflowByTable($this->custom_table))) {
                $custom_table = $this->custom_table;

                if (!$custom_table->gridFilterDisable('workflow_status')) {
                    $filterItems[] = function ($filter) use ($workflow, $custom_table) {
                        $field = $filter->where(function ($query) use ($custom_table) {
                            WorkflowItem::scopeWorkflowStatus($query, $custom_table, FilterOption::EQ, $this->input);
                        }, $workflow->workflow_view_name)->select($workflow->getStatusOptions());
                        if (boolval(request()->get($field->getFilter()->getId()))) {
                            System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK, true);
                        }
                    };
                }

                if (!$custom_table->gridFilterDisable('workflow_work_users')) {
                    $filterItems[] = function ($filter) use ($workflow, $custom_table) {
                        $field = $filter->where(function ($query) use ($custom_table) {
                        }, exmtrans('workflow.login_work_user'))->checkbox([1 => 'YES']);
    
                        if (boolval(request()->get($field->getFilter()->getId()))) {
                            System::setRequestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK, true);
                        }
                    };
                }
            }

            // loop custom column
            foreach ($search_enabled_columns as $search_column) {
                $filterItems[] = function ($filter) use ($search_column) {
                    $search_column->column_item->setAdminFilter($filter);
                };
            }

            // set filter item
            if (count($filterItems) <= 6) {
                foreach ($filterItems as $filterItem) {
                    $filterItem($filter);
                }
            } else {
                $separate = floor(count($filterItems) /  2);
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = 0; $i < $separate; $i++) {
                        $filterItems[$i]($filter);
                    }
                });
                $filter->column(1/2, function ($filter) use ($filterItems, $separate) {
                    for ($i = $separate; $i < count($filterItems); $i++) {
                        $filterItems[$i]($filter);
                    }
                });
            }
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
            $listButtons = Plugin::pluginPreparingButton($this->plugins, PluginEventTrigger::GRID_MENUBUTTON);
            
            // validate export and import
            $import = $this->custom_table->enableImport();
            $export = $this->custom_table->enableExport();
            if ($import === true || $export === true) {
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, true, $export === true, $import === true);
                $tools->append($button->setCustomTable($this->custom_table));
            }
            
            if ($this->custom_table->enableCreate(true) === true) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }

            // add page change button(contains view seting)
            $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
            
            // add plugin button
            if ($listButtons !== null && count($listButtons) > 0) {
                foreach ($listButtons as $listButton) {
                    $tools->append(new Tools\PluginMenuButton($listButton, $this->custom_table));
                }
            }
            
            // manage batch --------------------------------------------------
            $tools->batch(function ($batch) {
                // if cannot edit, disable delete and update operations
                if ($this->custom_table->enableEdit() === true) {
                    foreach ($this->custom_table->custom_operations as $custom_operation) {
                        $batch->add($custom_operation->operation_name, new GridTools\BatchUpdate($custom_operation));
                    }
                } else {
                    $batch->disableDelete();
                }
                
                if (request()->get('_scope_') == 'trashed' && $this->custom_table->enableEdit() === true && $this->custom_table->enableShowTrashed() === true) {
                    $batch->disableDelete();
                    $batch->add(exmtrans('custom_value.restore'), new GridTools\BatchRestore());
                    $batch->add(exmtrans('custom_value.hard_delete'), new GridTools\BatchHardDelete(exmtrans('custom_value.hard_delete')));
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
                $enableEdit = true;
                $enableDelete = true;
                $enableHardDelete = false;

                // if has relations, add link
                if (count($relationTables) > 0) {
                    $linker = (new Linker)
                        ->url($this->row->getRelationSearchUrl())
                        ->icon('fa-compress')
                        ->tooltip(exmtrans('search.header_relation'));
                    $actions->prepend($linker);
                }
                
                // append restore url
                if ($actions->row->trashed() && $custom_table->enableEdit() === true && $custom_table->enableShowTrashed() === true) {
                    $enableHardDelete = true;
                }

                // if user does't edit permission disable edit row.
                if ($actions->row->enableEdit(true) !== true) {
                    $enableEdit = false;
                }
                
                if ($actions->row->enableDelete(true) !== true) {
                    $enableDelete = false;
                }
                
                if (!is_null($parent_value = $actions->row->getParentValue()) && $parent_value->enableEdit(true) !== true) {
                    $enableEdit = false;
                    $enableDelete = false;
                }

                if (!$enableEdit) {
                    $actions->disableEdit();
                }

                if (!$enableDelete) {
                    $actions->disableDelete();
                }

                if ($enableHardDelete) {
                    $actions->disableView();
                    $actions->disableDelete();
                        
                    // add restore link
                    $restoreUrl = $actions->row->getUrl() . '/restoreClick';
                    $linker = (new Linker)
                        ->icon('fa-undo')
                        ->script(true)
                        ->linkattributes([
                            'data-add-swal' => $restoreUrl,
                            'data-add-swal-title' => exmtrans('custom_value.restore'),
                            'data-add-swal-text' => exmtrans('custom_value.message.restore'),
                            'data-add-swal-method' => 'get',
                            'data-add-swal-confirm' => trans('admin.confirm'),
                            'data-add-swal-cancel' => trans('admin.cancel'),
                        ])
                        ->tooltip(exmtrans('custom_value.restore'));
                    $actions->append($linker);
                    
                    // append show url
                    $showUrl = $actions->row->getUrl() . '?trashed=1';
                    // add new edit link
                    $linker = (new Linker)
                        ->url($showUrl)
                        ->icon('fa-eye')
                        ->tooltip(trans('admin.show'));
                    $actions->append($linker);

                    // add hard delete link
                    $deleteUrl = $actions->row->getUrl();
                    $linker = (new Linker)
                        ->icon('fa-trash')
                        ->script(true)
                        ->linkattributes([
                            'data-add-swal' => $deleteUrl,
                            'data-add-swal-title' => exmtrans('custom_value.hard_delete'),
                            'data-add-swal-text' => exmtrans('custom_value.message.hard_delete'),
                            'data-add-swal-method' => 'delete',
                            'data-add-swal-confirm' => trans('admin.confirm'),
                            'data-add-swal-cancel' => trans('admin.cancel'),
                        ])
                        ->tooltip(exmtrans('custom_value.hard_delete'));
                    $actions->append($linker);
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
            ))->viewExportAction(new DataImportExport\Actions\Export\SummaryAction(
                [
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
                    'grid' => $grid,
                ]
            ))->pluginExportAction(new DataImportExport\Actions\Export\PluginAction(
                [
                    'custom_table' => $this->custom_table,
                    'custom_view' => $this->custom_view,
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

        $models = $this->custom_table->getValueModel()->query()->whereIn('id', explode(',', $rowid));

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
