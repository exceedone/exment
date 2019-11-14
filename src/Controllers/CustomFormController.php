<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Table;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custom Form Controller
 */
class CustomFormController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);
        $this->setPageInfo(exmtrans("custom_form.header"), exmtrans("custom_form.header"), exmtrans("custom_form.description"), 'fa-keyboard-o');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        $this->AdminContent($content);
        $content->body($this->grid());
        $content->row($this->setFormPriorities());
        return $content;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function setFormPriorities()
    {
        $grid = new Grid(new CustomFormPriority);
        $grid->model()->orderBy('order');
        $grid->setTitle(exmtrans("custom_form.priority.title"));
        $grid->setResource(admin_urls('formpriority', $this->custom_table->table_name));
        $grid->column('form_priority_text', exmtrans("custom_form.priority.form_priority_text"));
        $grid->column('form_view_name', exmtrans("custom_form.priority.form_view_name"));
        $grid->column('order', exmtrans("custom_form.priority.order"))->editable('number');

        if (isset($this->custom_table)) {
            $grid->model()
                ->select(['custom_form_priorities.*', 'custom_forms.form_view_name'])
                ->join('custom_forms', 'custom_forms.id', '=', 'custom_form_priorities.custom_form_id')
                ->where('custom_forms.custom_table_id', $this->custom_table->id);
        }
        
        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });
        
        $grid->disableExport();
        $grid->disableRowSelector();
        $grid->disableFilter();
        $grid->actions(function ($actions) {
            $actions->disableView();
            // $actions->disableDelete();
        });
        return $grid;
    }

    /**
     * priority update interface.
     *
     * @param $tableKey
     * @param $id
     */
    public function priority(Request $request, $tableKey, $id)
    {
        $column_name = $request->get('name');
        $column_value = $request->get('value');

        if (isset($column_value) && is_numeric($column_value)) {
            $custom_form_priority = CustomFormPriority::find($id);
            $custom_form_priority->order = $column_value;
            $result = $custom_form_priority->save();
    
            if ($result) {
                admin_toastr(trans('admin.save_succeeded'));
            }
            //            return back()->withInput();
        }
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomForm::class, $id, 'form')) {
            return;
        }
        $this->AdminContent($content);
        $this->createForm($content, $id);
        return $content;
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        $this->AdminContent($content);
        $this->createForm($content);
        return $content;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $tableKey, $id)
    {
        if (!$this->saveformValidate($request, $id)) {
            admin_toastr(exmtrans('custom_form.message.no_exists_column'), 'error');
            return back()->withInput();
        }

        if ($this->saveform($request, $id)) {
            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_url("form/{$this->custom_table->table_name}"));
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
        if (!$this->saveformValidate($request)) {
            admin_toastr(exmtrans('custom_form.message.no_exists_column'), 'error');
            return back()->withInput();
        }

        if ($this->saveform($request)) {
            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_url("form/{$this->custom_table->table_name}"));
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
        $grid->column('default_flg', exmtrans("custom_form.default_flg"))->sortable()->display(function ($val) {
            return getTrueMark($val);
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }
        
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\GridChangePageMenu('form', $this->custom_table, false));
            
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });
        
        $grid->disableExport();
        $grid->disableRowSelector();
        // $grid->disableCreateButton();
        $grid->actions(function ($actions) {
            $actions->disableView();
            // $actions->disableDelete();
        });
        return $grid;
    }
    
    /**
     *
     * Make a form
     *
     * @return Form
     */
    protected function createForm($content, $id = null)
    {
        // get form
        $form = CustomForm::getEloquent($id);
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
        
        // get exment version
        $ver = getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }

        // create endpoint
        $formroot = admin_url("form/{$this->custom_table->table_name}");
        $endpoint = $formroot.(isset($id) ? "/{$id}" : "");
        $content->row(view('exment::custom-form.form', [
            'formroot' => $formroot,
            'endpoint'=> $endpoint,
            'custom_form_blocks' => $custom_form_blocks,
            'css' => asset('/vendor/exment/css/customform.css?ver='.$ver),
            'js' => asset('/vendor/exment/js/customform.js?ver='.$ver),
            'editmode' => isset($id),
            'form_view_name' => $form->form_view_name,
            'default_flg' => $form->default_flg?? '0',
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
        foreach ($this->getFormBlockItems($form) as $custom_form_block) {
            $column_blocks = $custom_form_block->toArray();
            // get label header.
            $column_blocks = array_merge($column_blocks, [
                'label' => $this->getBlockLabelHeader(array_get($column_blocks, 'form_block_type')) . array_get($custom_form_block, 'target_table.table_view_name') ?? null,
                'custom_form_columns' => [],
            ]);

            // get form columns
            $custom_form_columns = $this->getFormColumns($custom_form_block);
            foreach ($custom_form_columns as $custom_form_column) {
                $custom_form_column_array = $custom_form_column->toArray();
                if (!isset($custom_form_column_array['column_no'])) {
                    $custom_form_column_array['column_no'] = 1;
                }
                $custom_form_column_array['required'] = boolval(array_get($custom_form_column, 'required')) || boolval(array_get($custom_form_column, 'custom_column.required'));

                // get column view name
                switch (array_get($custom_form_column, 'form_column_type')) {
                    case FormColumnType::COLUMN:
                        $custom_column = array_get($custom_form_column, 'custom_column');
                        if (!isset($custom_column)) {
                            // get from form_column_target_id
                            $custom_column = CustomColumn::getEloquent(array_get($custom_form_column, 'form_column_target_id'));
                        }
                        if (!isset($custom_column)) {
                            break 2; // break switch and Loop.
                        }
                        $column_view_name = $custom_column->column_view_name;
                        break;
                    default:
                        // get column name
                        $column_form_column_name = FormColumnType::getOption(['id' => array_get($custom_form_column, 'form_column_target_id')])['column_name'] ?? null;
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
                
                // add name for toggle(it's OK random string)
                $custom_form_column_array['toggle_key_name'] = make_uuid();

                array_push($column_blocks['custom_form_columns'], $custom_form_column_array);
            }
            array_push($custom_form_blocks, $column_blocks);
        }

        // if $custom_form_blocks not have $block->form_block_type = default, set as default
        if (!collect($custom_form_blocks)->first(function ($custom_form_block) {
            return array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT;
        })) {
            $block = new CustomFormBlock;
            $block->id = null;
            $block->form_block_type = FormBlockType::DEFAULT;
            $block->form_block_target_table_id = $this->custom_table->id;
            $block->label = $this->getBlockLabelHeader(FormBlockType::DEFAULT) . $this->custom_table->table_view_name;
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
                    'hasmany_type' => null
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
            $custom_columns = CustomTable::getEloquent($form_block_target_table_id)->custom_columns->toArray();
            
            // if form block type is 1:n or n:n, get parent tables columns too. use parent_table_id.
            if (in_array(array_get($custom_form_block, 'form_block_type'), [FormBlockType::ONE_TO_MANY, FormBlockType::MANY_TO_MANY])) {
                $custom_columns = array_merge(
                    CustomTable::getEloquent($parent_table_id)->custom_columns->toArray(),
                    $custom_columns
                );
            }
            // else, get form_block_target_table_id as parent_table_id
            else {
                $parent_table_id = $form_block_target_table_id;
            }
            
            foreach ($custom_columns as $custom_column) {
                // if column_type is not select_table, return []
                if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
                    continue;
                }

                // if not have array_get($custom_column, 'options.select_target_table'), conitnue
                $custom_column_eloquent = CustomColumn::getEloquent(array_get($custom_column, 'id'));
                if(!isset($custom_column_eloquent)){
                    continue;
                }

                $custom_column_eloquent->select_target_table;
                if(!isset($target_table)){
                    continue;
                }
                $target_table = $custom_column_eloquent->select_target_table;
                if(!isset($target_table)){
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
     * Get form blocks.
     * If first request, set from database.
     * If not (ex. validation error), set from request value
     *
     * @return void
     */
    protected function getFormBlockItems($form)
    {
        // get custom_form_blocks from request
        $req_custom_form_blocks = old('custom_form_blocks');
        if (!isset($req_custom_form_blocks)
        ) {
            return $form->custom_form_blocks;
        }

        return collect($req_custom_form_blocks)->map(function ($custom_form_block, $id) {
            $custom_form_block['id'] = $id;
            $custom_form_block['available'] = $custom_form_block['available']?? 0;
            $custom_form_block['target_table'] = CustomTable::getEloquent($custom_form_block['form_block_target_table_id']);
            return collect($custom_form_block);
        });
    }

    /**
     * Get form columns from $custom_form_block.
     * If first request, set from database.
     * If not (ex. validation error), set from request value
     *
     * @return void
     */
    protected function getFormColumns($custom_form_block)
    {
        // get custom_form_blocks from request
        $req_custom_form_blocks = old('custom_form_blocks');
        if (!isset($req_custom_form_blocks)
            || !isset($req_custom_form_blocks[$custom_form_block['id']])
            || !isset($req_custom_form_blocks[$custom_form_block['id']]['custom_form_columns'])
        ) {
            return array_get($custom_form_block, 'custom_form_columns')?? [];
        }

        $custom_form_columns = $req_custom_form_blocks[$custom_form_block['id']]['custom_form_columns'];
        return collect($custom_form_columns)->map(function ($custom_form_column, $id) {
            $custom_form_column['id'] = $id;
            return collect($custom_form_column);
        });
    }

    /**
     * get custom form column suggest list.
     */
    protected function setTableSuggests($form, $custom_form_block, &$suggests = [])
    {
        // if form_block_type is n:n, no get columns.
        if (array_get($custom_form_block, 'form_block_type') != FormBlockType::MANY_TO_MANY) {

            // get columns by form_block_target_table_id.
            $custom_columns = CustomColumn::where('custom_table_id', array_get($custom_form_block, 'form_block_target_table_id'))
                ->get()->toArray();
            $custom_form_columns = [];
            
            // set VIEW_COLUMN_SYSTEM_OPTIONS as header and footer
            $system_columns_header = SystemColumn::getOptions(['header' => true]) ?? [];
            $system_columns_footer = SystemColumn::getOptions(['footer' => true]) ?? [];

            $loops = [
                ['form_column_type' => FormColumnType::COLUMN , 'columns' => $custom_columns],
            ];

            // loop header, custom_columns, footer
            foreach ($loops as $loop) {
                // get array items
                $form_column_type = array_get($loop, 'form_column_type');
                $columns = array_get($loop, 'columns');
                // loop each column
                foreach ($columns as &$custom_column) {
                    $has_custom_forms = false;
                    // check $custom_form_block->custom_form_columns. if $custom_column has $this->custom_form_columns, add parameter has_custom_forms.
                    // if has_custom_forms is true, not show display default.
                    if (collect(array_get($custom_form_block, 'custom_form_columns', []))->first(function ($custom_form_column) use ($custom_column, $form_column_type) {
                        if (boolval(array_get($custom_form_column, 'delete_flg'))) {
                            return false;
                        }
                        return array_get($custom_form_column, 'form_column_type') == $form_column_type && array_get($custom_form_column, 'form_column_target_id') == array_get($custom_column, 'id');
                    })) {
                        $has_custom_forms = true;
                    }

                    // re-set column
                    $custom_column = [
                        'column_name' => array_get($custom_column, 'column_name'),
                        'column_view_name' => array_get($custom_column, 'column_view_name'),
                        'column_type' => array_get($custom_column, 'column_type'),
                        'form_column_type' => $form_column_type,
                        'form_column_target_id' => array_get($custom_column, 'id'),
                        'has_custom_forms' => $has_custom_forms,
                        'required' => boolval(array_get($custom_column, 'required')),
                    ];

                    array_push($custom_form_columns, $custom_column);
                }
            }
        
            // add header name
            foreach ($custom_form_columns as &$custom_form_column) {
                $header_column_name = '[custom_form_columns]['
                .(isset($custom_form_column['id']) ? $custom_form_column['id'] : 'NEW__'.make_uuid())
                .']';
                $custom_form_column['header_column_name'] = $header_column_name;
                $custom_form_column['toggle_key_name'] = make_uuid();
            }

            array_push($suggests, [
                'label' => exmtrans('custom_form.suggest_column_label'),
                'custom_form_columns' => $custom_form_columns,
                'clone' => false,
                'form_column_type' => FormColumnType::COLUMN,
            ]);
        }

        // set free html
        $custom_form_columns  = [];
        foreach (FormColumnType::getOptions() as $id => $type) {
            $header_column_name = '[custom_form_columns][NEW__'.make_uuid().']';
            array_push($custom_form_columns, [
                'id' => null,
                'column_view_name' => exmtrans("custom_form.form_column_type_other_options.".array_get($type, 'column_name')),
                'form_column_type' => FormColumnType::OTHER,
                'required' => false,
                'form_column_target_id' => $id,
                'header_column_name' =>$header_column_name,
                'toggle_key_name' => make_uuid(),
            ]);
        }
        array_push($suggests, [
            'label' => exmtrans('custom_form.suggest_other_label'),
            'custom_form_columns' => $custom_form_columns,
            'clone' => true,
            'form_column_type' => FormColumnType::OTHER,
        ]);
    }

    /**
     * validate before update or store
     */
    protected function saveformValidate($request, $id = null)
    {
        $inputs = $request->input('custom_form_blocks');
        foreach ($inputs as $key => $value) {
            $columns = [];
            if (!isset($value['form_block_target_table_id'])) {
                continue;
            }
            if (!boolval(array_get($value, 'available'))) {
                continue;
            }
            if (array_get($value, 'form_block_type') == FormBlockType::MANY_TO_MANY) {
                continue;
            }
            // get column id for registration
            if (is_array(array_get($value, 'custom_form_columns'))) {
                foreach (array_get($value, 'custom_form_columns') as $column_key => $column_value) {
                    if (!isset($column_value['form_column_type']) || $column_value['form_column_type'] != FormColumnType::COLUMN) {
                        continue;
                    }
                    if (boolval(array_get($column_value, 'delete_flg'))) {
                        continue;
                    }
                    if (isset($column_value['form_column_target_id'])) {
                        $columns[] = array_get($column_value, 'form_column_target_id');
                    }
                }
            }
            $table_id = array_get($value, 'form_block_target_table_id');
            // check if required column not for registration exist
            if (CustomColumn::where('custom_table_id', $table_id)
                    ->required()->whereNotIn('id', $columns)->exists()) {
                return false;
            }
        }
        return true;
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
                $form = CustomForm::getEloquent($id);
            }
            $form->form_view_name = $request->input('form_view_name');
            $form->default_flg = $request->input('default_flg');
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

                    // if delete flg is true, delete and continue
                    if (boolval(array_get($column_value, 'delete_flg'))) {
                        if (!$new_column) {
                            CustomFormColumn::findOrFail($column_key)->delete();
                        }
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
    protected function getBlockLabelHeader($form_block_type)
    {
        switch ($form_block_type) {
            case FormBlockType::ONE_TO_MANY:
                return exmtrans('custom_form.table_one_to_many_label');
            case FormBlockType::MANY_TO_MANY:
                return exmtrans('custom_form.table_many_to_many_label');
        }
        return exmtrans('custom_form.table_default_label');
    }
}
