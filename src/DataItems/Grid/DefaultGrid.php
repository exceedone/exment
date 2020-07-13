<?php

namespace Exceedone\Exment\DataItems\Grid;

use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Grid\Tools as GridTools;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\SelectItemBox;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;
use Illuminate\Http\Request;

class DefaultGrid extends GridBase
{
    public function __construct($custom_table, $custom_view)
    {
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    public function grid()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        
        // if modal, Change view model
        if ($this->modal) {
            $this->gridFilterForModal($grid, $this->callback);
        } else {
            // filter
            $this->custom_view->filterModel($grid->model(), ['callback' => $this->callback]);
        }

        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();
        $this->setCustomGridFilters($grid, $search_enabled_columns);

        if (!$this->modal) {
            Plugin::pluginExecuteEvent(PluginEventTrigger::LOADING, $this->custom_table);
        }
        
        // create grid
        $this->custom_view->setGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // manage tool button
        $this->manageMenuToolButton($grid);

        if (!$this->modal) {
            Plugin::pluginExecuteEvent(PluginEventTrigger::LOADED, $this->custom_table);
        }

        $grid->getDataCallback(function ($grid) {
            $customValueCollection = $grid->getOriginalCollection();
            $this->custom_table->setSelectTableValues($customValueCollection);
        });

        // if modal, append to selectitem button
        if ($this->modal) {
            $this->appendSelectItemButton($grid);
        }

        return $grid;
    }

    /**
     * execute filter for modal
     *
     * @return void
     */
    protected function gridFilterForModal($grid, $filter_func)
    {
        // set request session data url disabled;
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_DISABLE_DATA_URL_TAG, true);

        $modal_target_view = CustomView::getEloquent(request()->get('target_view_id'));

        // modal use alldata view
        $this->custom_view = CustomView::getAllData($this->custom_table);

        // filter using modal_target_view, and display table
        if (isset($modal_target_view)) {
            $modal_target_view->filterModel($grid->model(), ['callback' => $filter_func]);
        }

        // filter display table
        $modal_display_table = CustomTable::getEloquent(request()->get('display_table_id'));
        $modal_custom_column = CustomColumn::getEloquent(request()->get('target_column_id'));
        if (!empty($modal_display_table) && !empty($modal_custom_column)) {
            $this->custom_table->filterDisplayTable($grid->model(), $modal_display_table, [
                'all' => $modal_custom_column->isGetAllUserOrganization(),
            ]);
        }

        ///// If set linkage, filter relation.
        // get children table id
        $expand = request()->get('linkage');
        if (!is_nullorempty($expand)) {
            RelationTable::setQuery($grid->model(), array_get($expand, 'search_type'), array_get($expand, 'linkage_value_id'), [
                'parent_table' => CustomTable::getEloquent(array_get($expand, 'parent_select_table_id')),
                'child_table' => CustomTable::getEloquent(array_get($expand, 'child_select_table_id')),
            ]);
        }
    }

    /**
     * Get filter html. call from ajax, or execute set filter.
     *
     * @return array offset 0 : html, 1 : script
     */
    public function getFilterHtml()
    {
        $classname = getModelName($this->custom_table);
        $grid = new Grid(new $classname);
        
        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();

        $this->setCustomGridFilters($grid, $search_enabled_columns, true);

        // get html force
        $html = null;
        $grid->filter(function ($filter) use (&$html) {
            $html = $filter->render();
        });

        return ['html' => $html, 'script' => \Admin::purescript()->render()];
    }

    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $search_enabled_columns, $ajax = false)
    {
        $grid->quickSearch(function ($model, $input) {
            $model->eloquent()->setSearchQueryOrWhere($model, $input);
        }, 'left');

        $grid->filter(function ($filter) use ($search_enabled_columns, $ajax) {
            $filter->disableIdFilter();
            $filter->setAction(admin_urls('data', $this->custom_table->table_name));

            if ($this->custom_table->enableShowTrashed() === true) {
                $filter->scope('trashed', exmtrans('custom_value.soft_deleted_data'))->onlyTrashed();
            }

            if (config('exment.custom_value_filter_ajax', true) && !$ajax && !$this->modal && !boolval(request()->get('execute_filter'))) {
                $filter->setFilterAjax(admin_urls_query('data', $this->custom_table->table_name, ['filter_ajax' => 1]));
                return;
            }
            
            if ($this->modal) {
                $filter->setAction(admin_urls_query('data', $this->custom_table->table_name, ['modal' => 1]));
            }

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

                $relationQuery = function ($query) use ($relation) {
                    if ($relation->relation_type == RelationType::ONE_TO_MANY) {
                        RelationTable::setQueryOneMany($query, $relation->parent_custom_table, $this->input);
                    } else {
                        RelationTable::setQueryManyMany($query, $relation->parent_custom_table, $relation->child_custom_table, $this->input);
                    }
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
                    $filterItems[] = function ($filter) {
                        $field = $filter->where(function ($query) {
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
        if ($this->modal) {
            $grid->disableRowSelector();
            $grid->disableCreateButton();
            $grid->disableExport();
            return;
        }

        $custom_table = $this->custom_table;
        $grid->disableCreateButton();
        $grid->disableExport();

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            $listButtons = Plugin::pluginPreparingButton(PluginEventTrigger::GRID_MENUBUTTON, $this->custom_table);
            
            // validate export and import
            $import = $this->custom_table->enableImport();
            $export = $this->custom_table->enableExport();
            if ($import === true || $export === true) {
                $button = new Tools\ExportImportButton(admin_urls('data', $this->custom_table->table_name), $grid, $export === true, $export === true, $import === true);
                $tools->append($button->setCustomTable($this->custom_table));
            }
            
            if ($this->custom_table->enableCreate(true) === true) {
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
            }

            // add page change button(contains view seting)
            if ($this->custom_table->enableTableMenuButton()) {
                $tools->append(new Tools\CustomTableMenuButton('data', $this->custom_table));
            }
            if ($this->custom_table->enableViewMenuButton()) {
                $tools->append(new Tools\CustomViewMenuButton($this->custom_table, $this->custom_view));
            }
            
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
        if ($this->modal) {
            $grid->disableActions();
            return;
        }

        if (isset($this->custom_table)) {
            // name
            $custom_table = $this->custom_table;
            $relationTables = $custom_table->getRelationTables();

            $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table, $relationTables) {
                $custom_table->setGridAuthoritable($actions->grid->getOriginalCollection());
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
    public function getImportExportService($grid = null)
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
    
    public function renderModalFrame()
    {
        // get target column id or class
        $custom_column = CustomColumn::getEloquent(request()->get('target_column_id'));
        $target_column_class = isset($custom_column) ? "value_{$custom_column->column_name}" :  request()->get('target_column_class');

        $items = $this->custom_table->getValueModel()->query()->whereOrIn('id', stringToArray(request()->get('selected_items')))->get();

        $url = request()->fullUrl() . '&modal=1';
        return getAjaxResponse([
            'title' => trans('admin.search') . ' : ' . $this->custom_table->table_view_name,
            'body'  => (new SelectItemBox(
                $url,
                $target_column_class,
                [[
                'name' => 'select',
                'label' =>  trans('admin.choose'),
                'multiple' => isset($custom_column) ? boolval($custom_column->getOption('multiple_enabled')) : false,
                'icon' => $this->custom_table->getOption('icon'),
                'background_color' =>  $this->custom_table->getOption('color') ?? '#3c8dbc', //if especially
                'color' => '#FFFFFF',
                'items' => $items->map(function ($item) {
                    return [
                        'value' => $item->id,
                        'label' => $item->getLabel(),
                    ];
                })->toArray(),
            ],
            ]
            ))->render(),
            'submitlabel' => trans('admin.setting'),
            'modalSize' => 'modal-xl',
            'modalClass' => 'modal-selectitem modal-heightfix modal-body-overflow-hidden',
            'preventSubmit' => true,
        ]);
    }

    public function renderModal($grid)
    {
        return view('exment::widgets.partialindex', [
            'content' => $grid->render()
        ]);
    }

    /**
     * Append select item button in grid
     *
     * @param Grid $grid
     * @return void
     */
    protected function appendSelectItemButton($grid)
    {
        $grid->column('modal_selectitem', trans('admin.action'))->display(function ($a, $b, $model) {
            return view('exment::tools.selectitem-button', [
                'value' => $model->id,
                'valueLabel' => $model->getLabel(),
                'label' => exmtrans('common.append_to_selectitem'),
                'target_selectitem' => 'select',
            ])->render();
        });
    }
}
