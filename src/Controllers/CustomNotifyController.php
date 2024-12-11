<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;

class CustomNotifyController extends AdminControllerTableBase
{
    use HasResourceTableActions;
    use NotifyTrait;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("notify.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("notify.description"), 'fa-bell');
    }


    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['notify_view_name']));
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
        $grid = new Grid(new Notify());

        $grid->column('target_id', exmtrans("notify.notify_target"))->sortable()->display(function ($val) {
            $custom_table = CustomTable::getEloquent($val);
            if (isset($custom_table)) {
                return $custom_table->table_view_name ?? null;
            }
            if (isset($this->workflow_id)) {
                return Workflow::getEloquent($this->workflow_id)->workflow_view_name ?? null;
            }

            return null;
        });

        $this->setBasicGrid($grid);

        $grid->column('action_settings', exmtrans("notify.notify_action"))->sortable()->display(function ($val) {
            return collect($val)->map(function ($v) {
                $enum = NotifyAction::getEnum(array_get($v, 'notify_action'));
                return isset($enum) ? $enum->transKey('notify.notify_action_options') : null;
            })->filter()->unique()->implode(exmtrans('common.separate_word'));
        });

        $grid->column('active_flg', exmtrans("plugin.active_flg"))->sortable()->display(function ($val) {
            return \Exment::getTrueMark($val);
        })->escape(false);

        // filter only custom table user has permission custom table
        if (!\Exment::user()->isAdministrator()) {
            $custom_tables = CustomTable::filterList()->pluck('id')->toArray();
            $grid->model()->whereIn('target_id', $custom_tables);
        }
        $grid->model()->where('target_id', $this->custom_table->id)
            ->whereIn('notify_trigger', NotifyTrigger::CUSTOM_TABLES());

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('notify', $this->custom_table));
        });

        $custom_table = $this->custom_table;

        $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table) {
            $actions->disableView();

            $linker = (new Linker())
                ->url(admin_urls("notify/{$custom_table->table_name}/create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('notify.notify')));
            $actions->prepend($linker);
        });

        $this->setFilterGrid($grid, function ($filter) {
            $filter->equal('notify_trigger', exmtrans("notify.notify_trigger"))->select(function ($val) {
                return NotifyTrigger::transKeyArray("notify.notify_trigger_options");
            });

            $filter->equal('target_id', exmtrans("notify.target_id"))->select(function ($val) {
                return CustomTable::filterList()->pluck('table_view_name', 'id');
            });
        });

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @param $copy_id
     * @return Form|false|void
     */
    protected function form($id = null, $copy_id = null)
    {
        if (!$this->hasPermissionEdit($id)) {
            return;
        }

        $form = new Form(new Notify());
        $notify = Notify::find($id);
        if ($notify && !in_array($notify->notify_trigger, NotifyTrigger::CUSTOM_TABLES())) {
            Checker::error(exmtrans('common.message.wrongdata'));
            return false;
        }

        $custom_table = $this->custom_table;

        $form->internal('target_id')->default($this->custom_table->id);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table"))->default($this->custom_table->table_view_name);

        $this->setBasicForm($form, $notify);

        $form->exmheader(exmtrans('notify.header_trigger'))->hr();

        $form->select('notify_trigger', exmtrans("notify.notify_trigger"))
            ->options(NotifyTrigger::transKeyArrayFilter("notify.notify_trigger_options", NotifyTrigger::CUSTOM_TABLES()))
            ->required()
            ->disableClear()
            ->attribute([
                'data-filtertrigger' =>true,
                'data-changedata' => json_encode([
                    'getitem' =>
                        ['uri' => admin_url('notify/notifytrigger_template')]
                ])
            ])
            ->help(exmtrans("notify.help.notify_trigger"));

        $form->select('custom_view_id', exmtrans("notify.custom_view_id"))
            ->help(exmtrans("notify.help.custom_view_id"))
            ->options(function ($value, $field) use ($custom_table) {
                return $custom_table->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') == ViewKindType::FILTER;
                    })->pluck('view_view_name', 'id');
            });

        $form->embeds('trigger_settings', exmtrans("notify.trigger_settings"), function (Form\EmbeddedForm $form) use ($custom_table) {
            // Notify Time --------------------------------------------------
            $controller = $this;
            $form->select('notify_target_date', exmtrans("notify.notify_target_column"))
            ->options($custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'include_parent' => true,
                'include_system' => false,
                'ignore_multiple' => true,
                'ignore_many_to_many' => true,
                'column_type_filter' => function ($column) {
                    if ($column instanceof CustomColumn) {
                        return ColumnType::isDate($column->column_type);
                    } elseif (is_array($column) && array_has($column, 'type')) {
                        return array_get($column, 'type') == 'datetime';
                    }
                },
            ]))
            ->required()
            ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
            ->help(exmtrans("notify.help.trigger_settings"));

            $form->number('notify_day', exmtrans("notify.notify_day"))
                ->help(exmtrans("notify.help.notify_day"))
                ->min(0)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
            ;
            $form->select('notify_beforeafter', exmtrans("notify.notify_beforeafter"))
                ->options(NotifyBeforeAfter::transKeyArray('notify.notify_beforeafter_options'))
                ->default(NotifyBeforeAfter::BEFORE)
                ->required()
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_beforeafter") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more?id='.exmtrans('common.help.task_schedule_id'))));

            $form->number('notify_hour', exmtrans("notify.notify_hour"))
                ->min(0)
                ->max(23)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_hour"));

            // get checkbox
            $form->checkbox('notify_saved_trigger', exmtrans("notify.header_trigger"))
                ->help(exmtrans("notify.help.notify_trigger"))
                ->options(NotifySavedType::transArray('common'))
                ->default(NotifySavedType::arrays())
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::CREATE_UPDATE_DATA]])])
            ;

            $form->text('notify_button_name', exmtrans("notify.notify_button_name"))
                ->required()
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::BUTTON]])])
                ->rules("max:40");

            $form->switchbool('notify_myself', exmtrans("notify.notify_myself"))
            ->attribute([
                'data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::CREATE_UPDATE_DATA]]),
            ])
            ->default(false)
            ->help(exmtrans("notify.help.notify_myself"));
        })->disableHeader();

        $form->exmheader(exmtrans("notify.header_action"))->hr();

        $form->hasManyJson('action_settings', exmtrans("notify.action_settings"), function ($form) use ($notify, $custom_table) {
            $form->select('notify_action', exmtrans("notify.notify_action"))
            ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
            ->required()
            ->disableClear()
            ->attribute([
                'data-filtertrigger' =>true,
                'data-linkage' => json_encode([
                    'notify_action_target' => admin_urls('notify', $this->custom_table->table_name, 'notify_action_target'),
                ]),
            ])
            ->help(exmtrans("notify.help.notify_action"))
            ;

            $this->setActionForm($form, $notify, $custom_table);
        })->required()->disableHeader();

        $this->setMailTemplateForm($form, $notify);

        $this->setFooterForm($form, $notify);

        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->add(new Tools\CustomTableMenuButton('notify', $custom_table));
        });

        return $form;
    }



    public function notify_action_target(Request $request)
    {
        $options = NotifyService::getNotifyTargetColumns($this->custom_table, $request->get('q'), [
            'get_realtion_email' => true,
        ]);

        return $options;
    }

    protected function getTargetDateColumnOptions($custom_table_id, $table_name = null)
    {
        return CustomColumn::where('custom_table_id', $custom_table_id)
            ->whereIn('column_type', [ColumnType::DATE, ColumnType::DATETIME])
            ->get(['id', 'column_view_name as text'])
            ->map(function (&$item) use ($table_name) {
                if (isset($table_name)) {
                    $item['text'] = $table_name . ' : ' . $item['text'];
                }
                return $item;
            });
    }

    protected function getTargetColumnOptions($custom_table)
    {
        $custom_table = CustomTable::getEloquent($custom_table);

        if (!isset($custom_table)) {
            return [];
        }

        $options = $this->getTargetDateColumnOptions($custom_table->id);

        $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $custom_table->id)->get();
        foreach ($relations as $rel) {
            $parent = array_get($rel, 'parent_custom_table');
            $options = $options->merge($this->getTargetDateColumnOptions($parent->id, $parent->table_view_name));
        }

        $select_table_columns = $custom_table->getSelectTableColumns(null, true);
        foreach ($select_table_columns as $select_table_column) {
            $select_table = $select_table_column->column_item->getSelectTable();
            $options = $options->merge($this->getTargetDateColumnOptions($select_table->id, $select_table->table_view_name));
        }

        return $options->pluck('text', 'id');
    }

    public function getNotifyTriggerTemplate(Request $request)
    {
        $keyName = 'mail_template_id';
        $value = $request->input('value');

        // get mail key enum
        $enum = NotifyTrigger::getEnum($value);
        if (!isset($enum)) {
            return [$keyName => null];
        }

        // get mailKeyName
        $mailKeyName = $enum->getDefaultMailKeyName();
        if (!isset($mailKeyName)) {
            return [$keyName => null];
        }

        // get mail template
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', $mailKeyName)
            ->first();

        if (!isset($mail_template)) {
            return [$keyName => null];
        }

        return [
            $keyName => $mail_template->id
        ];
    }
}
