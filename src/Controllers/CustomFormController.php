<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormPriority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ShowGridType;
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
     * @param Request $request
     * @param Content $content
     * @return Content|void
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_CUSTOM_FORM)) {
            return;
        }
        $this->AdminContent($content);
        $content->body($this->grid());

        // form priorities
        if ($this->custom_table->hasPermission(Permission::EDIT_CUSTOM_FORM)) {
            $content->row($this->setFormPriorities());
        }

        // public form
        if ($this->enablePublicForm()) {
            $content->row($this->setFormPublics());
        }

        return $content;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function setFormPriorities()
    {
        $grid = new Grid(new CustomFormPriority());
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
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function setFormPublics()
    {
        $grid = new Grid(new PublicForm());
        $grid->setName('public_forms');
        $grid->setTitle(exmtrans("custom_form.public_form.title"));
        $grid->setResource(admin_urls('formpublic', $this->custom_table->table_name));

        $grid->column('form_view_name', exmtrans("custom_form_public.custom_form_id"));
        $grid->column('public_form_view_name', exmtrans("custom_form_public.public_form_view_name"));
        $grid->column('active_flg', exmtrans("plugin.active_flg"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);
        $grid->column('validity_period', exmtrans("custom_form_public.validity_period"))
            ->display(function ($value, $column, $model) {
                if (!$model) {
                    return null;
                }
                $start = $model->getOption('validity_period_start');
                $end = $model->getOption('validity_period_end');
                if (!$start && !$end) {
                    return null;
                }
                return sprintf("%s ～ %s", $start, $end);
            });

        if (isset($this->custom_table)) {
            $grid->model()
                ->select(['public_forms.*', 'custom_forms.form_view_name'])
                ->join('custom_forms', 'custom_forms.id', '=', 'public_forms.custom_form_id')
                ->where('custom_forms.custom_table_id', $this->custom_table->id);
        }

        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });

            $tools->append(view('exment::tools.button', [
                'href' => admin_urls_query('formpublic', $this->custom_table->table_name, 'create', ['template' => 1]),
                'icon' => 'fa-plus',
                'btn_class' => 'btn-success',
                'label' => exmtrans('custom_form_public.create_template'),
            ]));
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
     * @param $tableKey
     * @param $id
     * @return Content|void
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM)) {
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
     * Showing preview
     *
     * @param Request $request
     * @return Content
     */
    public function preview(Request $request)
    {
        // get requested form
        $requestItem = $this->getModelFromRequest($request, null, false);
        $custom_form = $requestItem['custom_form'];

        // loop form block and column
        foreach ($requestItem['custom_form_blocks'] ?? [] as $block) {
            $custom_form_block = $block['custom_form_block'] ?? null;
            if (!$custom_form_block) {
                continue;
            }

            foreach ($block['custom_form_columns'] ?? [] as $custom_form_column) {
                $custom_form_block->custom_form_columns->add($custom_form_column);
            }
            $custom_form->custom_form_blocks->add($custom_form_block);
        }

        return $this->getPreviewContent($request, $custom_form);
    }

    /**
     * Preview error. (If called as GET request)
     *
     * @param Request $request
     * @return Content
     */
    public function previewError(Request $request)
    {
        $content = new Content();
        $content->withError(exmtrans('common.error'), exmtrans('common.message.preview_error'));
        return $content;
    }

    /**
     * Showing preview by id
     *
     * @param Request $request
     * @param string $tableKey
     * @param string $suuid
     * @return Content
     */
    public function previewBySuuid(Request $request, string $tableKey, string $suuid)
    {
        $custom_form = CustomForm::findBySuuid($suuid);
        return $this->getPreviewContent($request, $custom_form);
    }


    /**
     * @param Request $request
     * @param CustomForm $custom_form
     * @return Content
     */
    protected function getPreviewContent(Request $request, CustomForm $custom_form)
    {
        $form_item = $custom_form->form_item;
        $form = $form_item->disableToolsButton()->disableSavingButton()->form();

        $content = new Content();
        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description, $this->custom_table->getOption('icon'));
        $this->AdminContent($content);
        $content->row($form);

        admin_info(exmtrans('common.preview'), exmtrans('common.message.preview'));

        return $content;
    }

    /**
     * Create interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|void
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM)) {
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
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|null
     * @throws \Exception
     */
    public function update(Request $request, $tableKey, $id)
    {
        $validator = $this->saveformValidate($request, $id);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        if (!is_null($custom_form = $this->saveform($request, $id))) {
            admin_toastr(trans('admin.save_succeeded'));

            if ($request->get('after-save') == 1) {
                return redirect(admin_url("form/{$this->custom_table->table_name}/{$id}/edit?after-save=1"));
            }
            return redirect(admin_url("form/{$this->custom_table->table_name}"));
        }
        return null; //TODO
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|null
     * @throws \Exception
     */
    public function store(Request $request)
    {
        $validator = $this->saveformValidate($request);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        if (!is_null($custom_form = $this->saveform($request))) {
            admin_toastr(trans('admin.save_succeeded'));

            if ($request->get('after-save') == 1) {
                return redirect(admin_url("form/{$this->custom_table->table_name}/{$custom_form->id}/edit?after-save=1"));
            }
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
        $grid = new Grid(new CustomForm());
        $grid->setName('custom_forms');
        $grid->column('custom_table.table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('form_view_name', exmtrans("custom_form.form_view_name"))->sortable();
        $grid->column('default_flg', exmtrans("custom_form.default_flg"))->sortable()->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }

        $custom_table = $this->custom_table;
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('form', $this->custom_table));
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });

        $grid->disableExport();
        $grid->disableRowSelector();

        if (!$custom_table->hasPermission(Permission::EDIT_CUSTOM_FORM)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function ($actions) use ($custom_table) {
            $actions->disableView();

            // append preview
            $linker = (new Linker())
                    ->url(admin_urls('form', $custom_table->table_name, "preview", $actions->row->suuid))
                    ->icon('fa-check-circle')
                    ->linkattributes(['target' => '_blank'])
                    ->tooltip(exmtrans('common.preview'));
            $actions->prepend($linker);

            // checking edit permission
            if ($custom_table->hasPermission(Permission::EDIT_CUSTOM_FORM)) {
                $linker = (new Linker())
                    ->url(admin_urls('form', $custom_table->table_name, "create?copy_id={$actions->row->id}"))
                    ->icon('fa-copy')
                    ->tooltip(exmtrans('common.copy_item', exmtrans('custom_form.default_form_name')));
                $actions->prepend($linker);
            } else {
                $actions->disableDelete();
                $actions->disableEdit();
            }
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
     * Make a form
     *
     * @param $content
     * @param $id
     * @param $copy_id
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
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
                $form = new CustomForm();
            }
        }
        $form->append(['show_grid_type', 'form_label_type']);

        // get form block list
        $custom_form_block_items = $this->getFormBlocks($form);
        $custom_form_blocks = collect($custom_form_block_items)->map(function ($custom_form_block_item) {
            return $custom_form_block_item->getItemsForDisplay();
        });


        // create endpoint
        $formroot = admin_url("form/{$this->custom_table->table_name}");
        $endpoint = $formroot.(isset($id) ? "/{$id}" : "");
        $content->row(view('exment::custom-form.form', [
            'formroot' => $formroot,
            'endpoint'=> $endpoint,
            'custom_form_blocks' => $custom_form_blocks,
            'editmode' => isset($id),
            'headerBox' => $this->getHeaderBox($form, $formroot),
            'after_save' => old('after-save', request()->get('after-save')),
        ]));
    }

    /**
     * Get header box ex. view name, label, default flg....
     *
     * @param CustomForm|null $custom_form
     * @param string $formroot
     * @return Box
     */
    protected function getHeaderBox(?CustomForm $custom_form, string $formroot)
    {
        ///// set default setting
        $form = new WidgetForm($custom_form);
        $form->disableSubmit()->disableReset()->onlyRenderFields();

        $manualUrl = getManualUrl('additional_php_ini');
        $form->description(sprintf(exmtrans("custom_form.message.max_input_warning"), $manualUrl))->escape(false);

        $form->text('form_view_name', exmtrans('custom_form.form_view_name'))
            ->required();

        $form->switchbool('default_flg', exmtrans('custom_form.default_flg'))
            ->default(false);


        $form->radio('show_grid_type', exmtrans('custom_form.show_grid_type'))
            ->help(exmtrans('custom_form.help.show_grid_type'))
            ->default(ShowGridType::GRID)
            ->options(ShowGridType::transArray('custom_form.show_grid_type_options'));

        $form->radio('form_label_type', exmtrans('custom_form.form_label_type'))
            ->help(exmtrans('custom_form.help.form_label_type'))
            ->default(FormLabelType::HORIZONTAL)
            ->options(FormLabelType::transArrayFilter('custom_form.form_label_type_options', FormLabelType::getFormLabelTypes()));

        $box = new Box(exmtrans('custom_form.header_basic_setting'), $form);
        $box->tools(view('exment::tools.button', [
            'href' => 'javascript:void(0);',
            'label' => exmtrans('common.preview'),
            'icon' => 'fa-eye',
            'btn_class' => 'preview-custom_form btn-warning',
        ])->render());
        $box->tools(view('exment::tools.button', [
            'href' => $formroot,
            'label' => trans('admin.list'),
            'icon' => 'fa-list',
            'btn_class' => 'btn-default',
        ])->render());
        $box->tools((new Tools\CustomTableMenuButton('form', $this->custom_table))->render());

        return $box;
    }


    protected function getFormBlocks($form)
    {
        // Loop using CustomFormBlocks
        $custom_form_block_items = [];
        foreach ($this->getFormBlockItems($form) as $custom_form_block) {
            $block_item = FormSetting\FormBlock\BlockBase::make($custom_form_block, $this->custom_table);

            // get form column items
            $custom_form_column_items = collect($block_item->getFormColumns())->map(function ($custom_form_column) {
                return FormSetting\FormColumn\ColumnBase::make($custom_form_column);
            });
            $block_item->setCustomFormColumnItems($custom_form_column_items);

            $custom_form_block_items[] = $block_item;
        }

        // if $custom_form_blocks not have $block->form_block_type = default, set as default
        if (!collect($custom_form_block_items)->first(function ($custom_form_block_item) {
            return $custom_form_block_item->getCustomFormBlockType() == FormBlockType::DEFAULT;
        })) {
            $custom_form_block_items[] = FormSetting\FormBlock\DefaultBlock::getDefaultBlock($this->custom_table);
        }

        // Create Blocks as "table-self", "one-to-many tables", "many-to-many tables".
        // "table-self", "one-to-many tables" have form-columns.
        // "many-to-many tables" have only use or not use relation.
        // define relation tables
        $relations = $this->custom_table->custom_relations;

        // check relation define.if not exists in custom_form_blocks, add define.
        foreach ($relations as $relation) {
            if (!collect($custom_form_block_items)->first(function ($custom_form_block_item) use ($relation) {
                return $custom_form_block_item->getCustomFormBlockType() == $relation->relation_type
                            && array_get($custom_form_block_item->getCustomFormBlock(), 'form_block_target_table_id') == $relation->child_custom_table_id;
            })) {
                $custom_form_block_items[] = FormSetting\FormBlock\RelationBase::getDefaultBlock($this->custom_table, $relation);
            }
        }

        return $custom_form_block_items;
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

        return collect($req_custom_form_blocks)->map(function ($req_custom_form_block, $key) {
            $custom_form_block = new CustomFormBlock($req_custom_form_block);
            $custom_form_block->request_key = $key;
            $custom_form_block->available = $req_custom_form_block['available'] ?? 0;
            $custom_form_block->target_table = CustomTable::getEloquent($req_custom_form_block['form_block_target_table_id']);
            return $custom_form_block;
        });
    }


    /**
     * validate before update or store
     */
    protected function saveformValidate($request, $id = null)
    {
        //not required check, confirm on display.
        // $inputs = $request->get('custom_form_blocks');
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
            'custom_form_blocks.*.custom_form_columns.*.options.image' => ['nullable', new \Exceedone\Exment\Validator\ImageRule()],
        ]);
    }

    /**
     * Store form data
     */
    protected function saveform(Request $request, $id = null)
    {
        $saveData = $this->getModelFromRequest($request, $id);
        DB::beginTransaction();
        try {
            $custom_form = $saveData['custom_form'];
            $custom_form->saveOrFail();
            $id = $custom_form->id;

            $new_columns = [];
            $deletes = [];
            foreach ($saveData['custom_form_blocks'] ?? [] as $key => $block) {
                $custom_form_block = $block['custom_form_block'];
                $custom_form_block->custom_form_id = $id;
                $custom_form_block->saveOrFail();

                // create columns --------------------------------------------------
                foreach ($block['custom_form_columns'] ?? [] as $custom_form_column) {
                    $custom_form_column->custom_form_block_id = $custom_form_block->id;
                    $custom_form_column->saveOrFail();
                }
                // delete columns --------------------------------------------------
                foreach ($block['delete_custom_form_columns'] ?? [] as $custom_form_column) {
                    $custom_form_column->delete();
                }
            }

            // set file info
            $this->saveAndStoreImage($saveData['new_columns']);
            // delete file info
            $this->deleteImage($saveData['deletes']);

            DB::commit();
            return $custom_form;
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * get Model from request
     *
     * @param Request $request
     * @param string|int|null $id
     * @return array [
     *     'custom_form' => $custom_form,
     *     'custom_form_blocks' => [
     *         [
     *             'custom_form_block' => $custom_form_block
     *             'custom_form_columns' => $custom_form_columns,
     *             'delete_custom_form_columns' => $custom_form_columns,
     *         ],
     *         ...
     *     ],
     *     'new_columns' => [(column_ids)],
     *     'deletes' => [(column_ids)],
     * ]
     */
    protected function getModelFromRequest(Request $request, $id = null, $isPrepareOptions = true): array
    {
        $result = [
            'custom_form_blocks' => [],
        ];
        $inputs = $request->get('custom_form_blocks');
        $is_new = false;

        // create form (if new form) --------------------------------------------------
        if (!isset($id)) {
            $form = new CustomForm();
            $form->custom_table_id = $this->custom_table->id;
            $is_new = true;
        } else {
            $form = CustomForm::getEloquent($id);
        }
        $form->form_view_name = $request->get('form_view_name');
        $form->default_flg = $request->get('default_flg');
        $form->form_label_type = $request->get('form_label_type', FormLabelType::HORIZONTAL);
        $form->show_grid_type = $request->get('show_grid_type', ShowGridType::GRID);

        $new_columns = [];
        $deletes = [];
        foreach ($inputs as $key => $value) {
            $result_block = [];

            // create blocks --------------------------------------------------
            // if key is "NEW_", create new block
            if (starts_with($key, 'NEW_') || $is_new) {
                $block = new CustomFormBlock();
                $block->custom_form_id = $id;
                $block->form_block_type = array_get($value, 'form_block_type');
                $block->form_block_target_table_id = array_get($value, 'form_block_target_table_id');
            } else {
                $block = CustomFormBlock::findOrFail($key);
            }
            $block->available = array_get($value, 'available') ?? 0;
            $block->form_block_view_name = array_get($value, 'form_block_view_name');
            $block->options = array_get($value, 'options', []);

            // create columns --------------------------------------------------
            $order = 1;

            // set and calc row_no and column_no
            $before_row_no = 0;
            $before_column_no = 0;
            $real_before_row_no = 0;
            $real_before_column_no = 0;
            foreach (array_get($value, 'custom_form_columns', []) as $column_key => $column_value) {
                if (!isset($column_value['form_column_type'])) {
                    continue;
                }
                // if key is "NEW_", create new column
                $new_column = starts_with($column_key, 'NEW_') || $is_new;

                // if delete flg is true, delete and continue
                if (boolval(array_get($column_value, 'delete_flg'))) {
                    if (!$new_column) {
                        $result_block['delete_custom_form_columns'][] = CustomFormColumn::find($column_key);
                        $deletes[] = $column_key;
                    }
                    continue;
                } elseif ($new_column) {
                    $column = new CustomFormColumn();
                    $column->custom_form_block_id = $block->id;
                    $column->form_column_type = array_get($column_value, 'form_column_type');
                    if (is_null(array_get($column_value, 'form_column_target_id'))) {
                        continue;
                    }
                    $column->form_column_target_id = array_get($column_value, 'form_column_target_id');
                } else {
                    $column = CustomFormColumn::findOrFail($column_key);
                }

                $column_item = FormSetting\FormColumn\ColumnBase::make($column);

                // if change row_no and calc_no, increment no's.
                if ($real_before_row_no != array_get($column_value, 'row_no', 1)) {
                    $before_row_no++;
                    $before_column_no = 0;
                }
                if ($real_before_column_no != array_get($column_value, 'column_no', 1)) {
                    $before_column_no++;
                }

                // set real before row and column no
                $real_before_row_no = array_get($column_value, 'row_no', 1);
                $real_before_column_no = array_get($column_value, 'column_no', 1);

                $column->row_no = $before_row_no;
                $column->column_no = $before_column_no;
                $column->width = array_get($column_value, 'width', 1);

                $form_options = jsonToArray(array_get($column_value, 'options', "[]"));
                if ($isPrepareOptions) {
                    $column->options = $column_item->prepareSavingOptions($form_options);
                }
                // if preview, options set directrly.
                else {
                    $column->options = $form_options;
                }
                $column->order = $order++;

                $result_block['custom_form_columns'][] = $column;

                if ($new_column) {
                    // set new column info, after gertting id.
                    $new_columns[$column_key] = $column;
                }
            }

            $result_block['custom_form_block'] = $block;
            $result['custom_form_blocks'][] = $result_block;
        }

        $result['custom_form'] = $form;
        $result['new_columns'] = $new_columns;
        $result['deletes'] = $deletes;

        return $result;
    }


    // create form because we need for delete
    protected function form($id = null)
    {
        return Admin::form(CustomForm::class, function (Form $form) {
        });
    }

    /**
     * Get setting modal
     *
     * @param Request $request
     * @return Response
     */
    public function settingModal(Request $request)
    {
        $column_item = FormSetting\FormColumn\ColumnBase::makeByParams(
            $request->get('form_column_type'),
            $request->get('form_column_target_id'),
            $request->get('header_column_name')
        );

        $block_item = FormSetting\FormBlock\BlockBase::makeByParams(
            $request->get('form_block_type'),
            $request->get('form_block_target_table_id')
        );

        $form = $column_item->getSettingModalForm($block_item, $request->get('options', []));
        $form->disableReset();
        $form->disableSubmit();
        $form->setWidth(9, 2);
        $form->hidden('widgetmodal_uuid')->default($request->get('widgetmodal_uuid'));

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => trans('admin.setting'),
            'modalSize' => 'modal-xl',
            'submitlabel' => trans('admin.setting'),
            'modalClass' => 'modal-customform',
            'preventSubmit' => true,
            'showReset' => true,
        ]);
    }

    /**
     * Save attachment and get column name
     *
     * @return void
     */
    protected function saveAndStoreImage(array $new_columns)
    {
        $files = request()->files->all();
        foreach (array_get($files, 'custom_form_blocks', []) as $block_id => $file_blocks) {
            foreach (array_get($file_blocks, 'custom_form_columns', []) as $column_id => $file_options) {
                $image = array_get($file_options, 'options.image');
                if (!$image) {
                    continue;
                }

                // get custom form column's id
                if (array_key_exists($column_id, $new_columns)) {
                    $column_id = $new_columns[$column_id]->id ?? null;
                }
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
        collect($deletes)->map(function ($delete) {
            return ExmentFile::getFileFromFormColumn($delete);
        })->filter()->each(function ($file) {
            ExmentFile::deleteFileInfo($file);
        });
    }


    protected function enablePublicForm(): bool
    {
        if (!System::publicform_available()) {
            return false;
        }
        if (!$this->custom_table->hasPermission(Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return false;
        }
        if (boolval($this->custom_table->getOption('one_record_flg'))) {
            return false;
        }
        if (in_array($this->custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
            return false;
        }
        return true;
    }
}
