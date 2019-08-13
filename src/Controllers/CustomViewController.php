<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Form\Field\ChangeField;

class CustomViewController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        
        $this->setPageInfo(exmtrans("custom_view.header"), exmtrans("custom_view.header"), exmtrans("custom_view.description"), 'fa-th-list');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        return parent::index($request, $content);
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        $this->setFormViewInfo($request);
        
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomView::class, $id, 'view')) {
            return;
        }

        // check has system permission
        if (!$this->hasSystemPermission()) {
            $view = CustomView::getEloquent($id);

            if ($view->view_type == Enums\ViewType::SYSTEM) {
                Checker::error();
                return false;
            } elseif ($view->created_user_id != \Exment::user()->base_user_id) {
                Checker::error();
                return false;
            }
        }
        
        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }

        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['view_view_name', 'default_flg', 'view_type', 'view_kind_type']));
        }

        return parent::create($request, $content);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomView);
        $grid->column('custom_table.table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('view_view_name', exmtrans("custom_view.view_view_name"))->sortable();
        if ($this->hasSystemPermission()) {
            $grid->column('view_type', exmtrans("custom_view.view_type"))->sortable()->display(function ($view_type) {
                return Enums\ViewType::getEnum($view_type)->transKey("custom_view.custom_view_type_options");
            });
        }

        if (!$this->hasSystemPermission()) {
            $grid->model()->where('view_type', Enums\ViewType::USER);
        }
        
        $grid->column('view_kind_type', exmtrans("custom_view.view_kind_type"))->sortable()->display(function ($view_kind_type) {
            return ViewKindType::getEnum($view_kind_type)->transKey("custom_view.custom_view_kind_type_options");
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
            $custom_table = $this->custom_table;
        }

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table) {
            if (isset($custom_table)) {
                $table_name = $custom_table->table_name;
            }
            if (boolval($actions->row->disabled_delete)) {
                $actions->disableDelete();
            }
            if (intval($actions->row->view_kind_type) === Enums\ViewKindType::AGGREGATE ||
                intval($actions->row->view_kind_type) === Enums\ViewKindType::CALENDAR) {
                $actions->disableEdit();
                
                $linker = (new Linker)
                    ->url(admin_urls('view', $table_name, $actions->getKey(), 'edit').'?view_kind_type='.$actions->row->view_kind_type)
                    ->icon('fa-edit')
                    ->tooltip(trans('admin.edit'));
                $actions->prepend($linker);
            }
            // if ($actions->row->disabled_delete) {
            //     $actions->disableDelete();
            // }
            $actions->disableView();

            if (intval($actions->row->view_kind_type) != Enums\ViewKindType::FILTER) {
                $linker = (new Linker)
                ->url($custom_table->getGridUrl(true, ['view' => $actions->row->suuid]))
                ->icon('fa-database')
                ->tooltip(exmtrans('custom_view.view_datalist'));
                $actions->prepend($linker);
            }
            
            $linker = (new Linker)
                ->url(admin_urls('view', $table_name, "create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('custom_view.custom_view_button_label')));
            $actions->prepend($linker);
        });

        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            // ctrate newbutton (list) --------------------------------------------------
            $lists = $this->getMenuItems();
            $tools->append(view('exment::tools.newlist-button', [
                'label' => trans('admin.new'),
                'menu' => $lists
            ]));
            $tools->append(new Tools\GridChangePageMenu('view', $this->custom_table, false));
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null, $copy_id = null)
    {
        // get request
        $request = Request::capture();
        $copy_custom_view = CustomView::getEloquent($copy_id);
        
        $form = new Form(new CustomView);

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        if (isset($id)) {
            $model = CustomView::getEloquent($id);
        }
        if (isset($model)) {
            $suuid = $model->suuid;
            $view_kind_type = $model->view_kind_type;
        } else {
            $suuid = null;
            $view_kind_type = null;
        }
        
        // get view_kind_type
        if (!is_null($request->input('view_kind_type'))) {
            $view_kind_type = $request->input('view_kind_type');
        } elseif (!is_null($request->query('view_kind_type'))) {
            $view_kind_type =  $request->query('view_kind_type');
        } elseif (isset($copy_custom_view)) {
            $view_kind_type =  array_get($copy_custom_view, 'view_kind_type');
            // if all data, change default
            if ($view_kind_type == ViewKindType::ALLDATA) {
                $view_kind_type = ViewKindType::DEFAULT;
            }
        } elseif (is_null($view_kind_type)) {
            $view_kind_type = ViewKindType::DEFAULT;
        }
        
        // get from_data
        $from_data = false;
        if ($request->has('from_data')) {
            $from_data = boolval($request->get('from_data'));
        }

        $form->hidden('custom_table_id')->default($this->custom_table->id);

        $form->hidden('view_kind_type')->default($view_kind_type);
        $form->hidden('from_data')->default($from_data);
        
        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);
        $form->display('view_kind_type', exmtrans("custom_view.view_kind_type"))
            ->with(function ($value) use ($view_kind_type) {
                return ViewKindType::getEnum($value?? $view_kind_type)->transKey("custom_view.custom_view_kind_type_options");
            });

        $form->text('view_view_name', exmtrans("custom_view.view_view_name"))->required()->rules("max:40");

        if (intval($view_kind_type) == Enums\ViewKindType::FILTER) {
            $form->hidden('view_type')->default(Enums\ViewType::SYSTEM);
        } else {
            // select view type
            if (!isset($id) && $this->hasSystemPermission()) {
                $form->select('view_type', exmtrans('custom_view.view_type'))
                    ->default(Enums\ViewType::SYSTEM)
                    ->config('allowClear', false)
                    ->options(Enums\ViewType::transKeyArray('custom_view.custom_view_type_options'));
            } else {
                $form->hidden('view_type')->default(Enums\ViewType::USER);
            }
        }
        
        if ($view_kind_type == Enums\ViewKindType::DEFAULT) {
            $form->select('pager_count', exmtrans("common.pager_count"))
            ->required()
            ->options(getPagerOptions(true))
            ->config('allowClear', false)
            ->default(0);
        }
        
        if (intval($view_kind_type) != Enums\ViewKindType::FILTER) {
            $form->switchbool('default_flg', exmtrans("common.default"))->default(false);
        }
        
        $custom_table = $this->custom_table;
        $is_aggregate = false;
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        switch (intval($view_kind_type)) {
            case Enums\ViewKindType::AGGREGATE:
                // group columns setting
                $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_groups"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getColumnSelectOptions([
                            'append_table' => true,
                            'index_enabled_only' => true,
                            'include_parent' => true,
                            'include_child' => true,
                        ]))
                        ->attribute([
                            'data-linkage' => json_encode(['view_group_condition' => admin_urls('view', $custom_table->table_name, 'group-condition')]),
                            'data-change_field_target' => 'view_column_target',
                        ]);
                    
                    $form->text('view_column_name', exmtrans("custom_view.view_column_name"))->icon(null);

                    $controller = $this;
                    $form->select('view_group_condition', exmtrans("custom_view.view_group_condition"))
                        ->options(function ($val, $form) use ($controller) {
                            if (is_null($data = $form->data())) {
                                return [];
                            }
                            if (is_null($view_column_target = array_get($data, 'view_column_target'))) {
                                return [];
                            }
                            return collect($controller->_getGroupCondition($view_column_target))->pluck('text', 'id')->toArray();
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
                ->description(sprintf(exmtrans("custom_view.description_custom_view_groups"), $manualUrl));

                // summary columns setting
                $form->hasManyTable('custom_view_summaries', exmtrans("custom_view.custom_view_summaries"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getSummaryColumnsSelectOptions())
                        ->attribute(['data-linkage' => json_encode(['view_summary_condition' => admin_urls('view', $custom_table->table_name, 'summary-condition')])]);
                    $form->select('view_summary_condition', exmtrans("custom_view.view_summary_condition"))
                        ->options(function ($val) {
                            return array_map(function ($array) {
                                return exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'));
                            }, SummaryCondition::getOptions());
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
                ->description(sprintf(exmtrans("custom_view.description_custom_view_summaries"), $manualUrl));

                // filter setting
                $this->setFilterFields($form, $custom_table, true);
                break;

            case Enums\ViewKindType::CALENDAR:
                // columns setting
                $hasmany = $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_start_date"))
                        ->required()
                        ->options($this->custom_table->getDateColumnsSelectOptions());
                    $form->select('view_column_end_date', exmtrans("custom_view.view_column_end_date"))
                        ->options($this->custom_table->getDateColumnsSelectOptions());
                    $form->color('view_column_color', exmtrans("custom_view.color"))
                        ->required()
                        ->default(config('exment.calendor_color_default', '#00008B'));
                    $form->color('view_column_font_color', exmtrans("custom_view.font_color"))
                        ->required()
                        ->default(config('exment.calendor_font_color_default', '#FFFFFF'));
                })->required()->setTableColumnWidth(4, 3, 2, 2, 1)
                ->description(sprintf(exmtrans("custom_view.description_custom_view_calendar_columns"), $manualUrl));

                // filter setting
                $this->setFilterFields($form, $custom_table);
                break;
            default:
                if ($view_kind_type != Enums\ViewKindType::FILTER) {
                    // columns setting
                    $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
                        $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                            ->options($this->custom_table->getColumnSelectOptions([
                                'append_table' => true,
                                'include_select_table' => true,
                            ]));
                        $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
                        $form->hidden('order')->default(0);
                    })->required()->setTableColumnWidth(7, 3, 2)
                    ->rowUpDown('order')
                    ->description(sprintf(exmtrans("custom_view.description_custom_view_columns"), $manualUrl));
                }

                // filter setting
                if ($view_kind_type != Enums\ViewKindType::ALLDATA) {
                    $this->setFilterFields($form, $custom_table);
                }

                // sort setting
                $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                    ->options($this->custom_table->getColumnSelectOptions([
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
                ->description(sprintf(exmtrans("custom_view.description_custom_view_sorts"), $manualUrl));
        }

        $custom_table = $this->custom_table;

        $form->ignore('from_data');

        // check filters and sorts count before save
        $form->saving(function (Form $form) {
            if (!is_null($form->custom_view_filters)) {
                $cnt = collect($form->custom_view_filters)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                })->count();
                if ($cnt > 5) {
                    admin_toastr(exmtrans('custom_view.message.over_filters_max'), 'error');
                    return back()->withInput();
                }
            }
            if (!is_null($form->custom_view_sorts)) {
                $cnt = collect($form->custom_view_sorts)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                })->count();
                if ($cnt > 5) {
                    admin_toastr(exmtrans('custom_view.message.over_sorts_max'), 'error');
                    return back()->withInput();
                }
            }
        });

        $form->saved(function (Form $form) use ($from_data, $custom_table) {
            if (boolval($from_data) && $form->model()->view_kind_type != Enums\ViewKindType::FILTER) {
                // get view suuid
                $suuid = $form->model()->suuid;
                return redirect($custom_table->getGridUrl(true, ['view' => $suuid]));
            }
        });

        $form->tools(function (Form\Tools $tools) use ($id, $suuid, $form, $custom_table) {
            $tools->add((new Tools\GridChangePageMenu('view', $custom_table, false))->render());

            if (isset($suuid)) {
                $tools->append(view('exment::tools.button', [
                    'href' => $custom_table->getGridUrl(true, ['view' => $suuid]),
                    'label' => exmtrans('custom_view.view_datalist'),
                    'icon' => 'fa-database',
                    'btn_class' => 'btn-purple',
                ]));
            }
        });
        
        $table_name = $this->custom_table->table_name;
        $script = <<<EOT
            $('#has-many-table-custom_view_filters').off('change').on('change', '.view_filter_condition', function (ev) {
                $.ajax({
                    url: admin_url("view/$table_name/filter-value"),
                    type: "GET",
                    data: {
                        'target': $(this).closest('tr.has-many-table-custom_view_filters-row').find('select.view_column_target').val(),
                        'cond_name': $(this).attr('name'),
                        'cond_val': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr.has-many-table-custom_view_filters-row').find('td:nth-child(3)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
EOT;
        Admin::script($script);
        return $form;
    }

    protected function setFilterFields(&$form, $custom_table, $is_aggregate = false)
    {
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
        // filter setting
        $form->hasManyTable('custom_view_filters', exmtrans("custom_view.custom_view_filters"), function ($form) use ($custom_table, $is_aggregate) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($this->custom_table->getColumnSelectOptions(
                    [
                        'append_table' => true,
                        'index_enabled_only' => true,
                        'include_parent' => $is_aggregate,
                        'include_child' => $is_aggregate,
                    ]
                ))
                ->attribute([
                    'data-linkage' => json_encode(['view_filter_condition' => admin_urls('view', $custom_table->table_name, 'filter-condition')]),
                    'data-change_field_target' => 'view_column_target',
                ]);

            $form->select('view_filter_condition', exmtrans("custom_view.view_filter_condition"))->required()
                ->options(function ($val, $select) {
                    // if null, return empty array.
                    if (!isset($val)) {
                        return [];
                    }

                    $data = $select->data();
                    $view_column_target = array_get($data, 'view_column_target');

                    if (array_get($data, 'view_column_type') != ViewColumnType::COLUMN) {
                        list($table_name, $target_id) = explode("-", $view_column_target);
                        if (is_numeric($target_id)) {
                            $view_column_target = $table_name . '-' . SystemColumn::getOption(['id' => $target_id])['name'];
                        }
                    }

                    // get column item
                    $column_item = CustomViewFilter::getColumnItem($view_column_target)
                        ->options([
                            'view_column_target' => true,
                        ]);

                    ///// get column_type
                    $column_type = $column_item->getViewFilterType();

                    // if null, return []
                    if (!isset($column_type)) {
                        return [];
                    }

                    // get target array
                    $options = array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), $column_type);
                    return collect($options)->mapWithKeys(function ($array) {
                        return [$array['id'] => exmtrans('custom_view.filter_condition_options.'.$array['name'])];
                    });

                    return [];
                });
            $form->changeField('view_filter_condition_value', exmtrans("custom_view.view_filter_condition_value_text"))
                ->rules('changeFieldValue');
        })->setTableColumnWidth(4, 4, 3, 1)
        ->description(sprintf(exmtrans("custom_view.description_custom_view_filters"), $manualUrl));
    }

    protected function hasSystemPermission()
    {
        return $this->custom_table->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VIEW]);
    }

    /**
     * get filter condition
     */
    public function getSummaryCondition(Request $request)
    {
        $view_column_target = $request->get('q');
        if (!isset($view_column_target)) {
            return [];
        }

        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        // only numeric
        if ($columnItem->isNumeric()) {
            $options = SummaryCondition::getOptions();
        } else {
            $options = SummaryCondition::getOptions(['numeric' => false]);
        }
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'))];
        });
    }

    public function getGroupCondition(Request $request)
    {
        return $this->_getGroupCondition($request->get('q'));
    }

    /**
     * get group condition
     */
    protected function _getGroupCondition($view_column_target = null)
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

    /**
     * get filter condition
     */
    public function getFilterValue(Request $request)
    {
        $data = $request->all();

        if (!array_key_exists('target', $data) ||
            !array_key_exists('cond_val', $data) ||
            !array_key_exists('cond_name', $data)) {
            return [];
        }
        $columnname = 'view_filter_condition_value';

        $field = new ChangeField($columnname, exmtrans('custom_view.'.$columnname.'_text'));
        $field->data([
            'view_column_target' => $data['target'],
            'view_filter_condition' => $data['cond_val']
        ]);
        $element_name = str_replace('view_filter_condition', 'view_filter_condition_value', $data['cond_name']);
        $field->setElementName($element_name);

        $view = $field->render();
        return \json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }

    /**
     * get filter condition
     */
    public function getFilterCondition(Request $request)
    {
        $view_column_target = $request->get('q');
        if (!isset($view_column_target)) {
            return [];
        }
        
        // get column item
        $column_item = CustomViewFilter::getColumnItem($view_column_target)
            ->options([
                'view_column_target' => true,
            ]);

        ///// get column_type
        $column_type = $column_item->getViewFilterType();

        // if null, return []
        if (!isset($column_type)) {
            return [];
        }

        // get target array
        $options = array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), $column_type);
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.filter_condition_options.'.array_get($array, 'name'))];
        });
    }
    
    /**
     * get filter value dialog html
     */
    public function getFilterDialogHtml(Request $request)
    {
        $view_column_target = $request->input('view_column_target');
        if (!isset($view_column_target)) {
            return null;
        }

        // get column item
        $column_item = CustomViewFilter::getColumnItem($view_column_target)
            ->options([
                'view_column_target' => true,
            ]);

        // create modal form
        $form = new ModalForm();
        $form->method('POST');
        $form->modalHeader('');

        // set form
        $form->pushField($column_item->getAdminField());

        return $form->render()->render();
    }

    protected function getMenuItems()
    {
        $view_kind_types = [
            ['name' => 'create', 'uri' => 'create'],
            ['name' => 'create_sum', 'uri' => 'create?view_kind_type=1'],
            ['name' => 'create_calendar', 'uri' => 'create?view_kind_type=2'],
        ];

        if ($this->hasSystemPermission()) {
            $view_kind_types[] = ['name' => 'create_filter', 'uri' => 'create?view_kind_type=3'];
        }

        // loop for role types
        $lists = [];
        foreach ($view_kind_types as  $view_kind_type) {
            $lists[] = [
                'href' => admin_urls('view', $this->custom_table->table_name, $view_kind_type['uri']),
                'label' => exmtrans("custom_view.custom_view_menulist.{$view_kind_type['name']}"),
            ];
        }

        return $lists;
    }
}
