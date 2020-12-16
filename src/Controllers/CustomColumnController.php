<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\Calc\CalcService;
use Symfony\Component\HttpFoundation\Response;
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
        
        $title = exmtrans("custom_column.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_column.description"), 'fa-list');
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
        $grid->column('column_name', exmtrans("custom_column.column_name"))->sortable();
        $grid->column('column_view_name', exmtrans("custom_column.column_view_name"))->sortable();
        $grid->column('column_type', exmtrans("custom_column.column_type"))->sortable()->displayEscape(function ($val) {
            return array_get(ColumnType::transArray("custom_column.column_type_options"), $val);
        });
        $grid->column('required', exmtrans("common.required"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        });
        $grid->column('index_enabled', exmtrans("custom_column.options.index_enabled"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        });
        $grid->column('options->freeword_search', exmtrans("custom_column.options.freeword_search"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        });
        $grid->column('unique', exmtrans("custom_column.options.unique"))->display(function ($val) {
            return \Exment::getTrueMark($val);
        });
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
     * @return Form
     */
    protected function form($id = null)
    {
        $controller = $this;
        $form = new Form(new CustomColumn);
        // set script
        $ver = \Exment::getExmentCurrentVersion();
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

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        $column_type = isset($id) ? CustomColumn::getEloquent($id)->column_type : null;
        if (!isset($id)) {
            $form->select('column_type', exmtrans("custom_column.column_type"))
                ->help(exmtrans("custom_column.help.column_type"))
                ->options(function () {
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
        } else {
            $form->display('column_type', exmtrans("custom_column.column_type"))
                ->displayText(function ($val) {
                    return array_get(ColumnType::transArray("custom_column.column_type_options"), $val);
                })->escape(false);
            $form->hidden('column_type')->default($column_type);
        }

        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($column_type, $id, $controller) {
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
            $form->text('default', exmtrans("custom_column.options.default"));
            $form->switchbool('login_user_default', exmtrans("custom_column.options.login_user_default"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::USER]])])
                ->help(exmtrans("custom_column.help.login_user_default"));
            $form->text('placeholder', exmtrans("custom_column.options.placeholder"))
                ->help(exmtrans("custom_column.help.placeholder"));
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
                ->options(function ($value, $field) use ($column_type) {
                    if (is_nullorempty($field)) {
                        return [];
                    }
            
                    // check $value or $field->data()
                    $custom_table = null;
                    if (isset($value)) {
                        $custom_view = CustomView::getEloquent($value);
                        $custom_table = $custom_view ? $custom_view->custom_table : null;
                    } elseif (!is_nullorempty($field->data())) {
                        $custom_table = CustomTable::getEloquent(array_get($field->data(), 'select_target_table'));
                    }
                    
                    if (!isset($custom_table)) {
                        if (!ColumnType::isUserOrganization($column_type)) {
                            return [];
                        }
                        $custom_table = CustomTable::getEloquent($column_type);
                    }
            
                    if (!isset($custom_table)) {
                        return [];
                    }

                    return CustomTable::getEloquent($custom_table)->custom_views
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
                ->help(exmtrans("custom_column.help.calc_formula") . \Exment::getMoreTag('column', 'custom_column.options.calc_formula'))
                ->ajax(admin_urls('column', $custom_table->table_name, $id, 'calcModal'))
                ->modalContentname('options_calc_formula')
                ->nullText(exmtrans('common.no_setting'))
                ->valueTextScript('Exment.CustomColumnEvent.GetSettingValText();')
                ->text(function ($value) use ($id, $custom_table, $self) {
                    return CalcService::getCalcDisplayText($value, $custom_table);
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
     * Get import export select list
     *
     * @return array
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
