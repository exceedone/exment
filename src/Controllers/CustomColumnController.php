<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\CurrencySymbol;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Validator;
use Illuminate\Validation\Rule;

class CustomColumnController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);
        
        $this->setPageInfo(exmtrans("custom_column.header"), exmtrans("custom_column.header"), exmtrans("custom_column.description"), 'fa-list');
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
     * @return Content
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
        $grid = new Grid(new CustomColumn);
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table"))->sortable();
        $grid->column('column_name', exmtrans("custom_column.column_name"))->sortable();
        $grid->column('column_view_name', exmtrans("custom_column.column_view_name"))->sortable();
        $grid->column('column_type', exmtrans("custom_column.column_type"))->sortable()->displayEscape(function ($val) {
            return array_get(ColumnType::transArray("custom_column.column_type_options"), $val);
        });
        $grid->column('required', exmtrans("common.required"))->sortable()->display(function ($val) {
            return getTrueMark($val);
        });
        $grid->column('index_enabled', exmtrans("custom_column.options.index_enabled"))->sortable()->display(function ($val) {
            return getTrueMark($val);
        });
        $grid->column('unique', exmtrans("custom_column.options.unique"))->sortable()->display(function ($val) {
            return getTrueMark($val);
        });
        $grid->column('order', exmtrans("custom_column.order"))->editable('number')->sortable();

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
            $filter->equal('column_name', exmtrans("custom_column.column_name"));
            $filter->equal('column_view_name', exmtrans("custom_column.column_view_name"));
            $filter->equal('column_type', exmtrans("custom_column.column_type"))->select(function ($val) {
                return array_get(ColumnType::transArray("custom_column.column_type_options"), $val);
            });

            $keys = ['required' => 'common', 'index_enabled' => 'custom_column.options', 'unique' => 'custom_column.options'];
            foreach ($keys as $key => $label) {
                $filter->where(function ($query) use ($key, $label) {
                    $query->whereIn("options->$key", [1, "1"]);
                }, exmtrans("$label.$key"))->radio([
                    '' => 'All',
                    '1' => 'YES',
                ]);
            }
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
        $controller = $this;
        $form = new Form(new CustomColumn);
        // set script
        $ver = getExmentCurrentVersion();
        if (!isset($ver)) {
            $ver = date('YmdHis');
        }
        $form->html('<script src="'.asset('vendor/exment/js/customcolumn.js?ver='.$ver).'"></script>');

        $form->hidden('custom_table_id')->default($this->custom_table->id);
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
        $form->select('column_type', exmtrans("custom_column.column_type"))
        ->help(exmtrans("custom_column.help.column_type"))
        ->options(function() {
            $arrays = collect(ColumnType::arrays())->filter(function ($arr) {
                if (System::organization_available() || $arr != ColumnType::ORGANIZATION) {
                    return true;
                } else {
                    return false;
                }
            })->toArray();
            return getTransArray($arrays, "custom_column.column_type_options");
        })
        ->attribute(['data-filtertrigger' =>true,
            'data-linkage' => json_encode([
                'options_select_import_column_id' => [
                    'url' => admin_url('webapi/table/indexcolumns'),
                    'text' => 'column_view_name',
                ],
                'options_select_export_column_id' => [
                    'url' => admin_url('webapi/table/columns'),
                    'text' => 'column_view_name',
                ],
                'options_select_target_view' => [
                    'url' => admin_url('webapi/table/filterviews'),
                    'text' => 'view_view_name',
                ],
            ]),
            'data-linkage-expand' => json_encode(['custom_type' => true]),
        ])
        ->required();

        if (!isset($id)) {
            $id = $form->model()->id;
        }

        $column_type = isset($id) ? CustomColumn::getEloquent($id)->column_type : null;
        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($column_type, $id, $controller) {
            $form->switchbool('required', exmtrans("common.required"));
            $form->switchbool('index_enabled', exmtrans("custom_column.options.index_enabled"))
                ->rules([
                    new Validator\CustomColumnIndexCountRule($this->custom_table, $id),
                    new Validator\CustomColumnUsingIndexRule($id),
                ])
                ->help(sprintf(exmtrans("custom_column.help.index_enabled"), getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'))));
            $form->switchbool('unique', exmtrans("custom_column.options.unique"))
                ->help(exmtrans("custom_column.help.unique"));
            $form->switchbool('init_only', exmtrans("custom_column.options.init_only"))
                ->help(exmtrans("custom_column.help.init_only"));
            $form->text('default', exmtrans("custom_column.options.default"));
            $form->switchbool('login_user_default', exmtrans("custom_column.options.login_user_default"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::USER]])])
                ->help(exmtrans("custom_column.help.login_user_default"));
            $form->text('placeholder', exmtrans("custom_column.options.placeholder"));
            $form->text('help', exmtrans("custom_column.options.help"))->help(exmtrans("custom_column.help.help"));
            
            $form->text('min_width', exmtrans("custom_column.options.min_width"))
                ->help(exmtrans("custom_column.help.min_width"))
                ->rules(['nullable', 'integer'])
                ;
            $form->text('max_width', exmtrans("custom_column.options.max_width"))
                ->help(exmtrans("custom_column.help.max_width"))
                ->rules(['nullable', 'integer'])
                ;
            
            // setting for each settings of column_type. --------------------------------------------------

            // text
            // string length
            $form->number('string_length', exmtrans("custom_column.options.string_length"))
                ->default(256)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXT, ColumnType::TEXTAREA]])]);

            $form->number('rows', exmtrans("custom_column.options.rows"))
                ->default(6)
                ->min(1)
                ->max(30)
                ->help(exmtrans("custom_column.help.rows"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXTAREA, ColumnType::EDITOR]])]);

            $form->checkbox('available_characters', exmtrans("custom_column.options.available_characters"))
                ->options(CustomColumn::getAvailableCharacters()->pluck('label', 'key'))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXT]])])
                ->help(exmtrans("custom_column.help.available_characters"))
                ;

            $form->switchbool('suggest_input', exmtrans("custom_column.options.suggest_input"))
                ->help(exmtrans("custom_column.help.suggest_input"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXT]])]);

            if (boolval(config('exment.expart_mode', false))) {
                $manual_url = getManualUrl('column#'.exmtrans('custom_column.options.regex_validate'));
                $form->text('regex_validate', exmtrans("custom_column.options.regex_validate"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXT]])])
                    ->rules('regularExpression')
                    ->help(sprintf(exmtrans("custom_column.help.regex_validate"), $manual_url));
            }

            // number
            //if(in_array($column_type, [ColumnType::INTEGER,ColumnType::DECIMAL])){
            $form->number('number_min', exmtrans("custom_column.options.number_min"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_CALC()])])
                ->disableUpdown()
                ->defaultEmpty();
            $form->number('number_max', exmtrans("custom_column.options.number_max"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_CALC()])])
                ->disableUpdown()
                ->defaultEmpty();
            
            $form->switchbool('number_format', exmtrans("custom_column.options.number_format"))
                ->help(exmtrans("custom_column.help.number_format"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_CALC()])]);

            $form->switchbool('percent_format', exmtrans("custom_column.options.percent_format"))
                ->help(exmtrans("custom_column.help.percent_format"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::DECIMAL]])]);

            $form->number('decimal_digit', exmtrans("custom_column.options.decimal_digit"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::DECIMAL, ColumnType::CURRENCY]])])
                ->default(2)
                ->min(0)
                ->max(8);

            $form->switchbool('updown_button', exmtrans("custom_column.options.updown_button"))
                ->help(exmtrans("custom_column.help.updown_button"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::INTEGER]])])
                ;
            
            $form->select('currency_symbol', exmtrans("custom_column.options.currency_symbol"))
                ->help(exmtrans("custom_column.help.currency_symbol"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::CURRENCY]])])
                ->required()
                ->options(function ($option) {
                    // create options
                    $options = [];
                    $currencies = CurrencySymbol::values();
                    foreach ($currencies as $currency) {
                        // make text
                        $options[$currency->getValue()] = getCurrencySymbolLabel($currency, true, '123,456.00');
                    }
                    return $options;
                });
            //}

            // date, time, datetime
            $form->switchbool('datetime_now_saving', exmtrans("custom_column.options.datetime_now_saving"))
                ->help(exmtrans("custom_column.help.datetime_now_saving"))
                ->default("0")
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_DATETIME()])]);
            $form->switchbool('datetime_now_creating', exmtrans("custom_column.options.datetime_now_creating"))
                ->help(exmtrans("custom_column.help.datetime_now_creating"))
                ->default("0")
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_DATETIME()])]);

            // select
            // define select-item
            $form->textarea('select_item', exmtrans("custom_column.options.select_item"))
                    ->required()
                    ->help(exmtrans("custom_column.help.select_item"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::SELECT])]);
            // define select-item
            $form->textarea('select_item_valtext', exmtrans("custom_column.options.select_item"))
                    ->required()
                    ->help(exmtrans("custom_column.help.select_item_valtext"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::SELECT_VALTEXT])]);

            // define select-target table
            $form->select('select_target_table', exmtrans("custom_column.options.select_target_table"))
                    ->help(exmtrans("custom_column.help.select_target_table"))
                    ->required()
                    ->options(function ($select_table) {
                        $options = CustomTable::filterList()->whereNotIn('table_name', [SystemTableName::USER, SystemTableName::ORGANIZATION])->pluck('table_view_name', 'id')->toArray();
                        return $options;
                    })
                    ->attribute([
                        'data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::SELECT_TABLE]),
                        'data-linkage' => json_encode([
                            'options_select_import_column_id' => [
                                'url' => admin_url('webapi/table/indexcolumns'),
                                'text' => 'column_view_name',
                            ],
                            'options_select_export_column_id' => [
                                'url' => admin_url('webapi/table/columns'),
                                'text' => 'column_view_name',
                            ],
                            'options_select_target_view' => [
                                'url' => admin_url('webapi/table/filterviews'),
                                'text' => 'view_view_name',
                            ]
                        ]),
                    ]);

            // define select-target table view
            $form->select('select_target_view', exmtrans("custom_column.options.select_target_view"))
                ->help(exmtrans("custom_column.help.select_target_view"))
                ->options(function ($select_view, $form) use ($column_type) {
                    $data = $form->data();
                    if (!isset($data)) {
                        return [];
                    }

                    // select_table
                    $select_target_table = array_get($data, 'select_target_table');
                    if (!isset($select_target_table)) {
                        if (!ColumnType::isUserOrganization($column_type)) {
                            return [];
                        }
                        $select_target_table = CustomTable::getEloquent($column_type);
                    }

                    return CustomTable::getEloquent($select_target_table)->custom_views
                        ->filter(function ($value) {
                            return array_get($value, 'view_kind_type') == ViewKindType::FILTER;
                        })->pluck('view_view_name', 'id');
                })
                ->attribute([
                    'data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_SELECT_TABLE()]),
                ]);
            
            $manual_url = getManualUrl('data_import_export#'.exmtrans('custom_column.help.select_import_column_id_key'));
            $form->select('select_import_column_id', exmtrans("custom_column.options.select_import_column_id"))
                ->help(exmtrans("custom_column.help.select_import_column_id", $manual_url))
                ->options(function ($select_table, $form) use ($id, $controller) {
                    return $controller->getImportExportColumnSelect($select_table, $form, $id);
                })
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_SELECT_TABLE()])]);

            $form->select('select_export_column_id', exmtrans("custom_column.options.select_export_column_id"))
                ->help(exmtrans("custom_column.help.select_export_column_id"))
                ->options(function ($select_table, $form) use ($id, $controller) {
                    return $controller->getImportExportColumnSelect($select_table, $form, $id, false);
                })
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_SELECT_TABLE()])]);

            $form->switchbool('select_load_ajax', exmtrans("custom_column.options.select_load_ajax"))
                ->help(exmtrans("custom_column.help.select_load_ajax", config('exment.select_table_limit_count', 100)))
                ->default("0")
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_SELECT_TABLE()])]);

            // user organization
            $form->switchbool('showing_all_user_organizations', exmtrans("custom_column.options.showing_all_user_organizations"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_USER_ORGANIZATION()])])
                ->help(exmtrans("custom_column.help.showing_all_user_organizations"))
                ->default('0');


            // yes/no ----------------------------
            $form->text('true_value', exmtrans("custom_column.options.true_value"))
                    ->help(exmtrans("custom_column.help.true_value"))
                    ->required()
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::BOOLEAN])]);

            $form->text('true_label', exmtrans("custom_column.options.true_label"))
                    ->help(exmtrans("custom_column.help.true_label"))
                    ->required()
                    ->default(exmtrans("custom_column.options.true_label_default"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::BOOLEAN])]);
                
            $form->text('false_value', exmtrans("custom_column.options.false_value"))
                    ->help(exmtrans("custom_column.help.false_value"))
                    ->required()
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::BOOLEAN])]);

            $form->text('false_label', exmtrans("custom_column.options.false_label"))
                    ->help(exmtrans("custom_column.help.false_label"))
                    ->required()
                    ->default(exmtrans("custom_column.options.false_label_default"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::BOOLEAN])]);

            // auto numbering
            $form->select('auto_number_type', exmtrans("custom_column.options.auto_number_type"))
                    ->required()
                    ->options(
                        [
                        'format' => exmtrans("custom_column.options.auto_number_type_format"),
                        'random25' => exmtrans("custom_column.options.auto_number_type_random25"),
                        'random32' => exmtrans("custom_column.options.auto_number_type_random32"),
                        'other' => exmtrans("custom_column.options.auto_number_other"),
                        ]
                    )
                    ->attribute(['data-filtertrigger' =>true, 'data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::AUTO_NUMBER])]);

            // set manual
            $manual_url = getManualUrl('column#'.exmtrans('custom_column.auto_number_format_rule'));
            $form->text('auto_number_format', exmtrans("custom_column.options.auto_number_format"))
                    ->attribute(['data-filter' => json_encode([
                        ['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::AUTO_NUMBER],
                        ['parent' => 1, 'key' => 'options_auto_number_type', 'value' => 'format'],
                    ])])
                    ->help(sprintf(exmtrans("custom_column.help.auto_number_format"), $manual_url))
                ;

            // calc
            $custom_table = $this->custom_table;
            $self = $this;
            $form->valueModal('calc_formula', exmtrans("custom_column.options.calc_formula"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_CALC()])])
                ->help(exmtrans("custom_column.help.calc_formula"))
                ->ajax(admin_urls('column', $custom_table->table_name, $id, 'calcModal'))
                ->modalContentname('options_calc_formula')
                ->valueTextScript('Exment.CustomColumnEvent.GetSettingValText();')
                ->text(function ($value) use ($id, $custom_table, $self) {
                    /////TODO:copy and paste
                    if (!isset($value)) {
                        return null;
                    }
                    // convert json to array
                    if (!is_array($value) && is_json($value)) {
                        $value = json_decode($value, true);
                    }

                    $custom_column_options = $self->getCalcCustomColumnOptions($id, $custom_table);
                    ///// get text
                    $texts = [];
                    foreach ($value as &$v) {
                        $texts[] = $self->getCalcDisplayText($v, $custom_column_options);
                    }
                    return implode(" ", $texts);
                })
            ;

            // image, file, select
            // enable multiple
            $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"))
                ->help(exmtrans("custom_column.help.multiple_enabled"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_MULTIPLE_ENABLED()])]);
        })->disableHeader();

        $form->number('order', exmtrans("custom_column.order"))->rules("integer")
        ->help(sprintf(exmtrans("common.help.order"), exmtrans('common.custom_column')));

        // if create column, add custom form and view
        if (!isset($id)) {
            $form->switchbool('add_custom_form_flg', exmtrans("custom_column.add_custom_form_flg"))->help(exmtrans("custom_column.help.add_custom_form_flg"))
                ->default("1")
                ->attribute(['data-filtertrigger' =>true])
            ;
            $form->switchbool('add_custom_view_flg', exmtrans("custom_column.add_custom_view_flg"))->help(exmtrans("custom_column.help.add_custom_view_flg"))
                ->default("0")
                ->attribute(['data-filtertrigger' =>true])
            ;
            $form->ignore('add_custom_form_flg');
            $form->ignore('add_custom_view_flg');
        }

        $form->saved(function (Form $form) {
            // create or drop index --------------------------------------------------
            $model = $form->model();
            $model->alterColumn();

            $this->addColumnAfterSaved($model);
        });

        $form->disableCreatingCheck(false);
        $form->disableEditingCheck(false);
        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use ($id, $form, $custom_table) {
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
        $custom_column_options = $this->getCalcCustomColumnOptions($id, $this->custom_table);
        
        // get value
        $value = $request->get('options_calc_formula');

        if (!isset($value)) {
            $value = [];
        }
        $value = jsonToArray($value);

        ///// get text
        foreach ($value as &$v) {
            $v['text'] = $this->getCalcDisplayText($v, $custom_column_options);
        }
        
        $render = view('exment::custom-column.calc_formula_modal', [
            'custom_columns' => $custom_column_options,
            'value' => $value,
            'symbols' => exmtrans('custom_column.symbols'),
        ]);
        return getAjaxResponse([
            'body'  => $render->render(),
            'showReset' => true,
            'title' => exmtrans("custom_column.options.calc_formula"),
            'contentname' => 'options_calc_formula',
            'submitlabel' => trans('admin.setting'),
        ]);
    }

    protected function getCalcDisplayText($v, $custom_column_options)
    {
        $val = array_get($v, 'val');
        $table = array_get($v, 'table');
        $text = null;
        switch (array_get($v, 'type')) {
            case 'dynamic':
            case 'select_table':
                $target_column = collect($custom_column_options)->first(function ($custom_column_option) use ($v) {
                    return array_get($v, 'val') == array_get($custom_column_option, 'val') && array_get($v, 'type') == array_get($custom_column_option, 'type');
                });
                $text = array_get($target_column, 'text');
                break;
            case 'count':
                if (isset($table)) {
                    $child_table = CustomTable::getEloquent($table);
                    if (isset($child_table)) {
                        $text = exmtrans('custom_column.child_count_text', $child_table->table_view_name);
                    }
                }
                break;
            case 'summary':
                $column = CustomColumn::getEloquent($val);
                if (isset($column)) {
                    $text = exmtrans('custom_column.child_sum_text', $column->custom_table->table_view_name, $column->column_view_name);
                }
                break;
            case 'symbol':
                $symbols = exmtrans('custom_column.symbols');
                $text = array_get($symbols, $val);
                break;
            case 'fixed':
                $text = $val;
                break;
        }
        return $text;
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
                    ->where('column_no', 1)
                    ->where('form_column_type', FormColumnType::COLUMN)
                    ->max('order') ?? 0;
                $order++;

                $custom_form_column = new CustomFormColumn;
                $custom_form_column->custom_form_block_id = $form_block->id;
                $custom_form_column->form_column_type = FormColumnType::COLUMN;
                $custom_form_column->form_column_target_id = $model->id;
                $custom_form_column->column_no = 1;
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

            $custom_view_column = new CustomViewColumn;
            $custom_view_column->custom_view_id = $view->id;
            $custom_view_column->view_column_type = ConditionType::COLUMN;
            $custom_view_column->view_column_target = $model->id;
            $custom_view_column->order = $order;

            $custom_view_column->save();
        }
    }

    /**
     * Get column options for calc
     *
     * @param [type] $id
     * @param [type] $custom_table
     * @return void
     */
    protected function getCalcCustomColumnOptions($id, $custom_table)
    {
        $options = [];

        // get calc options
        $custom_table->custom_columns_cache->filter(function ($column) use ($id) {
            if (isset($id) && $id == array_get($column, 'id')) {
                return false;
            }
            if (!ColumnType::isCalc(array_get($column, 'column_type'))) {
                return false;
            }

            return true;
        })->each(function ($column) use (&$options) {
            $options[] = [
                'val' => $column->id,
                'type' => 'dynamic',
                'text' => $column->column_view_name,
            ];
        });
        
        // get select table custom columns
        $select_table_custom_columns = [];
        $custom_table->custom_columns_cache->each(function ($column) use ($id, &$options) {
            if (isset($id) && $id == array_get($column, 'id')) {
                return;
            }
            if (!ColumnType::isSelectTable(array_get($column, 'column_type'))) {
                return;
            }

            // get select table's calc column
            $column->select_target_table->custom_columns_cache->filter(function ($select_target_column) use ($id, $column, &$options) {
                if (isset($id) && $id == array_get($select_target_column, 'id')) {
                    return false;
                }
                if (!ColumnType::isCalc(array_get($select_target_column, 'column_type'))) {
                    return false;
                }
    
                return true;
            })->each(function ($select_target_column) use ($column, &$options) {
                $options[] = [
                    'val' => $column->id,
                    'type' => 'select_table',
                    'from' => $select_target_column->id,
                    'text' => $column->column_view_name . '/' . $select_target_column->column_view_name,
                ];
            });
        });

        // add child columns
        $child_relations = $custom_table->custom_relations;
        if (isset($child_relations)) {
            foreach ($child_relations as $child_relation) {
                $child_table = $child_relation->child_custom_table;
                $child_table_name = array_get($child_table, 'table_view_name');
                $options[] = [
                    'type' => 'count',
                    'text' => exmtrans('custom_column.child_count_text', $child_table_name),
                    'custom_table_id' => $child_table->id
                ];

                $child_columns = $child_table->custom_columns_cache->filter(function ($column) {
                    return in_array(array_get($column, 'column_type'), ColumnType::COLUMN_TYPE_CALC());
                })->map(function ($column) use ($child_table_name) {
                    return [
                        'type' => 'summary',
                        'val' => $column->id,
                        'text' => exmtrans('custom_column.child_sum_text', $child_table_name, $column->column_view_name),
                        'custom_table_id' => $column->custom_table_id
                    ];
                })->toArray();
                $options = array_merge($options, $child_columns);
            }
        }
        
        return $options;
    }

    /**
     * Get import export select list
     *
     * @return void
     */
    protected function getImportExportColumnSelect($select_table, $form, $id, $isImport = true)
    {
        $data = $form->data();
        if (!isset($data)) {
            return [];
        }

        // whether column_type is user or org
        if (!is_null(old('column_type'))) {
            $model = CustomColumn::getEloquent(old('column_type'), $this->custom_table);
        } elseif (isset($id) || old('column_type')) {
            $model = CustomColumn::getEloquent($id);
        }
        if (isset($model) && in_array($model->column_type, [ColumnType::USER, ColumnType::ORGANIZATION])) {
            return CustomTable::getEloquent($model->column_type)->getColumnsSelectOptions([
                'index_enabled_only' => $isImport,
                'include_system' => false,
            ]) ?? [];
        }

        // select_table
        if (is_null($select_target_table = array_get($data, 'select_target_table'))) {
            return [];
        }
        return CustomTable::getEloquent($select_target_table)->getColumnsSelectOptions([
            'index_enabled_only' => $isImport,
            'include_system' => false,
        ]) ?? [];
    }
}
