<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Model\Workflow;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\FormActionType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\ShareTrigger;
use Exceedone\Exment\Enums\SharePermission;
use Exceedone\Exment\Enums\CompareColumnType;
use Exceedone\Exment\Enums\ShowPositionType;

class CustomTableController extends AdminControllerBase
{
    use HasResourceActions;

    protected $exists = false;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("custom_table.header"), exmtrans("custom_table.header"), exmtrans("custom_table.description"), 'fa-table');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $content = $this->AdminContent($content);

        $row = new Row($this->grid());
        $row->class(['block_custom_table']);

        return $content->row($row);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomTable());
        $grid->column('table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('order', exmtrans("custom_table.order"))->sortable()->editable();

        $grid->tools(function (Grid\Tools $tools) {
            $tools->disableBatchActions();
            $tools->append(new Tools\CustomTableMenuAjaxButton());
        });

        $grid->disableExport();
        if (!\Exment::user()->hasPermission(Permission::CUSTOM_TABLE)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
            $actions->disableDelete();

            // add new multiple columns
            $linker = (new Linker())
                ->url(admin_urls('table', $actions->getKey(), 'edit').'?columnmulti=1')
                ->icon('fa-cogs')
                ->tooltip(exmtrans('custom_table.expand_setting'));
            $actions->append($linker);

            /** @var CustomTable $custom_table */
            $custom_table = $actions->row;

            // add custom column
            if ($custom_table->hasPermission(Permission::CUSTOM_TABLE)) {
                $linker = (new Linker())
                ->url(admin_urls('column', $custom_table->table_name))
                ->icon('fa-list')
                ->tooltip(exmtrans('change_page_menu.custom_column'));
                $actions->append($linker);
            }

            // add data
            if ($custom_table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                $linker = (new Linker())
                    /** @phpstan-ignore-next-line fix laravel-admin documentation */
                ->url($actions->row->getGridUrl())
                ->icon('fa-database')
                ->tooltip(exmtrans('change_page_menu.custom_value'));
                $actions->append($linker);
            }
        });

        // filter table --------------------------------------------------
        CustomTable::filterList($grid->model(), ['getModel' => false]);

        $grid->filter(function ($filter) {
            $filter->like('table_name', exmtrans("custom_table.table_name"));
            $filter->like('table_view_name', exmtrans("custom_table.table_view_name"));
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
        $form = new Form(new CustomTable());
        if (!isset($id)) {
            $form->text('table_name', exmtrans("custom_table.table_name"))
                ->required()
                ->rules("max:30|unique:".CustomTable::getTableName()."|regex:/".Define::RULES_REGEX_SYSTEM_NAME."/")
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
        } else {
            $form->display('table_name', exmtrans("custom_table.table_name"));
        }
        $form->text('table_view_name', exmtrans("custom_table.table_view_name"))
            ->required()
            ->rules("max:40")
            ->help(exmtrans('common.help.view_name'));
        $form->textarea('description', exmtrans("custom_table.field_description"))->rows(3);

        $form->number('order', exmtrans("custom_table.order"))->rules("integer")
            ->help(sprintf(exmtrans("common.help.order"), exmtrans('common.custom_table')));

        $form->exmheader(exmtrans('common.detail_setting'))->hr();

        $form->embeds('options', exmtrans("custom_column.options.header"), function ($form) {
            $form->color('color', exmtrans("custom_table.color"))->help(exmtrans("custom_table.help.color"));
            $form->icon('icon', exmtrans("custom_table.icon"))->help(exmtrans("custom_table.help.icon"));
            $form->switchbool('search_enabled', exmtrans("custom_table.search_enabled"))->help(exmtrans("custom_table.help.search_enabled"))->default("1")
            ;
            $form->switchbool('use_label_id_flg', exmtrans("custom_table.use_label_id_flg"))
                ->help(sprintf(exmtrans("custom_table.help.use_label_id_flg"), getManualUrl('column?id='.exmtrans('custom_column.options.use_label_flg'))))
                ->default("0")
            ;
            $form->switchbool('one_record_flg', exmtrans("custom_table.one_record_flg"))
                ->help(exmtrans("custom_table.help.one_record_flg"))
                ->default("0")
            ;
            $form->switchbool('attachment_flg', exmtrans("custom_table.attachment_flg"))->help(exmtrans("custom_table.help.attachment_flg"))
                ->default("1")
            ;
            $form->switchbool('comment_flg', exmtrans("custom_table.comment_flg"))
                ->help(exmtrans("custom_table.help.comment_flg"))
                ->default("1")
            ;
            $form->switchbool('revision_flg', exmtrans("custom_table.revision_flg"))->help(exmtrans("custom_table.help.revision_flg"))
                ->default("1")
                ->attribute(['data-filtertrigger' =>true])
            ;
            $form->number('revision_count', exmtrans("custom_table.revision_count"))->help(exmtrans("custom_table.help.revision_count"))
                ->min(0)
                ->max(500)
                ->default(config('exment.revision_count', 100))
                ->attribute(['data-filter' => json_encode(['key' => 'options_revision_flg', 'value' => "1"])])
            ;

            $form->exmheader(exmtrans('role_group.permission_setting'))->hr();

            $form->switchbool('all_user_editable_flg', exmtrans("custom_table.all_user_editable_flg"))->help(exmtrans("custom_table.help.all_user_editable_flg"))
                ->default("0");

            $form->switchbool('all_user_viewable_flg', exmtrans("custom_table.all_user_viewable_flg"))->help(exmtrans("custom_table.help.all_user_viewable_flg"))
                ->default("0");

            $form->switchbool('all_user_accessable_flg', exmtrans("custom_table.all_user_accessable_flg"))->help(exmtrans("custom_table.help.all_user_accessable_flg"))
                ->default("0");
        })->disableHeader();

        // if create table, show menulist
        if (!isset($id)) {
            $form->exmheader(exmtrans('common.create_only_setting'))->hr();

            $form->switchbool('add_parent_menu_flg', exmtrans("custom_table.add_parent_menu_flg"))->help(exmtrans("custom_table.help.add_parent_menu_flg"))
                ->default("0")
                ->attribute(['data-filtertrigger' =>true])
            ;
            $form->select('add_parent_menu', exmtrans("custom_table.add_parent_menu"))->help(exmtrans("custom_table.help.add_parent_menu"))
            ->options(function ($value) {
                $options = Menu::selectOptions();
                return $options;
            })
            ->attribute(['data-filter' => json_encode(['key' => 'add_parent_menu_flg', 'value' => '1'])]);
            ;
            $form->ignore('add_parent_menu');
            $form->ignore('add_parent_menu_flg');

            $form->switchbool('add_notify_flg', exmtrans("custom_table.add_notify_flg"))->help(exmtrans("custom_table.help.add_notify_flg"))
                ->default("0")
            ;
            $form->ignore('add_notify_flg');
        }

        // Role setting --------------------------------------------------
        $deleteButton = $this->confirmDeleteButton($id);

        $form->tools(function (Form\Tools $tools) use ($id, $deleteButton) {
            $custom_table = CustomTable::getEloquent($id);
            if (isset($custom_table) && $custom_table->disabled_delete) {
                $tools->disableDelete();
            } elseif (isset($deleteButton)) {
                $tools->disableDelete();
                $tools->prepend($deleteButton);
            }
            // if edit mode
            if ($id != null) {
                $model = CustomTable::getEloquent($id);
                $tools->append((new Tools\CustomTableMenuButton('table', $model, 'default_setting')));
            }
        });

        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->savedInTransaction(function (Form $form) {
            // create or drop index --------------------------------------------------
            $model = $form->model();
            // if has value 'add_parent_menu', add menu
            $this->addMenuAfterSaved($model);

            // if has value 'add_notify_flg', add notify
            $this->addNotifyAfterSaved($model);
        });

        if ($id != null) {
            $form->disableEditingCheck(false);
        }
        $form->saved(function (Form $form) {
            // create or drop index --------------------------------------------------
            $model = $form->model();
            /** @phpstan-ignore-next-line fix laravel-admin documentation */
            $model->createTable();

            // redirect custom column page
            if (!$this->exists) {
                /** @phpstan-ignore-next-line fix laravel-admin documentation */
                $table_name = CustomTable::getEloquent($model->id)->table_name;
                $custom_column_url = admin_urls('column', $table_name);

                admin_toastr(exmtrans('custom_table.help.saved_redirect_column'));
                return redirect($custom_column_url);
            }
        });

        return $form;
    }

    /**
     * Render `delete` button.
     *
     * @return ?string
     */
    protected function confirmDeleteButton($id = null)
    {
        if (is_null($id)) {
            return null;
        }

        $url = url(admin_urls('table', $id));
        $listUrl = url(admin_urls('table'));
        $keyword = Define::DELETE_CONFIRM_KEYWORD;
        $trans = [
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
            'delete'         => trans('admin.delete'),
            'delete_guide'   => sprintf(exmtrans('custom_table.help.delete_confirm_message'), $keyword),
            'delete_keyword' => exmtrans('custom_table.help.delete_confirm_error'),
        ];

        $class = uniqid();

        $script = <<<SCRIPT

$('.{$class}-delete').unbind('click').click(function() {
    Exment.CommonEvent.ShowSwal("$url", {
        title: "{$trans['delete_confirm']}",
        text: "{$trans['delete_guide']}",
        input: 'text',
        method: 'delete',
        confirm:"{$trans['confirm']}",
        cancel:"{$trans['cancel']}",
        redirect: "$listUrl",
        preConfirmValidate: function(input){
            if (input != "$keyword") {
                return "{$trans['delete_keyword']}";
            }

            return true;
        }
    });
});

SCRIPT;

        Admin::script($script);

        return <<<HTML
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="javascript:void(0);" class="btn btn-sm btn-danger {$class}-delete" title="{$trans['delete']}">
        <i class="fa fa-trash"></i><span class="hidden-xs">  {$trans['delete']}</span>
    </a>
</div>
HTML;
    }

    /**
     * Make a formMultiColumn.
     *
     * @return Form
     */
    protected function formMultiColumn($id = null)
    {
        $form = new Form(new CustomTable());
        $form->display('table_name', exmtrans("custom_table.table_name"));
        $form->display('table_view_name', exmtrans("custom_table.table_view_name"));

        $form->hidden('columnmulti')->default(1);
        $form->ignore('columnmulti');

        $custom_table = CustomTable::getEloquent($id);

        $form->hasManyTable('table_labels', exmtrans("custom_table.custom_column_multi.table_labels"), function ($form) use ($custom_table) {
            $form->select('table_label_id', exmtrans("custom_table.custom_column_multi.column_target"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'include_system' => false,
                ]));

            $form->hidden('priority')->default(1);
            $form->hidden('multisetting_type')->default(MultisettingType::TABLE_LABELS);
        })->setTableColumnWidth(10, 2)
        ->rowUpDown('priority')
        ->descriptionHtml(sprintf(exmtrans("custom_table.custom_column_multi.help.table_labels"), getManualUrl('table?id='.exmtrans('custom_table.custom_column_multi.table_labels'))));

        $form->hasManyTable('multi_uniques', exmtrans("custom_table.custom_column_multi.uniques"), function ($form) use ($custom_table) {
            $form->select('unique1', exmtrans("custom_table.custom_column_multi.unique1"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'include_system' => false,
                ]));
            $form->select('unique2', exmtrans("custom_table.custom_column_multi.unique2"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'include_system' => false,
                ]));
            $form->select('unique3', exmtrans("custom_table.custom_column_multi.unique3"))
                ->options($custom_table->getColumnsSelectOptions([
                    'include_system' => false,
                ]));
            $form->hidden('multisetting_type')->default(MultisettingType::MULTI_UNIQUES);
        })->setTableColumnWidth(4, 4, 3, 1)
        ->descriptionHtml(exmtrans("custom_table.custom_column_multi.help.uniques"));


        $form->hasManyTable('compare_columns', exmtrans("custom_table.custom_column_multi.compare_columns"), function ($form) use ($custom_table) {
            $form->select('compare_column1_id', exmtrans("custom_table.custom_column_multi.compare_column1_id"))->required()
                ->options($custom_table->getColumnsSelectOptions([
                    'include_system' => false,
                ]));
            $form->select('compare_type', exmtrans("custom_table.custom_column_multi.compare_type"))->required()
                ->options(function () {
                    $options = FilterOption::FILTER_OPTIONS()[FilterType::COMPARE];
                    return collect($options)->map(function ($option) {
                        return ['id' => $option['id'], 'label' => exmtrans("custom_table.custom_column_multi.filter_condition_compare_options.{$option['name']}")];
                    })->pluck('label', 'id');
                });
            $form->select('compare_column2_id', exmtrans("custom_table.custom_column_multi.compare_column2_id"))->required()
                ->options($this->getColumnsSelectOptions($custom_table, [
                    'include_system' => false,
                ]));
            $form->hidden('multisetting_type')->default(MultisettingType::COMPARE_COLUMNS);
        })->setTableColumnWidth(4, 3, 4, 1)
        ->descriptionHtml(exmtrans("custom_table.custom_column_multi.help.compare_columns"));


        // if not master, share setting
        if (!in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
            $manualUrl = getManualUrl('table?id=' . exmtrans('custom_table.custom_column_multi.share_settings'));
            $form->hasManyTable('share_settings', exmtrans("custom_table.custom_column_multi.share_settings"), function ($form) use ($custom_table) {
                $form->multipleSelect('share_trigger_type', exmtrans("custom_table.custom_column_multi.share_trigger_type"))->required()
                    ->options(ShareTrigger::transKeyArray("custom_table.custom_column_multi.share_trigger_type_options"));
                $form->select('share_column_id', exmtrans("custom_table.custom_column_multi.share_column_id"))->required()
                    ->options($custom_table->getUserOrgColumnsSelectOptions(['index_enabled_only' => false]));
                $form->select('share_permission', exmtrans("custom_table.custom_column_multi.share_permission"))->required()
                    ->options(SharePermission::transKeyArray("custom_table.custom_column_multi.share_permission_options"));
                $form->hidden('multisetting_type')->default(MultisettingType::SHARE_SETTINGS);
            })->setTableColumnWidth(3, 5, 3, 1)
            ->descriptionHtml(exmtrans("custom_table.custom_column_multi.help.share_settings") . '<br/>' . exmtrans('common.help.more_help_here', $manualUrl));
        }


        $form->embeds('options', exmtrans("custom_table.custom_column_multi.options_label"), function ($form) use ($custom_table) {
            if (!in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
                $manualUrl = getManualUrl('table?id=' . exmtrans('custom_table.custom_column_multi.share_settings'));
                $form->switchbool('share_setting_sync', exmtrans("custom_table.custom_column_multi.share_setting_sync"))
                    ->help(exmtrans("custom_table.custom_column_multi.help.share_setting_sync") . exmtrans('common.help.more_help_here', $manualUrl))
                    ->default('0')
                ;
            }

            $form->checkbox('form_action_disable_flg', exmtrans("custom_table.custom_column_multi.form_action_disable_flg"))
                ->help(exmtrans("custom_table.custom_column_multi.help.form_action_disable_flg"))
                ->options(FormActionType::transArray('custom_table.custom_column_multi.form_action_options'))
            ;
            $form->select('system_values_pos', exmtrans("system.system_values_pos"))
                ->default(ShowPositionType::DEFAULT)
                ->options(ShowPositionType::transArray("system.system_values_pos_options"))
                ->help(exmtrans("system.help.system_values_pos"))
            ;

            if (boolval(config('exment.expart_mode', false))) {
                $form->text('table_label_format', exmtrans("custom_table.custom_column_multi.table_label_format"))
                ->rules("max:200")
                ->help(sprintf(exmtrans("custom_table.custom_column_multi.help.table_label_format"), getManualUrl('table?id='.exmtrans('custom_table.custom_column_multi.table_label_format'))));
            }
        });

        $form->tools(function (Form\Tools $tools) use ($id) {
            $tools->disableDelete();

            // if edit mode
            if ($id != null) {
                $model = CustomTable::getEloquent($id);
                $tools->append((new Tools\CustomTableMenuButton('table', $model, 'expand_setting')));
            }
        });

        $form->disableEditingCheck(false);
        $form->saved(function (Form $form) {
            if (request()->get('after-save') != '1') {
                return;
            }

            $model = $form->model();
            admin_toastr(trans('admin.update_succeeded'));
            /** @phpstan-ignore-next-line fix laravel-admin documentation */
            return redirect(admin_urls_query('table', $model->id, 'edit', ['columnmulti' => 1, 'after-save' => 1]));
        });

        return $form;
    }

    /**
     * get columns select options.include system date
     * @param CustomTable $custom_table
     * @param array $selectOptions
     * @return array|mixed[]
     */
    protected function getColumnsSelectOptions($custom_table, $selectOptions = [])
    {
        $options = collect(CompareColumnType::transArray('custom_table.custom_column_multi.compare_column_options'))
            ->mapWithKeys(function ($val, $key) {
                return [$key => "**{$val}"];
            })->toArray();
        return $options + $custom_table->getColumnsSelectOptions($selectOptions);
    }

    /**
     * Edit interface.
     *
     * @param Request $request
     * @param Content $content
     * @param $id
     * @return Content|void
     */
    public function edit(Request $request, Content $content, $id)
    {
        if (!$this->validateTable($id, Permission::CUSTOM_TABLE)) {
            return;
        }

        if ($request->has('columnmulti')) {
            return $this->AdminContent($content)->body($this->formMultiColumn($id)->edit($id));
        }

        return parent::edit($request, $content, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        if (request()->has('columnmulti')) {
            return $this->formMultiColumn($id)->update($id);
        }

        return $this->form($id)->update($id);
    }

    /**
     * add menu after saved
     */
    protected function addMenuAfterSaved($model)
    {
        // if has value 'add_parent_menu', add menu
        if (!app('request')->has('add_parent_menu_flg') || !app('request')->has('add_parent_menu')) {
            return;
        }

        $add_parent_menu_flg = app('request')->input('add_parent_menu_flg');
        if (!boolval($add_parent_menu_flg)) {
            return;
        }

        $add_parent_menu = app('request')->input('add_parent_menu');
        if (!isset($add_parent_menu)) {
            return;
        }

        // get order
        $order = Menu::where('parent_id', $add_parent_menu)->max('order');
        if (!isset($order)) {
            $order = 0;
        }
        $order++;

        // insert
        Menu::insert([
            'parent_id' => $add_parent_menu,
            'order' => $order,
            'title' => $model->table_view_name,
            'icon' => $model->getOption('icon'),
            'uri' => $model->table_name,
            'menu_type' => MenuType::TABLE,
            'menu_name' => $model->table_name,
            'menu_target' => $model->id,
        ]);
    }

    /**
     * add notofy after saved
     */
    protected function addNotifyAfterSaved($model)
    {
        // if has value 'add_parent_menu', add menu
        if (!app('request')->has('add_notify_flg')) {
            return;
        }

        $add_notify_flg = app('request')->input('add_notify_flg');
        if (!boolval($add_notify_flg)) {
            return;
        }

        // get mail template
        $mail_template_id = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::DATA_SAVED_NOTIFY)
            ->first()
            ->id;

        // insert
        $notify = new Notify();
        $notify->notify_view_name = exmtrans('notify.notify_trigger_options.create_update_data');
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $notify->target_id = $model->id;
        $notify->mail_template_id = $mail_template_id;
        $notify->trigger_settings = [
            'notify_saved_trigger' =>  NotifySavedType::arrays()
        ];
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' =>  [NotifyActionTarget::HAS_ROLES]
        ]];
        $notify->save();
    }

    /**
     * validate before delete.
     * @param int|string $id
     */
    protected function validateDestroy($id)
    {
        return CustomTable::validateDestroy($id);
    }


    /**
     * Showing menu modal
     *
     * @param Request $request
     * @param string|int|null $id
     * @return Response
     */
    public function menuModal(Request $request, $id)
    {
        $tool = new Tools\CustomTableMenuAjaxButton();
        $tool->id($id);

        return getAjaxResponse([
            'body'  => $tool->ajaxHtml(),
            'title' => exmtrans("change_page_menu.change_page_label"),
            'showSubmit' => false,
        ]);
    }
}
