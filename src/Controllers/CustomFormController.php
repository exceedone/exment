<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Services\FormSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom Form Controller
 */
class CustomFormController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);
        
        $title = exmtrans("custom_form.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_form.description"), 'fa-keyboard-o');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_CUSTOM_FORM)) {
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
        $grid->setName('custom_form_priproties');
        $grid->model()->orderBy('order');
        $grid->setTitle(exmtrans("custom_form.priority.title"));
        $grid->setResource(admin_urls('formpriority', $this->custom_table->table_name));
        $grid->column('form_priority_text', exmtrans("custom_form.priority.form_priority_text"));
        $grid->column('form_view_name', exmtrans("custom_form.priority.form_view_name"));
        $grid->column('order', exmtrans("custom_form.priority.order"))->editable();

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
     * @param Request $request
     * @param string $tableKey
     * @param string|int|null $id
     * @return void
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
     * Edit
     *
     * @param Request $request
     * @param Content $content
     * @param string $tableKey
     * @param string|int|null $id
     * @return void|Response
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_CUSTOM_FORM)) {
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
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_CUSTOM_FORM)) {
            return;
        }

        $copy_id = $request->get('copy_id');

        $this->AdminContent($content);
        $this->createForm($content, null, $copy_id);
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
        $validator = $this->saveformValidate($request, $id);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
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
        $validator = $this->saveformValidate($request, $id);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        if ($this->saveform($request)) {
            admin_toastr(trans('admin.save_succeeded'));
            return redirect(admin_url("form/{$this->custom_table->table_name}"));
        }
        return null; //TODO
    }


    /**
     * Get relation select modal
     *
     * @param Request $request
     * @return void
     */
    public function relationFilterModal(Request $request)
    {
        $target_column_id = $request->get('target_column_id');
        $custom_column = CustomColumn::getEloquent($target_column_id);
        
        // get relation columns.
        $relationColumns = Linkage::getLinkages(null, $custom_column);

        // get selected value
        $selected_value = $request->get('relation_filter_target_column_id');

        return view('exment::custom-form.form-relation-filter-modal', [
            'columns' => $relationColumns,
            'target_column' => $custom_column,
            'selected_value' => $selected_value,
        ]);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomForm);
        $grid->setName('custom_forms');
        $grid->column('custom_table.table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('form_view_name', exmtrans("custom_form.form_view_name"))->sortable();
        $grid->column('default_flg', exmtrans("custom_form.default_flg"))->sortable()->display(function ($val) {
            return \Exment::getTrueMark($val);
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }
        
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('form', $this->custom_table));
            
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });
        
        $grid->disableExport();
        $grid->disableRowSelector();
        // $grid->disableCreateButton();
        $table_name = $this->custom_table->table_name;

        $grid->actions(function ($actions) use ($table_name) {
            $actions->disableView();
            // $actions->disableDelete();
            $linker = (new Linker)
                ->url(admin_urls('form', $table_name, "create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('custom_form.default_form_name')));
            $actions->prepend($linker);
        });
        
        // filter
        $grid->filter(function ($filter) {
            // Remove the default id filter
            $filter->disableIdFilter();

            // Add a column filter
            $filter->like('form_view_name', exmtrans("custom_form.form_view_name"));
            
            $filter->equal('default_flg', exmtrans("custom_form.default_flg"))->radio(\Exment::getYesNoAllOption());
        });

        return $grid;
    }
    
    /**
     *
     * Make a form
     *
     * @return Form
     */
    protected function createForm($content, $id = null, $copy_id = null)
    {
        // get form
        $form = CustomForm::getEloquent($id);
        if (is_null($form)) {
            if (isset($copy_id)) {
                $form = CustomForm::getEloquent($copy_id);
                $form->form_view_name = '';
                $form->default_flg = '0';
            } else {
                $form = new CustomForm;
            }
        }

        // get form block list
        $custom_form_blocks = $this->getFormBlocks($form);

        // get exment version
        $ver = \Exment::getExmentCurrentVersion();
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
            'change_page_menu' => (new Tools\CustomTableMenuButton('form', $this->custom_table)),
            'relationFilterUrl' => admin_urls('form', $this->custom_table->table_name, 'relationFilterModal'),
            'relationFilterHelp' => $this->getRelationFilterHelp(),
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
            $block_item = FormSetting\FormBlock\BlockBase::make($custom_form_block, $this->custom_table);
            $custom_form_block_array = $block_item->getItemsForDisplay();
            
            // get form columns
            $custom_form_columns = $block_item->getFormColumns();
            foreach ($custom_form_columns as $custom_form_column) {
                $column_item = FormSetting\FormColumn\ColumnBase::make($custom_form_column);
                $custom_form_block_array['custom_form_columns'][] = $column_item->getItemsForDisplay();
            }
            $custom_form_blocks[] = $custom_form_block_array;
        }

        // if $custom_form_blocks not have $block->form_block_type = default, set as default
        if (!collect($custom_form_blocks)->first(function ($custom_form_block) {
            return array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT;
        })) {
            $custom_form_blocks[] = FormSetting\FormBlock\DefaultBlock::getDefaultBlock($this->custom_table)->getItemsForDisplay();
        }

        // check relation define.if not exists in custom_form_blocks, add define.
        foreach ($relations as $relation) {
            if (!collect($custom_form_blocks)->first(function ($custom_form_block) use ($relation) {
                return array_get($custom_form_block, 'form_block_type') == $relation->relation_type
                            && array_get($custom_form_block, 'form_block_target_table_id') == $relation->child_custom_table_id;
            })) {
                $custom_form_blocks[] = FormSetting\FormBlock\RelationBase::getDefaultBlock($this->custom_table, $relation)->getItemsForDisplay();
            }
        }

        $parent_table_id = null;
        foreach ($custom_form_blocks as &$custom_form_block) {
            // ///// Set changedata selection select list
            // $select_table_columns = [];
            // // get custom columns
            // $form_block_target_table_id = array_get($custom_form_block, 'form_block_target_table_id');
            // $custom_columns = CustomTable::getEloquent($form_block_target_table_id)->custom_columns_cache->toArray();
            
            // // if form block type is 1:n or n:n, get parent tables columns too. use parent_table_id.
            // if (in_array(array_get($custom_form_block, 'form_block_type'), [FormBlockType::ONE_TO_MANY, FormBlockType::MANY_TO_MANY])) {
            //     $custom_columns = array_merge(
            //         CustomTable::getEloquent($parent_table_id)->custom_columns_cache->toArray(),
            //         $custom_columns
            //     );
            // }
            // // else, get form_block_target_table_id as parent_table_id
            // else {
            //     $parent_table_id = $form_block_target_table_id;
            // }
            
            // foreach ($custom_columns as $custom_column) {
            //     // if column_type is not select_table, return []
            //     if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
            //         continue;
            //     }

            //     // if not have array_get($custom_column, 'options.select_target_table'), conitnue
            //     $custom_column_eloquent = CustomColumn::getEloquent(array_get($custom_column, 'id'));
            //     if (!isset($custom_column_eloquent)) {
            //         continue;
            //     }

            //     $target_table = $custom_column_eloquent->select_target_table;
            //     if (!isset($target_table)) {
            //         continue;
            //     }

            //     // get custom table
            //     $custom_table_eloquent = CustomTable::getEloquent($custom_column_eloquent->custom_table_id);
            //     // set table name if not $form_block_target_table_id and custom_table_eloquent's id
            //     if (!isMatchString($custom_table_eloquent->id, $form_block_target_table_id)) {
            //         $select_table_column_name = sprintf('%s:%s', $custom_table_eloquent->table_view_name, array_get($custom_column, 'column_view_name'));
            //     } else {
            //         $select_table_column_name = array_get($custom_column, 'column_view_name');
            //     }
            //     // get select_table, user, organization columns
            //     $select_table_columns[array_get($custom_column, 'id')] = $select_table_column_name;
            // }
            // $custom_form_block['select_table_columns'] = collect($select_table_columns)->toJson();

        }

        return $custom_form_blocks;
    }

    /**
     * Get form blocks.
     * If first request, set from database.
     * If not (ex. validation error), set from request value
     *
     * @return array|\Illuminate\Support\Collection
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
            $custom_form_block['available'] = $custom_form_block['available'] ?? 0;
            $custom_form_block['target_table'] = CustomTable::getEloquent($custom_form_block['form_block_target_table_id']);
            return collect($custom_form_block);
        });
    }


    /**
     * validate before update or store
     */
    protected function saveformValidate($request, $id = null)
    {
        //not required check, confirm on display.
        // $inputs = $request->input('custom_form_blocks');
        // foreach ($inputs as $key => $value) {
        //     $columns = [];
        //     if (!isset($value['form_block_target_table_id'])) {
        //         continue;
        //     }
        //     if (!boolval(array_get($value, 'available'))) {
        //         continue;
        //     }
        //     if (array_get($value, 'form_block_type') == FormBlockType::MANY_TO_MANY) {
        //         continue;
        //     }
        //     // get column id for registration
        //     if (is_array(array_get($value, 'custom_form_columns'))) {
        //         foreach (array_get($value, 'custom_form_columns') as $column_key => $column_value) {
        //             if (!isset($column_value['form_column_type']) || $column_value['form_column_type'] != FormColumnType::COLUMN) {
        //                 continue;
        //             }
        //             if (boolval(array_get($column_value, 'delete_flg'))) {
        //                 continue;
        //             }
        //             if (isset($column_value['form_column_target_id'])) {
        //                 $columns[] = array_get($column_value, 'form_column_target_id');
        //             }
        //         }
        //     }
        //     $table_id = array_get($value, 'form_block_target_table_id');
        //     // check if required column not for registration exist
        //     // if (CustomColumn::where('custom_table_id', $table_id)
        //     //         ->required()->whereNotIn('id', $columns)->exists()) {
        //     //     return false;
        //     // }
        // }

        return \Validator::make($request->all(), [
            'custom_form_blocks.*.custom_form_columns.*.options.image' => ['nullable', new \Exceedone\Exment\Validator\ImageRule],
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
            $is_new = false;

            // create form (if new form) --------------------------------------------------
            if (!isset($id)) {
                $form = new CustomForm;
                $form->custom_table_id = $this->custom_table->id;
                $is_new = true;
            } else {
                $form = CustomForm::getEloquent($id);
            }
            $form->form_view_name = $request->input('form_view_name');
            $form->default_flg = $request->input('default_flg');
            $form->saveOrFail();
            $id = $form->id;


            $new_columns = [];
            $deletes = [];
            foreach ($inputs as $key => $value) {
                // create blocks --------------------------------------------------
                // if key is "NEW_", create new block
                if (starts_with($key, 'NEW_') || $is_new) {
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
                    $new_column = starts_with($column_key, 'NEW_') || $is_new;

                    // if delete flg is true, delete and continue
                    if (boolval(array_get($column_value, 'delete_flg'))) {
                        if (!$new_column) {
                            CustomFormColumn::findOrFail($column_key)->delete();
                            $deletes[] = $column_key;
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

                    if($new_column){
                        $new_columns[$column_key] = $column->id;
                    }
                }
            }

            // set file info
            $this->saveAndStoreImage($new_columns);
            // delete file info
            $this->deleteImage($deletes);

            DB::commit();
            return true;
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    // create form because we need for delete
    protected function form($id = null)
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

    protected function getRelationFilterHelp()
    {
        return exmtrans('custom_form.help.relation_filter') . '<br/>' . exmtrans('common.help.more_help_here', getManualUrl('form#relation_filter_manual'));
    }


    /**
     * getImageUrl
     *
     * @return string|null
     */
    protected function getImageUrl($custom_form_column) : ?string
    {
        if(!isMatchString(array_get($custom_form_column, 'form_column_type'), FormColumnType::OTHER)
            || !isMatchString(array_get($custom_form_column, 'form_column_target_id'), 5)){
                return null;
        }
        $file = ExmentFile::getFileFromFormColumn(array_get($custom_form_column, 'id'));
        if(!$file){
            return null;
        }
        return ExmentFile::getUrl($file);
    }

    
    /**
     * Save attachment and get column name
     *
     * @return void
     */
    protected function saveAndStoreImage(array $new_columns)
    {
        $files = request()->files->all();
        foreach(array_get($files, 'custom_form_blocks', []) as $block_id => $file_blocks){
            foreach(array_get($file_blocks, 'custom_form_columns', []) as $column_id => $file_options){
                $image = array_get($file_options, 'options.image');
                if(!$image){
                    continue;
                }

                // get custom form column's id 
                $column_id = array_key_exists($column_id, $new_columns) ? $new_columns[$column_id] : $column_id;
                $file = ExmentFile::storeAs(FileType::CUSTOM_FORM_COLUMN, $image, 'custom_form', $image->getClientOriginalName());
                $file->custom_form_column_id = $column_id;
                $file->save();
            }
        }
    }


    /**
     * delete attachments
     *
     * @return void
     */
    protected function deleteImage($deletes)
    {
        collect($deletes)->map(function($delete){
            return ExmentFile::getFileFromFormColumn($delete);
        })->filter()->each(function($file){
            ExmentFile::deleteFileInfo($file);
        });
    }
}
