<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\HasResourceActions;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\ViewColumnFilterType;
use Exceedone\Exment\Enums\ViewColumnFilterOption;


class CustomViewController extends AdminControllerTableBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        
        $this->setPageInfo(exmtrans("custom_view.header"), exmtrans("custom_view.header"), exmtrans("custom_view.description"));
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
    public function edit(Request $request, $id, Content $content)
    {
        $this->setFormViewInfo($request);
        
        //Validation table value
        if (!$this->validateTable($this->custom_table, AuthorityValue::CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomView::class, $id, 'view')) {
            return;
        }
        return parent::edit($request, $id, $content);
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
        if (!$this->validateTable($this->custom_table, AuthorityValue::CUSTOM_TABLE)) {
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
        
        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }

        //  $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if (boolval($actions->row->system_flg)) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
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
        $form = new Form(new CustomView);
        $form->hidden('custom_table_id')->default($this->custom_table->id);
        $form->hidden('view_type')->default('system');
        
        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);
        
        $form->text('view_view_name', exmtrans("custom_view.view_view_name"))->required()->rules("max:40");
        $form->switchbool('default_flg', exmtrans("common.default"))->default(false);
        
        $custom_table = $this->custom_table;

        // columns setting
        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options(getColumnsSelectOptions($this->custom_table));
            $form->number('order', exmtrans("custom_view.order"))->min(0)->max(99)->required();
        })->setTableColumnWidth(6, 4, 2)
        ->description(exmtrans("custom_view.description_custom_view_columns"));

        // filter setting
        $form->hasManyTable('custom_view_filters', exmtrans("custom_view.custom_view_filters"), function ($form) use ($custom_table) {
            $form->select('view_filter_target', exmtrans("custom_view.view_filter_target"))->required()
                ->options(getColumnsSelectOptions($this->custom_table, true))
                ->attribute(['data-linkage' => json_encode(['view_filter_condition' => admin_base_path(url_join('view', $custom_table->table_name, 'filter-condition'))])]);

            $form->select('view_filter_condition', exmtrans("custom_view.view_filter_condition"))->required()
                ->options(function ($val) {
                    // if null, return empty array.
                    if (!isset($val)) {
                        return [];
                    }

                    ///// To find filter condition array group, filter id
                    foreach (ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS() as $key => $filter_option_blocks) {
                        // if match id, return $filter_option_blocks;
                        if (!is_null(collect($filter_option_blocks)->first(function ($array) use ($val) {
                            return array_get($array, 'id') == $val;
                        }))) {
                            $options = collect($filter_option_blocks)->pluck('name', 'id');
                            return collect($options)->map(function ($name) {
                                return exmtrans('custom_view.filter_condition_options.'.$name);
                            });
                        }
                    }
                    return [];
                });
            $form->text('view_filter_condition_value_text', exmtrans("custom_view.view_filter_condition_value_text"));
        })->setTableColumnWidth(3, 4, 4, 1)
        ->description(exmtrans("custom_view.description_custom_view_filters"));

        if (!isset($id)) {
            $id = $form->model()->id;
        }

        disableFormFooter($form);
        
        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use ($id, $form, $custom_table) {
            $tools->disableView();
            $tools->add((new Tools\GridChangePageMenu('view', $custom_table, false))->render());
        });
        return $form;
    }
    
    /**
     * get filter condition
     */
    public function getFilterCondition(Request $request)
    {
        $view_filter_target = $request->get('q');
        if (!isset($view_filter_target)) {
            return [];
        }

        ///// get column_type
        $column_type = null;
        // if $view_filter_target is number, get database_column_type
        if (is_numeric($view_filter_target)) {
            // get column_type
            $database_column_type = CustomColumn::find($view_filter_target)->column_type;
            switch ($database_column_type) {
                case 'date':
                case 'datetime':
                    $column_type = ViewColumnFilterType::DAY;
                    break;
                case SystemTableName::USER:
                    $column_type = ViewColumnFilterType::USER;
                    break;
                default:
                    $column_type = ViewColumnFilterType::DEFAULT;
            }
        } else {
            switch ($view_filter_target) {
                case 'id':
                case 'suuid':
                    $column_type = ViewColumnFilterType::DEFAULT;
                    break;
                case 'created_at':
                case 'updated_at':
                    $column_type = ViewColumnFilterType::DAY;
                    break;
                case 'created_user':
                case 'updated_user':
                    $column_type = ViewColumnFilterType::USER;
                    break;
            }
        }

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
}
