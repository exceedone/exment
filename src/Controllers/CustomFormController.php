<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Table;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custom Form Controller
 */
class CustomFormController extends AdminControllerTableBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->setPageInfo(exmtrans("custom_form.header"), exmtrans("custom_form.header"), exmtrans("custom_form.description"));
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if (!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)) {
            return;
        }
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
        if (!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomForm::class, $id, 'form')) {
            return;
        }
        $this->AdminContent($content);
        $this->droppableForm($content, $id);
        return $content;
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
        if (!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)) {
            return;
        }
        $this->AdminContent($content);
        $this->droppableForm($content);
        return $content;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if ($this->saveform($request, $id)) {
            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_base_path("form/{$this->custom_table->table_name}"));
        }
        return null; //TODO
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if ($this->saveform($request)) {
            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_base_path("form/{$this->custom_table->table_name}"));
        }
        return null; //TODO
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomForm);
        $grid->column('custom_table.table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('form_view_name', exmtrans("custom_form.form_view_name"))->sortable();

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }
        
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\GridChangePageMenu('form', $this->custom_table, false));
        });
        
        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
        });
        return $grid;
    }
    
    /**
     *
     * Make a form
     *
     * @return Form
     */
    protected function droppableForm($content, $id = null)
    {
        // get form
        $form = CustomForm::find($id);
        if (is_null($form)) {
            $form = new CustomForm;
        }

        // get form block list
        $custom_form_blocks = $this->getFormBlocks($form);

        foreach ($custom_form_blocks as &$custom_form_block) {
            $suggests = [];
            // get custom_columns (but not contains custom_form_columns)
            $this->setTableSuggests($form, $custom_form_block, $suggests);
            $custom_form_block['suggests'] = $suggests;
        }

        $date = \Carbon\Carbon::now()->format('YmdHis');
        // create endpoint
        $formroot = admin_base_path("form/{$this->custom_table->table_name}");
        $endpoint = $formroot.(isset($id) ? "/{$id}" : "");
        $content->row(view('exment::custom-form.form', [
            'formroot' => $formroot,
            'endpoint'=> $endpoint,
            'custom_form_blocks' => $custom_form_blocks,
            'css' => asset('/vendor/exment/css/customform.css?ver='.$date),
            'js' => asset('/vendor/exment/js/customform.js?ver='.$date),
            'editmode' => isset($id),
            'form_view_name' => $form->form_view_name,
            'change_page_menu' => (new Tools\GridChangePageMenu('form', $this->custom_table, false))->render()
        ]));
    }

    protected function getFormBlocks($form)
    {
        // Create Blocks as "table-self", "one-to-many tables", "many-to-many tables".
        // "table-self", "one-to-many tables" have form-columns.
        // "many-to-many tables" have only use or not use relation.
        // define relation tables
        $relations = $this->custom_table->custom_relations;
                
        // Loop using CustomFormBlocks
        $custom_form_blocks = [];
        foreach ($form->custom_form_blocks as $custom_form_block) {
            $column_blocks = $custom_form_block->toArray();
            // get label header.
            $column_blocks = array_merge($column_blocks, [
                'label' => $this->getBlockLabelHeader(array_get($column_blocks, 'form_block_type')) . $custom_form_block->target_table->table_view_name ?? null,
                'custom_form_columns' => [],
            ]);
            foreach ($custom_form_block->custom_form_columns as $custom_form_column) {
                $custom_form_column_array = $custom_form_column->toArray();
                if (!isset($custom_form_column_array['column_no'])) {
                    $custom_form_column_array['column_no'] = 1;
                }

                // get column view name
                switch ($custom_form_column->form_column_type) {
                    case Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN:
                        $custom_column = $custom_form_column->custom_column;
                        if (!isset($custom_column)) {
                            break 2; // break switch and Loop.
                        }
                        $column_view_name = $custom_column->column_view_name;
                        break;
                        
                    case Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM:
                        // get column name
                        $column_form_column_array = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($option) use ($custom_form_column) {
                            return array_get($option, 'id') == array_get($custom_form_column, 'form_column_target_id');
                        }) ?? [];
                        $column_form_column_name = array_get($column_form_column_array, 'name');
                        $column_view_name =  exmtrans("common.".array_get($column_form_column_array, 'name'));
                        break;
                    default:
                        // get column name
                        $column_form_column_array = array_get(Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE, array_get($custom_form_column, 'form_column_target_id'));
                        $column_form_column_name = array_get($column_form_column_array, 'column_name');
                        $column_view_name = exmtrans("custom_form.form_column_type_other_options.$column_form_column_name");
                        break;
                }
                // set view_name using custom_column info.
                $custom_form_column_array = array_merge($custom_form_column_array, [
                            'column_view_name' => $column_view_name
                        ]);

                // add header name
                $custom_form_column_array['header_column_name'] = '[custom_form_columns]['
                    .(isset($custom_form_column['id']) ? $custom_form_column['id'] : 'NEW__'.make_uuid())
                    .']';

                array_push($column_blocks['custom_form_columns'], $custom_form_column_array);
            }
            array_push($custom_form_blocks, $column_blocks);
        }

        // if $custom_form_blocks not have $block->form_block_type = default, set as default
        if (!collect($custom_form_blocks)->first(function ($custom_form_block) {
            return array_get($custom_form_block, 'form_block_type') == Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
        })) {
            $block = new CustomFormBlock;
            $block->id = null;
            $block->form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
            $block->form_block_target_table_id = $this->custom_table->id;
            $block->label = $this->getBlockLabelHeader(Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT) . $this->custom_table->table_view_name;
            $block->form_block_view_name = $block->label;
            $block->available = 1;
            $block->options = [];
            $block->custom_form_columns = [];
            array_push($custom_form_blocks, $block->toArray());
        }

        // check relation define.if not exists in custom_form_blocks, add define.
        foreach ($relations as $relation) {
            if (!collect($custom_form_blocks)->first(function ($custom_form_block) use ($relation) {
                return array_get($custom_form_block, 'form_block_type') == $relation->relation_type
                            && array_get($custom_form_block, 'form_block_target_table_id') == $relation->child_custom_table_id;
            })) {
                $block = new CustomFormBlock;
                $block->id = null;
                $block->form_block_type = $relation->relation_type;
                $block->form_block_target_table_id = $relation->child_custom_table_id;
                $block->label = $this->getBlockLabelHeader($relation->relation_type).$relation->child_custom_table->table_view_name;
                $block->form_block_view_name = $block->label;
                $block->available = 0;
                $block->options = [
                    'hasmany_type' => Define::RELATION_TYPE_ONE_TO_MANY ? 1 : null
                ];
                $block->custom_form_columns = [];
                array_push($custom_form_blocks, $block->toArray());
            }
        }

        $parent_table_id = null;
        foreach ($custom_form_blocks as &$custom_form_block) {
            // add header name
            $custom_form_block['header_name'] = 'custom_form_blocks['
                .(isset($custom_form_block['id']) ? $custom_form_block['id'] : 'NEW__'.make_uuid())
                .']';
            
            ///// Set changedata selection select list
            $select_table_columns = [];
            // get custom columns
            $form_block_target_table_id = array_get($custom_form_block, 'form_block_target_table_id');
            $custom_columns = CustomTable::find($form_block_target_table_id)->custom_columns->toArray();
            
            // if form block type is 1:n or n:n, get parent tables columns too. use parent_table_id.
            if (in_array(array_get($custom_form_block, 'form_block_type'), [Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY, Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_MANY_TO_MANY])) {
                $custom_columns = array_merge(
                    CustomTable::find($parent_table_id)->custom_columns->toArray(),
                    $custom_columns
                );
            }
            // else, get form_block_target_table_id as parent_table_id
            else {
                $parent_table_id = $form_block_target_table_id;
            }
            
            foreach ($custom_columns as $custom_column) {
                // if column_type is not select_table, return []
                if (!in_array(array_get($custom_column, 'column_type'), ['select_table', Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])) {
                    continue;
                }
                // if not have array_get($custom_column, 'options.select_target_table'), conitnue
                if (is_null(array_get($custom_column, 'options.select_target_table'))) {
                    continue;
                }
                // get select_table, user, organization columns
                $select_table_columns[array_get($custom_column, 'id')] = array_get($custom_column, 'column_view_name');
            }
            $custom_form_block['select_table_columns'] = collect($select_table_columns)->toJson();
        }

        return $custom_form_blocks;
    }

    /**
     * get custom form column suggest list.
     */
    protected function setTableSuggests($form, $custom_form_block, &$suggests = [])
    {
        // if form_block_type is n:n, no get columns.
        if (array_get($custom_form_block, 'form_block_type') != Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_MANY_TO_MANY) {

            // get columns by form_block_target_table_id.
            $custom_columns = CustomColumn::where('custom_table_id', array_get($custom_form_block, 'form_block_target_table_id'))->get()->toArray();
            $custom_form_columns = [];
            
            // set VIEW_COLUMN_SYSTEM_OPTIONS as header and footer
            $system_columns_header = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->filter(function ($option) {
                return array_key_value_exists('header', $option);
            });
            $system_columns_footer = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->filter(function ($option) {
                return array_key_value_exists('footer', $option);
            });
            
            $loops = [
                ['form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM , 'columns' => $system_columns_header],
                ['form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN , 'columns' => $custom_columns],
                ['form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM ,'columns' => $system_columns_footer],
            ];

            // loop header, custom_columns, footer
            foreach ($loops as $loop) {
                // get array items
                $form_column_type = array_get($loop, 'form_column_type');
                $columns = array_get($loop, 'columns');
                // loop each column
                foreach ($columns as &$custom_column) {
                    $has_custom_forms = false;
                    // check $form->custom_form_columns. if $custom_column has $this->custom_form_columns, add parameter has_custom_forms.
                    // if has_custom_forms is true, not show display default.
                    if ($form->custom_form_columns->first(function ($custom_form_column) use ($custom_column, $form_column_type) {
                        // if system column
                        if ($form_column_type == Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM) {
                            return array_get($custom_form_column, 'form_column_type') == Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM && array_get($custom_form_column, 'form_column_target_id') == array_get($custom_column, 'id');
                        }
                        // if column
                        else {
                            return array_get($custom_form_column, 'form_column_type') == Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN && array_get($custom_form_column, 'custom_column.id') == array_get($custom_column, 'id');
                        }
                    })) {
                        $has_custom_forms = true;
                    }

                    // re-set column
                    if ($form_column_type == Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM) {
                        $custom_column = [
                            'column_name' => array_get($custom_column, 'name'),
                            'column_view_name' => exmtrans("common.".array_get($custom_column, 'name')),
                            //'column_type' => null,
                            'form_column_type' => $form_column_type,
                            'form_column_target_id' => array_get($custom_column, 'id'),
                            'has_custom_forms' => $has_custom_forms,
                        ];
                    } else {
                        $custom_column = [
                            'column_name' => array_get($custom_column, 'column_name'),
                            'column_view_name' => array_get($custom_column, 'column_view_name'),
                            'column_type' => array_get($custom_column, 'column_type'),
                            'form_column_type' => $form_column_type,
                            'form_column_target_id' => array_get($custom_column, 'id'),
                            'has_custom_forms' => $has_custom_forms,
                        ];
                    }

                    array_push($custom_form_columns, $custom_column);
                }
            }
        
            // add header name
            foreach ($custom_form_columns as &$custom_form_column) {
                $header_column_name = '[custom_form_columns]['
                .(isset($custom_form_column['id']) ? $custom_form_column['id'] : 'NEW__'.make_uuid())
                .']';
                $custom_form_column['header_column_name'] = $header_column_name;
            }

            array_push($suggests, [
                'label' => exmtrans('custom_form.suggest_column_label'),
                'custom_form_columns' => $custom_form_columns,
                'clone' => false,
                'form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN,
            ]);
        }

        // set free html
        $custom_form_columns  = [];
        foreach (Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE as $id => $type) {
            $header_column_name = '[custom_form_columns][NEW__'.make_uuid().']';
            array_push($custom_form_columns, [
                'id' => null,
                'column_view_name' => exmtrans("custom_form.form_column_type_other_options.".array_get($type, 'column_name')),
                'form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_OTHER,
                'form_column_target_id' => $id,
                'header_column_name' =>$header_column_name
            ]);
        }
        array_push($suggests, [
            'label' => exmtrans('custom_form.suggest_other_label'),
            'custom_form_columns' => $custom_form_columns,
            'clone' => true,
            'form_column_type' => Define::CUSTOM_FORM_COLUMN_TYPE_OTHER,
        ]);
    }

    /**
     * Store form data
     */
    protected function saveform(Request $request, $id = null)
    {
        DB::beginTransaction();
        try {
            $inputs = $request->input('custom_form_blocks');

            // create form (if new form) --------------------------------------------------
            if (!isset($id)) {
                $form = new CustomForm;
                $form->custom_table_id = $this->custom_table->id;
            } else {
                $form = CustomForm::findOrFail($id);
            }
            $form->form_view_name = $request->input('form_view_name');
            $form->saveOrFail();
            $id = $form->id;

            foreach ($inputs as $key => $value) {
                // create blocks --------------------------------------------------
                // if key is "NEW_", create new block
                if (starts_with($key, 'NEW_')) {
                    $block = new CustomFormBlock;
                    $block->custom_form_id = $id;
                    $block->form_block_type = array_get($value, 'form_block_type');
                    $block->form_block_target_table_id = array_get($value, 'form_block_target_table_id');
                } else {
                    $block = CustomFormBlock::findOrFail($key);
                }
                $block->available = array_get($value, 'available') ?? 0;
                $block->form_block_view_name = array_get($value, 'form_block_view_name');
                $block->options = array_get($value, 'options', []);
                $block->saveOrFail();

                // create columns --------------------------------------------------
                $order = 1;
                if (!is_array(array_get($value, 'custom_form_columns'))) {
                    continue;
                }
                foreach (array_get($value, 'custom_form_columns') as $column_key => $column_value) {
                    if (!isset($column_value['form_column_type'])) {
                        continue;
                    }
                    // if key is "NEW_", create new column
                    $new_column = starts_with($column_key, 'NEW_');

                    // when user click delete, execute delete
                    if (!$new_column && boolval(array_get($column_value, 'delete_flg'))) {
                        CustomFormColumn::findOrFail($column_key)->delete();
                        continue;
                    } elseif ($new_column) {
                        $column = new CustomFormColumn;
                        $column->custom_form_block_id = $block->id;
                        $column->form_column_type = array_get($column_value, 'form_column_type');
                        if (is_null(array_get($column_value, 'form_column_target_id'))) {
                            continue;
                        }
                        $column->form_column_target_id = array_get($column_value, 'form_column_target_id');
                    } else {
                        $column = CustomFormColumn::findOrFail($column_key);
                    }
                    $column->column_no = array_get($column_value, 'column_no', 1);
                    $column->options = array_get($column_value, 'options');
                    $column->order = $order++;
                    $column->saveOrFail();
                }
            }

            DB::commit();
            return true;
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    // create form because we need for delete
    protected function form()
    {
        return Admin::form(CustomForm::class, function (Form $form) {
        });
    }

    /**
     * get form block label header
     */
    protected function getBlockLabelHeader($form_block_type){
        switch($form_block_type){
            case Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY:
                return exmtrans('custom_form.table_one_to_many_label');
            case Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_MANY_TO_MANY:
                return exmtrans('custom_form.table_many_to_many_label');
        }
        return exmtrans('custom_form.table_default_label');
    }
}
