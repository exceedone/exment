<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Enums;
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
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomView::class, $id, 'view')) {
            return;
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
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
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
        $grid->column('view_kind_type', exmtrans("custom_view.view_kind_type"))->sortable()->display(function($view_kind_type){
            return ViewKindType::getEnum($view_kind_type)->transKey("custom_view.custom_view_kind_type_options");
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
            $table_name = $this->custom_table->table_name;
        }

        //  $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($table_name) {
            if (boolval($actions->row->system_flg)) {
                $actions->disableDelete();
            }
            if (intval($actions->row->view_kind_type) === Enums\ViewKindType::AGGREGATE ||
                intval($actions->row->view_kind_type) === Enums\ViewKindType::CALENDAR) {
                $actions->disableEdit();
                $actions->prepend('<a href="'.admin_urls('view', $table_name, $actions->getKey(), 'edit').'?view_kind_type='.$actions->row->view_kind_type.'"><i class="fa fa-edit"></i></a>');
            }
            $actions->disableView();

            $linker = (new Linker)
                ->url(admin_urls('data', "{$table_name}?view={$actions->row->suuid}"))
                ->icon('fa-database')
                ->tooltip(exmtrans('custom_view.view_datalist'));
            $actions->prepend($linker);
        });

        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            // add new button
            $view_kind_types = [
                ['name' => 'create', 'uri' => 'create'],
                ['name' => 'create_sum', 'uri' => 'create?view_kind_type=1'],
                ['name' => 'create_calendar', 'uri' => 'create?view_kind_type=2'],
            ];

            $addNewBtn = '<div class="btn-group pull-right">
                <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                    <i class="fa fa-plus"></i>&nbsp;'.trans('admin.new') . '
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu" role="menu">';
            // loop for role types
            foreach ($view_kind_types as  $view_kind_type) {
                $addNewBtn .= '<li><a href="'.admin_urls('view', $this->custom_table->table_name, $view_kind_type['uri']).'">'.exmtrans("custom_view.custom_view_menulist.{$view_kind_type['name']}").'</a></li>';
            }
            $addNewBtn .= '</ul></div>';
            $tools->append($addNewBtn);
            
            $tools->append(new Tools\GridChangePageMenu('view', $this->custom_table, false));
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        // get request
        $request = Request::capture();
        if (!is_null($request->input('view_kind_type'))) {
            $view_kind_type = $request->input('view_kind_type');
        } else {
            $view_kind_type = $request->query('view_kind_type')?? '0';
        }

        $form = new Form(new CustomView);
        $form->hidden('custom_table_id')->default($this->custom_table->id);
        $form->hidden('view_type')->default(Enums\ViewType::SYSTEM);
        $form->hidden('view_kind_type')->default($view_kind_type);
        
        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);
        
        $form->text('view_view_name', exmtrans("custom_view.view_view_name"))->required()->rules("max:40");
        $form->switchbool('default_flg', exmtrans("common.default"))->default(false);
        
        $custom_table = $this->custom_table;
        $is_aggregate = false;

        switch (intval($view_kind_type)) {
            case Enums\ViewKindType::AGGREGATE:
                // group columns setting
                $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_groups"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getColumnsSelectOptions(true, true, true));
                    $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
                    $form->number('order', exmtrans("custom_view.order"))->min(0)->max(99)->required();
                })->required()->setTableColumnWidth(4, 3, 2, 1)
                ->description(exmtrans("custom_view.description_custom_view_groups"));

                // summary columns setting
                $form->hasManyTable('custom_view_summaries', exmtrans("custom_view.custom_view_summaries"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getSummaryColumnsSelectOptions());
                    //                    ->attribute(['data-linkage' => json_encode(['view_summary_condition' => admin_urls('view', $custom_table->table_name, 'summary-condition')])]);
                    $form->select('view_summary_condition', exmtrans("custom_view.view_summary_condition"))
                        ->options(function ($val) {
                            return array_map(function ($array) {
                                return exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'));
                            }, SummaryCondition::getOptions());
                        })
                        ->required()->rules('summaryCondition');
                    $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
                })->setTableColumnWidth(4, 2, 3, 1)
                ->description(exmtrans("custom_view.description_custom_view_summaries"));

                $is_aggregate = true;
                break;
            case Enums\ViewKindType::CALENDAR:
                // columns setting
                $hasmany = $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getDateColumnsSelectOptions());
                        $form->color('view_column_color', exmtrans("custom_view.color"))->default(config('exment.calendor_color_default', '#00008B'));
                        $form->color('view_column_font_color', exmtrans("custom_view.font_color"))->default(config('exment.calendor_font_color_default', '#FFFFFF'));
                })->required()->setTableColumnWidth(7, 2, 2, 1)
                ->description(exmtrans("custom_view.description_custom_view_calendar_columns"));
                break;
            default:
                // columns setting
                $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
                    $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                        ->options($this->custom_table->getColumnsSelectOptions(true));
                    $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
                    $form->number('order', exmtrans("custom_view.order"))->min(0)->max(99)->required();
                })->required()->setTableColumnWidth(4, 3, 2, 1)
                ->description(exmtrans("custom_view.description_custom_view_columns"));
                break;
        }

        // filter setting
        $form->hasManyTable('custom_view_filters', exmtrans("custom_view.custom_view_filters"), function ($form) use ($custom_table, $is_aggregate) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($this->custom_table->getColumnsSelectOptions(true, true, $is_aggregate, $is_aggregate))
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
        ->description(sprintf(exmtrans("custom_view.description_custom_view_filters"), getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'))));

        // sort setting
        if (intval($view_kind_type) == Enums\ViewKindType::DEFAULT) {
            $form->hasManyTable('custom_view_sorts', exmtrans("custom_view.custom_view_sorts"), function ($form) use ($custom_table) {
                $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($this->custom_table->getColumnsSelectOptions(true, true));
                $form->select('sort', exmtrans("custom_view.sort"))->options([1 => exmtrans('common.asc'), -1 => exmtrans('common.desc')])->required()->default(1);
                $form->number('priority', exmtrans("custom_view.priority"))->min(0)->max(99)->required();
            })->setTableColumnWidth(4, 3, 3, 2)
            ->description(sprintf(exmtrans("custom_view.description_custom_view_sorts"), getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'))));
        }

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        if(isset($id)){
            $suuid = CustomView::find($id)->suuid;
        }else{
            $suuid = null;
        }
        
        $custom_table = $this->custom_table;

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
        $form->tools(function (Form\Tools $tools) use ($id, $suuid, $form, $custom_table) {
            $tools->add((new Tools\GridChangePageMenu('view', $custom_table, false))->render());

            if(isset($suuid)){
                $tools->append('<div class="btn-group pull-right" style="margin-right: 5px">
                <a href="'. admin_urls('data', "{$custom_table->table_name}?view={$suuid}") . '" class="btn btn-sm btn-pupple" title="'. exmtrans('custom_view.view_datalist') . '">
                    <i class="fa fa-database"></i><span class="hidden-xs"> '. exmtrans('custom_view.view_datalist') . '</span>
                </a>
            </div>');
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
    /**
     * get filter condition
     */
    public function getSummaryCondition(Request $request)
    {
        $view_column_target = $request->get('q');
        if (!isset($view_column_target)) {
            return [];
        }
        // target column type for summary is numeric or system only
        if (is_numeric($view_column_target)) {
            $options = SummaryCondition::getOptions();
        } else {
            $options = SummaryCondition::getOptions(['numeric' => false]);
        }
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'))];
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
}
