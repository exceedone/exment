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
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;
use Illuminate\Http\Request;
use Encore\Admin\Form;

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
        $this->setGrid($grid);

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
     * Get database query
     *
     * @param [type] $query
     * @param array $options
     * @return
     */
    public function getQuery($query, array $options = [])
    {
        // Now only execute filter Model
        return $this->custom_view->filterModel($query, $options);
    }


    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        $custom_table = $this->custom_table;
        // get view columns
        $custom_view_columns = $this->custom_view->custom_view_columns_cache;
        foreach ($custom_view_columns as $custom_view_column) {
            $item = $custom_view_column->column_item;
            if (!isset($item)) {
                continue;
            }

            $item = $item->label(array_get($custom_view_column, 'view_column_name'))
                ->options([
                    'grid_column' => true,
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ]);
            $name = $item->indexEnabled() ? $item->index() : $item->uniqueName();
            $className = $item->indexEnabled() ? 'column-' . $item->name() : '';
            $grid->column($name, $item->label())
                ->sort($item->sortable())
                ->cast($item->getCastName())
                ->style($item->gridStyle())
                ->setClasses($className)
                ->display(function ($v) use ($item) {
                    if (is_null($this)) {
                        return '';
                    }
                    return $item->setCustomValue($this)->html();
                });
        }

        // set parpage
        $pager_count = $this->custom_view->pager_count;
        if (is_null(request()->get('per_page')) && isset($pager_count) && is_numeric($pager_count) && $pager_count > 0) {
            $grid->paginate(intval($pager_count));
        }

        // set with
        $custom_table->setQueryWith($grid->model(), $this->custom_view);
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

                $relationQuery = function ($query, $input) use ($relation) {
                    if ($relation->relation_type == RelationType::ONE_TO_MANY) {
                        RelationTable::setQueryOneMany($query, $relation->parent_custom_table, $input);
                    } else {
                        RelationTable::setQueryManyMany($query, $relation->parent_custom_table, $relation->child_custom_table, $input);
                    }
                };

                // set relation
                if (isset($ajax)) {
                    $filterItems[] = function ($filter) use ($relationQuery, $table_view_name, $ajax) {
                        $filter->exmwhere($relationQuery, $table_view_name)->select([])->ajax($ajax, 'id', 'text');
                    };
                } else {
                    $filterItems[] = function ($filter) use ($relationQuery, $table_view_name, $options) {
                        $filter->exmwhere($relationQuery, $table_view_name)->select($options);
                    };
                }
            }

            // filter workflow
            if (!is_null($workflow = Workflow::getWorkflowByTable($this->custom_table))) {
                $custom_table = $this->custom_table;

                if (!$custom_table->gridFilterDisable('workflow_status')) {
                    $filterItems[] = function ($filter) use ($workflow, $custom_table) {
                        $field = $filter->exmwhere(function ($query, $input) use ($custom_table) {
                            WorkflowItem::scopeWorkflowStatus($query, $custom_table, FilterOption::EQ, $input);
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
                        if ($custom_operation->matchOperationType(Enums\CustomOperationType::BULK_UPDATE)) {
                            $batch->add($custom_operation->operation_name, new GridTools\BatchUpdate($custom_operation));
                        }
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
                        ->url($actions->row->getRelationSearchUrl())
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

    public function renderModalFrame()
    {
        // get target column id or class
        $custom_column = CustomColumn::getEloquent(request()->get('target_column_id'));
        $target_column_class = request()->get('target_column_class');
        $target_column_multiple = request()->get('target_column_multiple') ?? (isset($custom_column) ? boolval($custom_column->getOption('multiple_enabled')) : false);
        $widgetmodal_uuid = request()->get('widgetmodal_uuid');

        $items = $this->custom_table->getValueModel()->query()->whereOrIn('id', stringToArray(request()->get('selected_items')))->get();

        $url = request()->fullUrl() . '&modal=1';
        return getAjaxResponse([
            'title' => trans('admin.search') . ' : ' . $this->custom_table->table_view_name,
            'body'  => (new SelectItemBox(
                $url,
                $target_column_class,
                $widgetmodal_uuid,
                [[
                'name' => 'select',
                'label' =>  trans('admin.choose'),
                'multiple' => $target_column_multiple,
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

    /**
     * Set custom view columns form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table)
    {
        if (in_array($view_kind_type, [Enums\ViewKindType::DEFAULT, Enums\ViewKindType::ALLDATA])) {
            $form->select('pager_count', exmtrans("common.pager_count"))
                ->required()
                ->options(getPagerOptions(true))
                ->config('allowClear', false)
                ->default(0);
        }

        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        if ($view_kind_type != Enums\ViewKindType::FILTER) {
            // columns setting
            $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
                $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                    ->options($custom_table->getColumnsSelectOptions([
                        'append_table' => true,
                        'include_parent' => true,
                        'include_workflow' => true,
                    ]));
                $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
                $form->hidden('order')->default(0);
            })->required()->setTableColumnWidth(7, 3, 2)
            ->rowUpDown('order', 10)
            ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_columns"), $manualUrl));
        }

        // filter setting
        if ($view_kind_type != Enums\ViewKindType::ALLDATA) {
            static::setFilterFields($form, $custom_table);
        }

        // sort setting
        $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
            ->options($custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => true,
            ]));
            $form->select('sort', exmtrans("custom_view.sort"))->options(Enums\ViewColumnSort::transKeyArray('custom_view.column_sort_options'))
                ->required()
                ->default(1)
                ->help(exmtrans('custom_view.help.sort_type'));
            $form->hidden('priority')->default(0);
        })->setTableColumnWidth(7, 3, 2)
        ->rowUpDown('priority')
        ->descriptionHtml(sprintf(exmtrans("custom_view.description_custom_view_sorts"), $manualUrl));
    }
}
