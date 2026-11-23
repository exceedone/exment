<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Form\Widgets\ModalForm;

class CustomCopyController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_copy.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_copy.description"), 'fa-copy');
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
        if (!$this->validateTableAndId(CustomCopy::class, $id, 'copy')) {
            return;
        }
        return parent::edit($request, $content, $tableKey, $id);
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
        $grid = new Grid(new CustomCopy());
        $grid->column('from_custom_table.table_view_name', exmtrans("custom_copy.from_custom_table_view_name"))->sortable();
        $grid->column('to_custom_table.table_view_name', exmtrans("custom_copy.to_custom_table_view_name"))->sortable();
        $grid->column('label', exmtrans("plugin.options.label"))->sortable()->display(function ($value) {
            // @phpstan-ignore-next-line
            return array_get($this, 'options.label');
        });

        if (isset($this->custom_table)) {
            $grid->model()->where('from_custom_table_id', $this->custom_table->id);
        }

        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(view('exment::custom-value.new-button-copy', [
                'url' => admin_urls('copy', $this->custom_table->table_name, 'newModal')
            ]));
            //$tools->append($this->createNewModal());
            /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\CustomTableMenuButton given */
            $tools->append(new Tools\CustomTableMenuButton('copy', $this->custom_table));
        });

        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableView();
        });

        // filter
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();

            $filter->equal('to_custom_table_id', exmtrans("custom_copy.to_custom_table_view_name"))->select(function () {
                return CustomTable::filterList()->pluck('table_view_name', 'id')->toArray();
            });

            $filter->exmwhere(function ($query, $input) {
                $query->where('options->label', 'LIKE', $input . '%');
            }, exmtrans("plugin.options.label"));
        });

        return $grid;
    }

    /**
     * get child table copy options.
     *
     * @return array|null child copy options
     */
    // @phpstan-ignore-next-line
    protected function getChildCopyOptions($to_table)
    {
        if (isset($to_table)) {
            $from_relations = $this->custom_table->custom_relations()->pluck('child_custom_table_id');
            $to_relations = $to_table->custom_relations()->pluck('child_custom_table_id');
            return CustomCopy::whereIn('from_custom_table_id', $from_relations->toArray())
              ->whereIn('to_custom_table_id', $to_relations->toArray())->get()
              ->filter(function ($item) {
                  return count($item->custom_copy_input_columns) == 0;
              })->mapWithKeys(function ($item) {
                  $from_name = $item->from_custom_table->table_view_name;
                  $to_name = $item->to_custom_table->table_view_name;
                  return [$item['id'] => exmtrans(
                      "custom_copy.options.child_copy_format",
                      $item['options']['label'],
                      $from_name,
                      $to_name
                  )];
              })->toArray();
        }
        return null;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    // @phpstan-ignore-next-line
    protected function form($id = null)
    {
        $form = new Form(new CustomCopy());
        $form->internal('from_custom_table_id')->default($this->custom_table->id);
        $form->display('from_custom_table.table_view_name', exmtrans("custom_copy.from_custom_table_view_name"))->default($this->custom_table->table_view_name);

        // get to item
        // if set $id, get from CustomCopy
        $request = request();
        // if set posted to_custom_table_id, get from posted data
        if ($request->has('to_custom_table_id')) {
            $to_table = CustomTable::getEloquent($request->get('to_custom_table_id'));
            $form->hidden('to_custom_table_id')->default($request->get('to_custom_table_id'));
        } elseif (isset($id)) {
            $copy = CustomCopy::getEloquent($id);
            $to_table = $copy->to_custom_table;
        }
        // if not set, get query
        else {
            $to_custom_table_suuid = $request->get('to_custom_table');
            $to_table = CustomTable::findBySuuid($to_custom_table_suuid);
        }

        if (isset($to_table)) {
            $form->display('to_custom_table.table_view_name', exmtrans("custom_copy.to_custom_table_view_name"))->default($to_table->table_view_name);
            $form->hidden('to_custom_table_id')->default($to_table->id);
        }

        $child_options = $this->getChildCopyOptions($to_table);

        // exmtrans "plugin". it's same value
        $form->embeds('options', exmtrans("plugin.options.header"), function ($form) use ($child_options) {
            $form->text('label', exmtrans("plugin.options.label"))->default(exmtrans("common.copy"))->rules("max:40");
            $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"))->default('fa-copy');
            $form->text('button_class', exmtrans("plugin.options.button_class"))->help(exmtrans("plugin.help.button_class"));
            $form->switchbool('use_on_create', exmtrans("custom_copy.options.use_on_create"))
                ->help(exmtrans("custom_copy.help.use_on_create"))
                ->default(0);
            $form->text('create_label', exmtrans("custom_copy.options.create_label"))
                ->help(exmtrans("custom_copy.help.create_label"))
                ->rules("max:40")
                ->attribute(['data-filter' => json_encode(['key' => 'options_use_on_create', 'value' => '1'])]);
            if (!empty($child_options)) {
                $form->select('child_copy', exmtrans("custom_copy.options.child_copy"))
                    ->help(exmtrans("custom_copy.help.child_copy"))->options($child_options);
            }
        })->disableHeader();

        ///// get from and to columns
        $custom_table = $this->custom_table;
        $from_custom_column_options = $custom_table->getColumnsSelectOptions([
            'append_table' => true,
            'include_system' => false,
            'ignore_attachment' => true,
        ]);
        $to_custom_column_options = $to_table ? $to_table->getColumnsSelectOptions([
            'append_table' => true,
            'include_system' => false,
            'ignore_attachment' => true,
        ]) : [];
        $copy_columns_field = $form->hasManyTable('custom_copy_columns', exmtrans("custom_copy.custom_copy_columns"), function ($form) use ($from_custom_column_options, $to_custom_column_options) {
            $form->select('from_column_target', exmtrans("custom_copy.from_custom_column"))
                ->options($from_custom_column_options)->required();
            $form->descriptionHtml('▶');
            $form->select('to_column_target', exmtrans("custom_copy.to_custom_column"))
                ->options($to_custom_column_options)->required();
            $form->hidden('copy_column_type')->default(CopyColumnType::DEFAULT);
        })->setTableWidth(10, 1)
        ->descriptionHtml(exmtrans("custom_copy.column_description"));

        // a new setting may start with the columns of the same name already paired (#1828)
        if (!isset($id) && isset($to_table) && boolval($request->get('match_columns'))) {
            $pairs = static::getSameNameColumnPairs($from_custom_column_options, $to_custom_column_options);
            if (count($pairs) > 0) {
                $copy_columns_field->setRelatedValue($pairs);
                admin_info(exmtrans('custom_copy.match_columns.button'), exmtrans('custom_copy.match_columns.message', count($pairs)));
            } else {
                admin_warning(exmtrans('custom_copy.match_columns.button'), exmtrans('custom_copy.match_columns.message_none'));
            }
        }

        ///// get input columns
        $form->hasManyTable('custom_copy_input_columns', exmtrans("custom_copy.custom_copy_input_columns"), function ($form) use ($to_custom_column_options) {
            $form->select('to_column_target', exmtrans("custom_copy.input_custom_column"))
                ->options($to_custom_column_options)->required();
            $form->hidden('copy_column_type')->default(CopyColumnType::INPUT);
        })->setTableWidth(10, 1)
        ->descriptionHtml(exmtrans("custom_copy.input_column_description"));

        $form->tools(function (Form\Tools $tools) use ($custom_table, $id, $to_table) {
            // @phpstan-ignore-next-line
            $tools->add(new Tools\CustomTableMenuButton('copy', $custom_table));

            // open the new setting again with the columns of the same name paired
            if (!isset($id) && isset($to_table)) {
                $tools->append(view('exment::tools.button', [
                    'href' => admin_urls_query('copy', $custom_table->table_name, 'create', [
                        'to_custom_table' => $to_table->suuid,
                        'match_columns' => 1,
                    ]),
                    'label' => exmtrans('custom_copy.match_columns.button'),
                    'icon' => 'fa-exchange',
                    'btn_class' => 'btn-default',
                    'attributes' => [
                        'onclick' => 'return confirm(' . json_encode(exmtrans('custom_copy.match_columns.confirm')) . ');',
                    ],
                ]));
            }
        });

        // validate before saving
        $form->saving(function (Form $form) use ($to_table) {
            if (!is_null($form->custom_copy_columns)) {
                $columns = collect($form->custom_copy_columns)
                    ->filter(function ($value) {
                        return $value[Form::REMOVE_FLAG_NAME] != 1;
                    })
                    ->pluck('to_column_target');
            } else {
                $columns = collect([]);
            }
            if (!is_null($form->custom_copy_input_columns)) {
                $columns = $columns->merge(collect($form->custom_copy_input_columns)
                    ->filter(function ($value) {
                        return $value[Form::REMOVE_FLAG_NAME] != 1;
                    })->pluck('to_column_target'));
            }
            $columns = $columns->map(function ($column) {
                return explode("?", $column)[0];
                ;
            });
            $required_columns = CustomColumn::where('custom_table_id', $to_table->id)->required()->pluck('id');
            $result = $required_columns->every(function ($required_column) use ($columns) {
                return $columns->contains($required_column);
            });
            if (!$result) {
                admin_toastr(exmtrans('custom_copy.message.to_custom_column_required'), 'error');
                return back()->withInput();
            }
        });
        return $form;
    }

    /**
     * The copy columns that pair each column of the copy-from table with the column
     * of the same name in the copy-to table.
     *
     * Made from the options the selects of the form offer, so a pair is always one
     * the user could have picked. Auto numbers are left out: the copy-to table
     * numbers its own records.
     *
     * @param array<mixed> $from_options
     * @param array<mixed> $to_options
     * @return array<int, array<string, mixed>>
     */
    public static function getSameNameColumnPairs(array $from_options, array $to_options): array
    {
        $to_keys = [];
        foreach (array_keys($to_options) as $key) {
            $column = static::getOptionColumn($key);
            if (isset($column) && !isset($to_keys[$column->column_name])) {
                $to_keys[$column->column_name] = $key;
            }
        }

        $pairs = [];
        foreach (array_keys($from_options) as $key) {
            $column = static::getOptionColumn($key);
            if (!isset($column) || !isset($to_keys[$column->column_name])) {
                continue;
            }
            $pairs[] = [
                'from_column_target' => $key,
                'to_column_target' => $to_keys[$column->column_name],
                'copy_column_type' => CopyColumnType::DEFAULT,
            ];
        }
        return $pairs;
    }

    /**
     * The custom column of a column option ("(column id)?table_id=..."), or null for
     * anything else and for auto numbers.
     *
     * @param int|string $key
     * @return CustomColumn|null
     */
    protected static function getOptionColumn($key): ?CustomColumn
    {
        $id = explode('?', (string)$key)[0];
        if (!is_numeric($id)) {
            return null;
        }
        $column = CustomColumn::getEloquent($id);
        if (!$column instanceof CustomColumn || $column->column_type == \Exceedone\Exment\Enums\ColumnType::AUTO_NUMBER) {
            return null;
        }
        return $column;
    }

    /**
     * Create new button for modal.
     */
    // @phpstan-ignore-next-line
    protected function newModal()
    {
        $table_name = $this->custom_table->table_name;
        $path = admin_urls('copy', $table_name, 'create');
        // create form fields
        $form = new ModalForm();
        $form->action($path);
        $form->method('GET');
        $form->modalHeader(trans('admin.setting'));

        $form->select('to_custom_table', exmtrans('custom_copy.to_custom_table_view_name'))
            ->options(function ($option) {
                return CustomTable::where('showlist_flg', true)->pluck('table_view_name', 'suuid');
            })
            ->setWidth(8, 3)
            ->required()
            ->help(exmtrans('custom_copy.help.to_custom_table_view_name'));

        $form->switchbool('match_columns', exmtrans('custom_copy.match_columns.button'))
            ->setWidth(8, 3)
            ->default(1)
            ->help(exmtrans('custom_copy.match_columns.help'));

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => trans('admin.setting')
        ]);

        // add button unreachable statement
//        return $form->render()->render();
    }

    /**
     * The rows update() and destroy() check to be of this table.
     *
     * @return array<mixed>
     */
    protected function resourceRowRule(): ?array
    {
        return [\Exceedone\Exment\Model\CustomCopy::class, \Exceedone\Exment\Enums\Permission::CUSTOM_TABLE];
    }
}
