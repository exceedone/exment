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
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\CurrencySymbol;

class CustomColumnController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        
        $this->setPageInfo(exmtrans("custom_column.header"), exmtrans("custom_column.header"), exmtrans("custom_column.description"));
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
        $this->setFormViewInfo($request);
        
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
        $grid = new Grid(new CustomColumn);
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table"))->sortable();
        $grid->column('column_name', exmtrans("custom_column.column_name"))->sortable();
        $grid->column('column_view_name', exmtrans("custom_column.column_view_name"))->sortable();
        $grid->column('column_type', exmtrans("custom_column.column_type"))->sortable()->display(function ($val) {
            return esc_html(array_get(ColumnType::transArray("custom_column.column_type_options"), $val));
        });
        $grid->column('order', exmtrans("custom_column.order"))->editable('number')->sortable();

        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
        }

        // set default sort
        $grid->model()->orderBy('order', 'asc');

        //  $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if (boolval($actions->row->system_flg)) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\GridChangePageMenu('column', $this->custom_table, false));
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
        $form = new Form(new CustomColumn);
        // set script
        //TODO: call using pjax
        $date = \Carbon\Carbon::now()->format('YmdHis');
        $form->html('<script src="'.asset('vendor/exment/js/customcolumn.js?ver='.$date).'"></script>');

        $form->hidden('custom_table_id')->default($this->custom_table->id);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table"))->default($this->custom_table->table_view_name);
        
        if (!isset($id)) {
            $classname = CustomColumn::class;
            $form->text('column_name', exmtrans("custom_column.column_name"))
                ->required()
                ->rules("regex:/".Define::RULES_REGEX_SYSTEM_NAME."/|uniqueInTable:{$classname},{$this->custom_table->id}")
                ->help(exmtrans('common.help_code'));
        } else {
            $form->display('column_name', exmtrans("custom_column.column_name"));
        }

        $form->text('column_view_name', exmtrans("custom_column.column_view_name"))
            ->required()
            ->help(exmtrans('common.help.view_name'));
        $form->select('column_type', exmtrans("custom_column.column_type"))
        ->options(ColumnType::transArray("custom_column.column_type_options"))
        ->attribute(['data-filtertrigger' =>true])
        ->required();

        if (!isset($id)) {
            $id = $form->model()->id;
        }

        $column_type = isset($id) ? CustomColumn::getEloquent($id)->column_type : null;
        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) use ($column_type, $id) {
            $form->switchbool('required', exmtrans("common.reqired"));
            $form->switchbool('index_enabled', exmtrans("custom_column.options.index_enabled"))
                ->rules("maxTableIndex:{$this->custom_table->id},10|usingIndexColumn:{$id}")
                ->help(sprintf(exmtrans("custom_column.help.index_enabled"), getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'))));
            $form->switchbool('unique', exmtrans("custom_column.options.unique"))
                ->help(exmtrans("custom_column.help.unique"));
            $form->text('default', exmtrans("custom_column.options.default"));
            $form->text('placeholder', exmtrans("custom_column.options.placeholder"));
            $form->text('help', exmtrans("custom_column.options.help"))->help(exmtrans("custom_column.help.help"));
            $form->number('use_label_flg', exmtrans("custom_column.options.use_label_flg"))
                ->help(exmtrans("custom_column.help.use_label_flg"))
                ->min(0)
                ->max(5)
                ->default(0)
                ->help(sprintf(exmtrans("custom_column.help.use_label_flg"), getManualUrl('column?id='.exmtrans('custom_column.options.use_label_flg'))))
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
                ->options(getTransArray(Define::CUSTOM_COLUMN_AVAILABLE_CHARACTERS_OPTIONS, 'custom_column.available_characters'))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => [ColumnType::TEXT]])])
                ->help(exmtrans("custom_column.help.available_characters"))
                ;
                    
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
                        $options = CustomTable::filterList()->pluck('table_view_name', 'id')->toArray();
                        return $options;
                    })
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::SELECT_TABLE])]);

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
                        'random32' => exmtrans("custom_column.options.auto_number_type_random32")]
                    )
                    ->attribute(['data-filtertrigger' =>true, 'data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::AUTO_NUMBER])]);

            // set manual
            $manual_url = getManualUrl('column#自動採番フォーマットのルール');
            $form->text('auto_number_format', exmtrans("custom_column.options.auto_number_format"))
                    ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'options_auto_number_type', 'value' => 'format'])])
                    ->help(sprintf(exmtrans("custom_column.help.auto_number_format"), $manual_url))
                ;

            // calc
            $custom_table = $this->custom_table;
            $form->valueModal('calc_formula', exmtrans("custom_column.options.calc_formula"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_CALC()])])
                ->help(exmtrans("custom_column.help.calc_formula"))
                ->text(function ($value) {
                    /////TODO:copy and paste
                    if (!isset($value)) {
                        return null;
                    }
                    // convert json to array
                    if (!is_array($value) && is_json($value)) {
                        $value = json_decode($value, true);
                    }

                    // set calc formula list
                    $symbols = [
                        'plus' => '＋',
                        'minus' => '－',
                        'times' => '×',
                        'div' => '÷',
                    ];

                    ///// get text
                    $texts = [];
                    foreach ($value as &$v) {
                        $val = array_get($v, 'val');
                        switch (array_get($v, 'type')) {
                            case 'dynamic':
                                $texts[] = CustomColumn::getEloquent(array_get($v, 'val'))->column_view_name ?? null;
                                break;
                            case 'symbol':
                                $texts[] = array_get($symbols, $val);
                                break;
                            case 'fixed':
                                $texts[] = $val;
                                break;
                        }
                    }
                    return implode(" ", $texts);
                })
                ->modalbody(function ($value) use ($id, $custom_table) {
                    /////TODO:copy and paste
                    // get other columns
                    // return $id is null(calling create fuction) or not match $id and row id.
                    $custom_columns = $custom_table->custom_columns->filter(function ($column) use ($id) {
                        return (!isset($id) || $id != array_get($column, 'id'))
                            && in_array(array_get($column, 'column_type'), ColumnType::COLUMN_TYPE_CALC());
                    })->toArray();
                    
                    if (!isset($value)) {
                        $value = [];
                    }
                    // convert json to array
                    if (!is_array($value) && is_json($value)) {
                        $value = json_decode($value, true);
                    }

                    // set calc formula list
                    $symbols = [
                        'plus' => '＋',
                        'minus' => '－',
                        'times' => '×',
                        'div' => '÷',
                    ];

                    ///// get text
                    foreach ($value as &$v) {
                        $val = array_get($v, 'val');
                        switch (array_get($v, 'type')) {
                            case 'dynamic':
                                $v['text'] = CustomColumn::getEloquent(array_get($v, 'val'))->column_view_name ?? null;
                                break;
                            case 'symbol':
                                $v['text'] = array_get($symbols, $val);
                                break;
                            case 'fixed':
                                $v['text'] = $val;
                                break;
                        }
                    }

                    return view('exment::custom-column.calc_formula_modal', [
                        'custom_columns' => $custom_columns,
                        'value' => $value,
                        'symbols' => $symbols,
                    ]);
                })
            ;

            // image, file, select
            // enable multiple
            $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'column_type', 'value' => ColumnType::COLUMN_TYPE_MULTIPLE_ENABLED()])]);
                })->disableHeader();

        $form->number('order', exmtrans("custom_column.order"));

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

        $form->footer(function ($footer) {
            // disable reset btn
            $footer->disableReset();
            // disable `View` checkbox
            $footer->disableViewCheck();
        });
        
        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use ($id, $form, $custom_table) {
            $tools->disableView();
            $tools->add((new Tools\GridChangePageMenu('column', $custom_table, false))->render());
        });
        return $form;
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

        // set custom form columns --------------------------------------------------
        $add_custom_view_flg = app('request')->input('add_custom_view_flg');
        if (boolval($add_custom_view_flg)) {
            $view = CustomView::getDefault($this->custom_table);
            
            // get order
            $order = $view->custom_view_columns()
                ->where('view_column_type', ViewColumnType::COLUMN)
                ->max('order') ?? 0;
            $order++;

            $custom_view_column = new CustomViewColumn;
            $custom_view_column->custom_view_id = $view->id;
            $custom_view_column->view_column_type = ViewColumnType::COLUMN;
            $custom_view_column->view_column_target = $model->id;
            $custom_view_column->order = $order;

            $custom_view_column->save();
        }
    }
}
