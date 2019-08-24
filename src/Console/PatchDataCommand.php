<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Services\DataImportExport;
use Carbon\Carbon;

class PatchDataCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:patchdata {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Patch data if has bug';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $name = $this->argument("action");

        switch ($name) {
            case 'rmcomma':
                $this->removeDecimalComma();
                return;
            case 'use_label_flg':
                $this->modifyUseLabelFlg();
                return;
            case 'alter_index_hyphen':
                $this->reAlterIndexContainsHyphen();
                return;
            case '2factor':
                $this->import2factorTemplate();
                return;
            case 'system_flg_column':
                $this->patchSystemFlgColumn();
                return;
            case 'role_group':
                $this->roleToRoleGroup();
                return;
            case 'notify_saved':
                $this->updateSavedTemplate();
                return;
            case 'alldata_view':
                $this->copyViewColumnAllDataView();
                return;
            case 'init_column':
                $this->initOnlyCodeColumn();
                return;
            case 'move_plugin':
                $this->movePluginFolder();
                return;
            case 'move_template':
                $this->moveTemplateFolder();
                return;
        }

        $this->error('patch name not found.');
    }

    /**
     * Remove decimal comma
     *
     * @return void
     */
    protected function removeDecimalComma()
    {
        // get ColumnType is decimal or Currency
        $columns = CustomColumn::whereIn('column_type', ColumnType::COLUMN_TYPE_CALC())->get();

        foreach ($columns as $column) {
            $custom_table = $column->custom_table;

            // get value contains comma
            $dbTableName = \getDBTableName($custom_table);
            $custom_table->getValueModel()
                ->where("value->{$column->column_name}", 'LIKE', '%,%')
                ->withTrashed()
                ->chunk(1000, function ($commaValues) use ($column, $dbTableName) {
                    foreach ($commaValues as &$commaValue) {
                        // rmcomma
                        $v = array_get($commaValue, "value.{$column->column_name}");
                        $v = rmcomma($v);

                        \DB::table($dbTableName)->where('id', $commaValue->id)->update(["value->{$column->column_name}" => $v]);
                    }
                });
        }
    }
    
    /**
     * Modify Use Label Flg
     *
     * @return void
     */
    protected function modifyUseLabelFlg()
    {
        // move use_label_flg to custom_column_multi
        $use_label_flg_columns = CustomColumn::whereNotIn('options->use_label_flg', [0, "0"])->orderby('options->use_label_flg')->get();
        foreach ($use_label_flg_columns as $use_label_flg_column) {
            $custom_table = $use_label_flg_column->custom_table;

            // check exists
            $exists = $custom_table->table_labels()
                ->where('multisetting_type', 2)
                ->where('options->table_label_id', $use_label_flg_column->id)
                ->first();

            if (!isset($exists)) {
                $custom_table->table_labels()->save(
                    new CustomColumnMulti([
                        'multisetting_type' => 2,
                        'table_label_id' => $use_label_flg_column->id,
                        'priority' => $use_label_flg_column->getOption('use_label_flg'),
                    ])
                );
            }

            $use_label_flg_column->setOption('use_label_flg', null);
            $use_label_flg_column->save();
        }

        // remove use_label_flg property
        $columns = CustomColumn::all();
        foreach ($columns as $column) {
            if (!array_has($column, 'options.use_label_flg')) {
                continue;
            }
            $column->setOption('use_label_flg', null);

            $column->save();
        }
    }
    
    /**
     * re-alter Index Contains Hyphen
     *
     * @return void
     */
    protected function reAlterIndexContainsHyphen()
    {
        // get index contains hyphen
        $index_custom_columns = CustomColumn::indexEnabled()->where('column_name', 'LIKE', '%-%')->get();
        
        foreach ($index_custom_columns as  $index_custom_column) {
            $db_table_name = getDBTableName($index_custom_column->custom_table);
            $db_column_name = $index_custom_column->getIndexColumnName(false);
            $index_name = "index_$db_column_name";
            $column_name = $index_custom_column->column_name;

            \Schema::dropIndexColumn($db_table_name, $db_column_name, $index_name);
            \Schema::alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name);
        }
    }
    
    /**
     * import mail template for 2factor
     *
     * @return void
     */
    protected function import2factorTemplate()
    {
        return $this->patchMailTemplate([
            'verify_2factor',
            'verify_2factor_google',
            'verify_2factor_system',
        ]);
    }
    
    /**
     * update mail template for 2factor
     *
     * @return void
     */
    protected function updateSavedTemplate()
    {
        $this->patchMailTemplate([MailKeyName::DATA_SAVED_NOTIFY]);

        // add Notify update options
        $notifies = Notify::where('notify_trigger', NotifyTrigger::CREATE_UPDATE_DATA)
            ->get();
        
        foreach ($notifies as $notify) {
            $notify_saved_trigger = array_get($notify, 'trigger_settings.notify_saved_trigger');
            if (!isset($notify_saved_trigger)) {
                $trigger_settings = $notify->trigger_settings;
                $trigger_settings['notify_saved_trigger'] = NotifySavedType::arrays();
                $notify->trigger_settings = $trigger_settings;

                $notify->save();
            }
        }
    }
    
    /**
     * system flg patch
     *
     * @return void
     */
    protected function patchSystemFlgColumn()
    {
        // get vendor folder
        $templates_data_path = base_path() . '/vendor/exceedone/exment/system_template';
        $configPath = "$templates_data_path/config.json";

        $json = json_decode(\File::get($configPath), true);

        // re-loop columns. because we have to get other column id --------------------------------------------------
        foreach (array_get($json, "custom_tables", []) as $table) {
            // find tables. --------------------------------------------------
            $obj_table = CustomTable::getEloquent(array_get($table, 'table_name'));
            // get columns. --------------------------------------------------
            if (array_key_exists('custom_columns', $table)) {
                foreach (array_get($table, 'custom_columns') as $column) {
                    if (boolval(array_get($column, 'system_flg'))) {
                        $obj_column = CustomColumn::getEloquent(array_get($column, 'column_name'), $obj_table);
                        if (!isset($obj_column)) {
                            continue;
                        }
                        $obj_column->system_flg = true;
                        $obj_column->save();
                    }
                }
            }
        }
    }

    /**
     * Move (system) role to role group
     *
     * @return void
     */
    protected function roleToRoleGroup()
    {
        $this->patchSystemAuthoritable();
        $this->patchValueAuthoritable();
        $this->updateRoleMenu();
    }

    /**
     * Copy custom view default to alldata
     *
     * @return void
     */
    protected function copyViewColumnAllDataView()
    {
        // get default view counts
        $defaultViews = CustomView::where('view_kind_type', ViewKindType::DEFAULT)
            ->where('default_flg', true)
            ->with(['custom_view_columns', 'custom_view_sorts'])
            ->get();

        foreach ($defaultViews as $defaultView) {
            // if not found alldata view in same custom table, create
            $alldata_view_count = CustomView
                ::where('view_kind_type', ViewKindType::ALLDATA)
                ->where('custom_table_id', $defaultView->custom_table_id)
                ->count();

            if ($alldata_view_count > 0) {
                continue;
            }

            $aldata_view = CustomView::createDefaultView($defaultView->custom_table_id);

            $view_columns = [];
            foreach ($defaultView->custom_view_columns as $custom_view_column) {
                $view_column = new CustomViewColumn;
                $view_column->custom_view_id = $aldata_view->id;
                $view_column->view_column_target = array_get($custom_view_column, 'view_column_target');
                $view_column->order = array_get($custom_view_column, 'order');
                array_push($view_columns, $view_column);
            }
            $aldata_view->custom_view_columns()->saveMany($view_columns);

            $view_sorts = [];
            foreach ($defaultView->custom_view_sorts as $custom_view_sort) {
                $view_sort = new CustomViewSort;
                $view_sort->custom_view_id = $aldata_view->id;
                $view_sort->view_column_target = array_get($custom_view_sort, 'view_column_target');
                $view_sort->sort = array_get($custom_view_sort, 'sort');
                $view_sort->priority = array_get($custom_view_sort, 'priority');
                array_push($view_sorts, $view_sort);
            }
            $aldata_view->custom_view_sorts()->saveMany($view_sorts);
        }
    }



    protected function patchSystemAuthoritable()
    {
        if (!\Schema::hasTable('system_authoritable')) {
            return;
        }

        ///// move system admin to System
        $system_authoritable = \DB::table('system_authoritable')
        ->where('morph_type', 'system')
        ->where('role_id', 1)
        ->get();

        $users = [];
        foreach ($system_authoritable as $s) {
            $item = (array)$s;
            if (array_get($item, 'related_type') == SystemTableName::USER) {
                $users[] = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(array_get($item, 'related_id'))->toArray();
            } else {
                $users = array_merge(
                    $users,
                    CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel(array_get($item, 'related_id'))
                        ->users->toArray()
                );
            }
        }

        $users = collect($users)->filter()->map(function ($user) {
            return array_get($user, 'id');
        })->toArray();

        // set System user's array
        $system_admin_users = System::system_admin_users();
        $system_admin_users = array_merge($system_admin_users, $users);
        System::system_admin_users(array_unique($system_admin_users));
    }
    
    protected function patchValueAuthoritable()
    {
        if (!\Schema::hasTable('roles') || !\Schema::hasTable('value_authoritable') || !\Schema::hasTable(CustomValueAuthoritable::getTableName())) {
            return;
        }
        
        ///// value_auth to custom_value_auth
        // get role info
        $valueRoles = \DB::table('roles')
            ->where('role_type', RoleType::VALUE)
            ->get();
        
        $editRoles = [];
        $viewRoles = [];
        foreach ($valueRoles as $valueRole) {
            $val = (array)$valueRole;
            $permissions = json_decode($val['permissions'], true);
            if (array_has($permissions, 'custom_value_edit')) {
                $editRoles[] = $val['id'];
            } else {
                $viewRoles[] = $val['id'];
            }
        }
        
        //get value_auth
        $value_authoritable = \DB::table('value_authoritable')
            ->get();
        $custom_value_authoritables = [];
        foreach ($value_authoritable as $v) {
            $val = (array)$v;
            if (in_array($val['role_id'], $editRoles)) {
                $authoritable_type = Permission::CUSTOM_VALUE_EDIT;
            } else {
                $authoritable_type = Permission::CUSTOM_VALUE_VIEW;
            }
            $custom_value_authoritables[] = [
                'parent_id' => $val['morph_id'],
                'parent_type' => $val['morph_type'],
                'authoritable_type' => $authoritable_type,
                'authoritable_user_org_type' => $val['related_type'],
                'authoritable_target_id' => $val['related_id'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_user_id' => $val['related_type'] == SystemTableName::USER ? $val['related_id'] : null,
                'updated_user_id' => $val['related_type'] == SystemTableName::USER ? $val['related_id'] : null,
            ];
        }

        \DB::table(CustomValueAuthoritable::getTableName())
            ->insert($custom_value_authoritables);
    }

    /**
     * Update role menu role to role_group
     *
     * @return void
     */
    protected function updateRoleMenu()
    {
        // remove "role" menu
        \DB::table('admin_menu')
            ->where('menu_type', 'system')
            ->where('menu_target', 'role')
            ->update([
                'uri' => 'role_group',
                'menu_name' => 'role_group',
                'menu_target' => 'role_group',
                'title' => exmtrans('menu.system_definitions.role_group'),
            ]);
    }

    /**
     * update init only option custom column
     *
     * @return void
     */
    protected function initOnlyCodeColumn()
    {
        $tableColumns = [
            SystemTableName::USER => 'user_code',
            SystemTableName::ORGANIZATION => 'organization_code',
            SystemTableName::MAIL_TEMPLATE => 'mail_key_name',
        ];

        foreach ($tableColumns as $table => $column) {
            $custom_column = CustomColumn::getEloquent($column, $table);
            if (!isset($custom_column)) {
                continue;
            }

            $custom_column->setOption('init_only', 1);
            $custom_column->save();
        }
    }
    
    /**
     * patch mail template
     *
     * @return void
     */
    protected function patchMailTemplate($mail_key_names = [])
    {
        // get vendor folder
        $templates_data_path = base_path() . '/vendor/exceedone/exment/system_template/data';
        $path = "$templates_data_path/mail_template.xlsx";

        $table_name = \File::name($path);
        $format = \File::extension($path);
        $custom_table = CustomTable::getEloquent($table_name);

        // execute import
        $service = (new DataImportExport\DataImportExportService())
            ->importAction(new DataImportExport\Actions\Import\CustomTableAction([
                'custom_table' => $custom_table,
                'filter' => ['value.mail_key_name' => $mail_key_names],
                'primary_key' => 'value.mail_key_name',
            ]))
            ->format($format);
        $service->import($path);
    }
    
    /**
     * move plugin folder
     *
     * @return void
     */
    protected function movePluginFolder()
    {
        return $this->moveAppToStorageFolder('Plugins', Define::DISKNAME_PLUGIN_LOCAL);
    }
    
    /**
     * move template folder
     *
     * @return void
     */
    protected function moveTemplateFolder()
    {
        return $this->moveAppToStorageFolder('Templates', Define::DISKNAME_TEMPLATE_LOCAL);
    }
    
    /**
     * move folder
     *
     * @return void
     */
    protected function moveAppToStorageFolder($pathName, $diskName)
    {
        // get app/$pathName folder
        $beforeFolder = app_path($pathName);
        if (!\File::isDirectory($beforeFolder)) {
            return;
        }
        
        $befores = scandir($beforeFolder);
        if (!is_array($befores)) {
            return;
        }

        foreach ($befores as $before) {
            if (in_array($before, [".",".."])) {
                continue;
            }
            $oldPath = path_join($beforeFolder, $before);
            \File::move($oldPath, getFullpath($before, $diskName));
        }
    }
}
