<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\Calc\CalcService;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\TextAlignType;
use Exceedone\Exment\Enums\EditableUserInfoType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Validator;
use Illuminate\Validation\Rule;

class CustomColumnController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_column.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_column.description"), 'fa-list');
    }

    /**
     * Index interface.
     *
     * @return Content|void
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        return parent::index($request, $content);
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
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomColumn::class, $id, 'column')) {
            return;
        }
        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @return Content|void
     */
    public function create(Request $request, Content $content)
    {
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
        $grid = new Grid(new CustomColumn());
        $grid->column('column_name', exmtrans("custom_column.column_name"))->sortable();
        $grid->column('column_view_name', exmtrans("custom_column.column_view_name"))->sortable();
        $grid->column('column_type', exmtrans("custom_column.column_type"))->sortable()->display(function ($val) {
            return array_get(ColumnType::transArray("custom_column.column_type_options"), $val);
        });
        $grid->column('required', exmtrans("common.required"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);
        $grid->column('index_enabled', exmtrans("custom_column.options.index_enabled"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);
        $grid->column('options->freeword_search', exmtrans("custom_column.options.freeword_search"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);
        $grid->column('unique', exmtrans("custom_column.options.unique"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);
        $grid->column('order', exmtrans("custom_column.order"))->sortable()->editable();

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }

        //  $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if ($actions->row->disabled_delete) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('column', $this->custom_table));
        });

        // filter
        $grid->filter(function ($filter) {
            // Remove the default id filter
            $filter->disableIdFilter();
            // Add a column filter
            $filter->like('column_name', exmtrans("custom_column.column_name"));
            $filter->like('column_view_name', exmtrans("custom_column.column_view_name"));
            $filter->equal('column_type', exmtrans("custom_column.column_type"))->select(ColumnType::transArray("custom_column.column_type_options"));

            $keys = ['required' => 'common', 'index_enabled' => 'custom_column.options', 'freeword_search' => 'custom_column.options', 'unique' => 'custom_column.options'];
            foreach ($keys as $key => $label) {
                $filter->exmwhere(function ($query, $input) use ($key) {
                    if (is_nullorempty($input)) {
                        return;
                    }
                    if (isMatchString($input, 0)) {
                        $query->where(function ($query) use ($key) {
                            $query->whereIn("options->$key", [0, '0'])
                                ->orWhereNull("options->$key");
                        });
                    } else {
                        $query->whereIn("options->$key", [1, '1']);
                    }
                }, exmtrans("$label.$key"))->radio(\Exment::getYesNoAllOption());
            }
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @return Form
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomColumn());
        $request = request();

        // get custom_item for option
        $custom_column = CustomColumn::getEloquent($id);

        if (isset($custom_column)) {
            $column_type = $custom_column->column_type;
            $column_item = $custom_column->column_item;
        } elseif (!is_nullorempty($request->get('column_type'))) {
            $column_type = $request->get('column_type');
            $column_item = $this->getCustomItem(request(), $id, $column_type);
        } elseif (!is_nullorempty($request->old('column_type'))) {
            $column_type = $request->old('column_type');
            $column_item = $this->getCustomItem(request(), $id, $column_type);
        } else {
            $column_type = null;
            $column_item = null;
        }

        $form->internal('custom_table_id')->default($this->custom_table->id);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table"))->default($this->custom_table->table_view_name);

        if (!isset($id)) {
            $classname = CustomColumn::class;
            $form->text('column_name', exmtrans("custom_column.column_name"))
                ->required()
                ->rules([
                    "max:30",
                    "regex:/".Define::RULES_REGEX_SYSTEM_NAME."/",
                    "uniqueInTable:{$classname},{$this->custom_table->id}",
                    Rule::notIn(SystemColumn::arrays()),
                ])
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
        } else {
            $form->display('column_name', exmtrans("custom_column.column_name"));
        }

        $form->text('column_view_name', exmtrans("custom_column.column_view_name"))
            ->required()
            ->rules("max:40")
            ->help(exmtrans('common.help.view_name'));

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        $column_type = isset($id) ? CustomColumn::getEloquent($id)->column_type : null;
        if (!isset($id)) {
            $form->select('column_type', exmtrans("custom_column.column_type"))
                ->help(exmtrans("custom_column.help.column_type"))
                ->options(function () {
                    return collect(ColumnType::arrays())->filter(function ($arr) {
                        if (System::organization_available() || $arr != ColumnType::ORGANIZATION) {
                            return true;
                        } else {
                            return false;
                        }
                    })->mapWithKeys(function ($column_type) {
                        return [$column_type => ColumnType::getHtml($column_type)];
                    })->toArray();
                })
                ->escapeMarkup(true)
                ->attribute(['data-filtertrigger' =>true,
                    'data-changehtml' => json_encode([
                        [
                            'url' => admin_urls('column', $this->custom_table->table_name, $id, 'columnTypeHtml'),
                            'target' => '.form_dynamic_options',
                            'response' => '.form_dynamic_options_response',
                            'form_type' => 'option',
                        ],
                    ]),
                ])
                ->required();
        } else {
            $form->display('column_type', exmtrans("custom_column.column_type"))
                ->displayText(function ($val) {
                    return ColumnType::getHtml($val);
                })->escape(false);
            $form->hidden('column_type')->default($column_type);
        }

        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($column_item, $id) {
            $form->switchbool('required', exmtrans("common.required"));
            $form->switchbool('index_enabled', exmtrans("custom_column.options.index_enabled"))
                ->rules([
                    new Validator\CustomColumnIndexCountRule($this->custom_table, $id),
                    new Validator\CustomColumnUsingIndexRule($id),
                ])
                ->attribute(['data-filtertrigger' =>true])
                ->help(sprintf(exmtrans("custom_column.help.index_enabled"), getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'))));

            $form->switchbool('freeword_search', exmtrans("custom_column.options.freeword_search"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'options_index_enabled', 'value' => '1'])])
                ->help(exmtrans("custom_column.help.freeword_search"));

            $form->switchbool('unique', exmtrans("custom_column.options.unique"))
                ->help(exmtrans("custom_column.help.unique"));

            $form->switchbool('init_only', exmtrans("custom_column.options.init_only"))
                ->help(exmtrans("custom_column.help.init_only"));

            $form->text('placeholder', exmtrans("custom_column.options.placeholder"))
                ->help(exmtrans("custom_column.help.placeholder"));

            $form->text('dropzone_title', exmtrans("custom_column.options.dropzone_title"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ['file', 'image']])])
                ->help(exmtrans("custom_column.help.dropzone_title"));

            $form->text('help', exmtrans("custom_column.options.help"))->help(exmtrans("custom_column.help.help"));

            $form->numberRange('min_width', 'max_width', exmtrans("custom_column.options.min_max_width"))
                ->help(exmtrans("custom_column.help.min_max_width"))
            ;

            $form->select('text_align', exmtrans("custom_column.options.text_align"))
                ->help(exmtrans("custom_column.help.text_align"))
                ->options(TextAlignType::transArray('custom_column.align_type_options'));

            if ($this->custom_table->table_name == SystemTableName::USER) {
                $form->select('editable_userinfo', exmtrans("custom_column.editable_userinfo"))
                    ->help(exmtrans("custom_column.help.editable_userinfo"))
                    ->options(EditableUserInfoType::transArray('custom_column.editable_userinfo_options'))
                    ->disableClear()
                    ->default(EditableUserInfoType::VIEW);
            }

            // setting for each settings of column_type. --------------------------------------------------
            // Form options area -- start
            $form->html('<div class="form_dynamic_options">')->plain();
            if (isset($column_item)) {
                $column_item->setCustomColumnForm($form);
            }
            // Form options area -- End
            $form->html('</div>')->plain();
        })->disableHeader();

        $form->number('order', exmtrans("custom_column.order"))->rules("integer")
        ->help(sprintf(exmtrans("common.help.order"), exmtrans('common.custom_column')));

        // if create column, add custom form and view
        if (!isset($id)) {
            $form->exmheader(exmtrans('common.create_only_setting'))->hr();

            $form->switchbool('add_custom_form_flg', exmtrans("custom_column.add_custom_form_flg"))->help(exmtrans("custom_column.help.add_custom_form_flg"))
                ->default("1")
            ;
            $form->switchbool('add_custom_view_flg', exmtrans("custom_column.add_custom_view_flg"))->help(exmtrans("custom_column.help.add_custom_view_flg"))
                ->default("0")
            ;
            $form->switchbool('add_table_label_flg', exmtrans("custom_column.add_table_label_flg"))->help(exmtrans("custom_column.help.add_table_label_flg"))
                ->default("0")
            ;
            $form->ignore('add_custom_form_flg');
            $form->ignore('add_custom_view_flg');
            $form->ignore('add_table_label_flg');
        }

        $form->saved(function (Form $form) {
            $model = $form->model();
            $this->addColumnAfterSaved($model);
        });

        $form->disableCreatingCheck(false);
        $form->disableEditingCheck(false);
        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use ($id, $custom_table) {
            if (isset($id) && boolval(CustomColumn::getEloquent($id)->disabled_delete)) {
                $tools->disableDelete();
            }
            $tools->add(new Tools\CustomTableMenuButton('column', $custom_table));
        });
        return $form;
    }

    public function calcModal(Request $request, $tableKey, $id = null)
    {
        // get other columns
        // return $id is null(calling create fuction) or not match $id and row id.
        $custom_column_options = CalcService::getCalcCustomColumnOptions($id, $this->custom_table);

        // get value
        $value = $request->get('options_calc_formula') ?? '';

        $render = view('exment::custom-column.calc_formula_modal', [
            'custom_columns' => $custom_column_options,
            'value' => $value,
            'symbols' => CalcService::getSymbols(),
        ]);
        return getAjaxResponse([
            'body'  => $render->render(),
            'showReset' => true,
            'title' => exmtrans("custom_column.options.calc_formula"),
            'contentname' => 'options_calc_formula',
            'submitlabel' => trans('admin.setting'),
            'disableSubmit' => true,
            'modalSize' => 'modal-xl',
        ]);
    }


    /**
     * add column form and view after saved
     */
    protected function addColumnAfterSaved($model)
    {
        // set custom form columns --------------------------------------------------
        $add_custom_form_flg = app('request')->input('add_custom_form_flg');
        if (boolval($add_custom_form_flg)) {
            $form = CustomForm::getDefault($this->custom_table);
            $form_block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->first();

            // whether saved check (as index)
            $exists = $form_block->custom_form_columns()
                ->where('form_column_target_id', $model->id)
                ->where('form_column_type', FormColumnType::COLUMN)
                ->count() > 0;

            if (!$exists) {
                // get order
                $order = $form_block->custom_form_columns()
                    ->where('row_no', 1)
                    ->where('column_no', 1)
                    ->where('form_column_type', FormColumnType::COLUMN)
                    ->max('order') ?? 0;
                $order++;

                // get width
                /** @phpstan-ignore-next-line need test 'Called 'first' on Laravel collection, but could have been retrieved as a query.' */
                $width = $form_block->custom_form_columns()
                    ->where('row_no', 1)
                    ->where('column_no', 1)
                    ->select('width')
                    ->pluck('width')
                    ->first() ?? 2;

                $custom_form_column = new CustomFormColumn();
                $custom_form_column->custom_form_block_id = $form_block->id;
                $custom_form_column->form_column_type = FormColumnType::COLUMN;
                $custom_form_column->form_column_target_id = $model->id;
                $custom_form_column->row_no = 1;
                $custom_form_column->column_no = 1;
                $custom_form_column->width = $width;
                $custom_form_column->order = $order;
                $custom_form_column->save();
            }
        }

        // set custom form columns --------------------------------------------------
        $add_custom_view_flg = app('request')->input('add_custom_view_flg');
        if (boolval($add_custom_view_flg)) {
            $view = CustomView::getDefault($this->custom_table, false);

            // get order
            if ($view->custom_view_columns()->count() == 0) {
                $order = 1;
            } else {
                // get order. ignore system column and footer
                $order = $view->custom_view_columns
                    ->filter(function ($custom_view_column) {
                        if ($custom_view_column->view_column_type != ConditionType::SYSTEM) {
                            return true;
                        }
                        $systemColumn = SystemColumn::getOption(['id' => $custom_view_column->view_column_target_id]);
                        if (!isset($systemColumn)) {
                            return false;
                        }

                        // check not footer
                        return !boolval(array_get($systemColumn, 'footer'));
                    })->max('order') ?? 1;
                $order++;
            }

            $custom_view_column = new CustomViewColumn();
            $custom_view_column->custom_view_id = $view->id;
            $custom_view_column->view_column_type = ConditionType::COLUMN;
            $custom_view_column->view_column_target = $model->id;
            $custom_view_column->order = $order;

            $custom_view_column->save();
        }


        // set table labels --------------------------------------------------
        $add_table_label_flg = app('request')->input('add_table_label_flg');
        if (boolval($add_table_label_flg)) {
            $priority = CustomColumnMulti::where('custom_table_id', $this->custom_table->id)->where('multisetting_type', MultisettingType::TABLE_LABELS)->max('priority') ?? 0;

            CustomColumnMulti::create([
                'custom_table_id' => $this->custom_table->id,
                'multisetting_type' => MultisettingType::TABLE_LABELS,
                'priority' => ++$priority,
                'options' => [
                    'table_label_id' => $model->id,
                ],
            ]);
        }
    }


    /**
     * Get column_type's option form
     *
     * @param Request $request
     * @return array
     */
    public function columnTypeHtml(Request $request)
    {
        $val = $request->get('val');
        $form_type = $request->get('form_type');
        $form_uniqueName = $request->get('form_uniqueName');
        $id = $request->route('id');

        // get custom item
        $column_item = $this->getCustomItem($request, $id, $val);

        $form = new Form(new CustomColumn());
        $form->setUniqueName($form_uniqueName)->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($column_item) {
            // Form options area -- start
            $form->html('<div class="form_dynamic_options_response">')->plain();
            if (isset($column_item)) {
                $column_item->setCustomColumnForm($form);
            }
            $form->html('</div>')->plain();
        });

        $body = $form->render();
        $script = \Admin::purescript()->render();
        return [
            'body'  => $body,
            'script' => $script,
        ];
    }


    protected function getCustomItem(Request $request, $id, $column_type)
    {
        return CustomItem::getItem(new CustomColumn([
            'custom_table_id' => $this->custom_table->id,
            'id' => $id,
            'column_type' => $column_type,
        ]));
    }
}
