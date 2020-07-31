<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Middleware\Morph;
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
            case 'zip_password':
                $this->importZipPasswordTemplate();
                return;
            case 'workflow_mail_template':
                $this->importWorkflowTemplate();
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
                //$this->moveTemplateFolder();
                return;
            case 'remove_deleted_table_notify':
                $this->removeDeletedTableNotify();
                return;
            case 'revisionable_type':
                $this->patchRevisionableType();
                return;
            case 'parent_org_type':
                $this->patchParentOrg();
                return;
            case 'remove_deleted_column':
                $this->removeDeletedColumn();
                return;
            case 'remove_deleted_relation':
                $this->removeDeletedRelation();
                return;
            case 'chartitem_x_label':
                $this->patchDashboardBoxSummaryX();
                return;
            case 'back_slash_replace':
                $this->patchFileNameBackSlash();
                return;
            case 'remove_stored_revision':
                $this->removeStoredRevision();
                return;
            case 'login_type_sso':
                $this->setLoginTypeSso();
                // no break
            case 'patch_log_opelation':
                $this->patchLogOpelation();
                return;
            case 'plugin_all_user_enabled':
                $this->patchAllUserEnabled();
                return;
            case 'view_column_suuid':
                $this->patchViewColumnSuuid();
                // no break
            case 'patch_form_column_relation':
                $this->patchFormColumnRelation();
                return;
            case 'clear_form_column_relation':
                $this->clearFormColumnRelation();
                return;
            case 'patch_freeword_search':
                $this->setFreewordSearchOption();
                return;
            case 'init_custom_operation_type':
                $this->initCustomOperationType();
                return;
            case 'set_env':
                $this->setEnv();
                return;
            case 'patch_view_dashboard':
                $this->patchViewDashboard();
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
        // $columns = CustomColumn::all();
        // foreach ($columns as $column) {
        //     if (!array_has($column, 'options.use_label_flg')) {
        //         continue;
        //     }
        //     $column->setOption('use_label_flg', null);

        //     $column->save();
        // }
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
     * set freeword_search option true, if index-column
     *
     * @return void
     */
    protected function setFreewordSearchOption()
    {
        // get index columns
        $index_custom_columns = CustomColumn::indexEnabled()->get();
        
        foreach ($index_custom_columns as  $index_custom_column) {
            $index_custom_column->setOption('freeword_search', '1');
            $index_custom_column->save();
        }
    }
    
    /**
     * import mail template for 2factor
     *
     * @return void
     */
    protected function import2factorTemplate()
    {
        $this->patchMailTemplate([
            'verify_2factor',
            'verify_2factor_google',
            'verify_2factor_system',
        ]);
    }
    
    /**
     * import mail template for Zip Password
     *
     * @return void
     */
    protected function importZipPasswordTemplate()
    {
        $this->patchMailTemplate([
            'password_notify',
            'password_notify_header',
        ]);
    }
    
    /**
     * import mail template for workflow
     *
     * @return void
     */
    protected function importWorkflowTemplate()
    {
        $this->patchMailTemplate([
            'workflow_notify',
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
        $templates_data_path = exment_package_path('system_template');
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
                $view_columns[] = $view_column;
            }
            $aldata_view->custom_view_columns()->saveMany($view_columns);

            $view_sorts = [];
            foreach ($defaultView->custom_view_sorts as $custom_view_sort) {
                $view_sort = new CustomViewSort;
                $view_sort->custom_view_id = $aldata_view->id;
                $view_sort->view_column_target = array_get($custom_view_sort, 'view_column_target');
                $view_sort->sort = array_get($custom_view_sort, 'sort');
                $view_sort->priority = array_get($custom_view_sort, 'priority');
                $view_sorts[] = $view_sort;
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
        $locale = \App::getLocale();
        $templates_data_path = exment_package_path('system_template/data');
        $path = path_join($templates_data_path, $locale, "mail_template.xlsx");
        // if exists, execute data copy
        if (!\File::exists($path)) {
            $path = path_join($templates_data_path, "mail_template.xlsx");
            // if exists, execute data copy
            if (!\File::exists($path)) {
                return;
            }
        }

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
     * remove deleted table notify
     *
     * @return void
     */
    protected function removeDeletedTableNotify($mail_key_names = [])
    {
        // get custom table id
        $custom_table_ids = CustomTable::pluck('id');
        Notify::whereNotIn('custom_table_id', $custom_table_ids)->delete();
    }
    
    /**
     * move plugin folder
     *
     * @return void
     */
    protected function movePluginFolder()
    {
        $this->moveAppToStorageFolder('Plugins', Define::DISKNAME_PLUGIN_LOCAL);
    }
    
    // /**
    //  * move template folder
    //  *
    //  * @return void
    //  */
    // protected function moveTemplateFolder()
    // {
    //     return $this->moveAppToStorageFolder('Templates', Define::DISKNAME_TEMPLATE_SYNC);
    // }
    
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
    
    /**
     * patch revisionable type
     *
     * @return void
     */
    protected function patchRevisionableType()
    {
        $morphs = array_flip(Morph::getMorphs());
        $morphKeys = array_keys($morphs);

        // get revisions filtering
        $revisions = \Exceedone\Exment\Revisionable\Revision::whereIn('revisionable_type', $morphKeys)->get();

        foreach ($revisions as $revision) {
            $revisionable_type = array_get($morphs, $revision->revisionable_type);
            if (!isset($revisionable_type)) {
                continue;
            }

            $revision->revisionable_type = $revisionable_type;
            $revision->save();
        }
    }

    /**
     * Remove already deleted relation
     *
     * @return void
     */
    protected function removeDeletedRelation()
    {
        $custom_form_blocks = Model\CustomFormBlock::where('form_block_type', '<>', Enums\FormBlockType::DEFAULT)->get();
        foreach ($custom_form_blocks as $custom_form_block) {
            $is_related = Model\CustomRelation::where('parent_custom_table_id', $custom_form_block->custom_form->custom_table_id)
                ->where('child_custom_table_id', $custom_form_block->form_block_target_table_id)
                ->exists();
            if (!$is_related) {
                $custom_form_block->delete();
            }
        }
    }

    /**
     * Patch org select_table to organization
     *
     * @return void
     */
    protected function patchParentOrg()
    {
        $parent_organization = CustomColumn::getEloquent('parent_organization', SystemTableName::ORGANIZATION);
        if (!isset($parent_organization)) {
            return;
        }

        if ($parent_organization->column_type == ColumnType::ORGANIZATION) {
            return;
        }

        $parent_organization->column_type = ColumnType::ORGANIZATION;
        $parent_organization->save();
    }
    
    protected function removeDeletedColumn()
    {
        $classes = [
            Model\CustomViewColumn::class => ['type' => 'view_column_type', 'column' => 'view_column_target_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\CustomViewSort::class => ['type' => 'view_column_type', 'column' => 'view_column_target_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\CustomViewFilter::class => ['type' => 'view_column_type', 'column' => 'view_column_target_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\CustomViewSummary::class => ['type' => 'view_column_type', 'column' => 'view_column_target_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\CustomOperationColumn::class => ['type' => 'view_column_type', 'column' => 'view_column_target_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\Condition::class => ['type' => 'condition_type', 'column' => 'target_column_id', 'whereval' => Enums\ConditionType::COLUMN],
            Model\CustomFormColumn::class => ['type' => 'form_column_type', 'column' => 'form_column_target_id', 'whereval' => Enums\FormColumnType::COLUMN],
        ];

        foreach ($classes as $class => $val) {
            $items = $class::where($val['type'], $val['whereval'])
                ->get();

            foreach ($items as $item) {
                $column_id = $item->{$val['column']};
                if (!isset($column_id)) {
                    continue;
                }

                $custom_column = CustomColumn::getEloquent($column_id);
                if (isset($custom_column)) {
                    continue;
                }

                $item->delete();
            }
        }


        // remove custom_column_multisettings
        $items = Model\CustomColumnMulti::all();
        foreach ($items as $item) {
            $keys = ['table_label_id', 'unique1_id', 'unique2_id', 'unique3_id'];

            foreach ($keys as $key) {
                $column_id = array_get($item, "options.$key");
                if (!isset($column_id)) {
                    continue;
                }

                $custom_column = CustomColumn::getEloquent($column_id);
                if (isset($custom_column)) {
                    continue;
                }

                $item->delete();
            }
        }
    }

    /**
     * Patch chartX selection column to Define::CHARTITEM_LABEL
     *
     * @return void
     */
    protected function patchDashboardBoxSummaryX()
    {
        $dashboardBoxes = DashboardBox::whereNotNull('options->target_view_id')->where('options->chart_axisx', '<>', Define::CHARTITEM_LABEL)->get();
        foreach ($dashboardBoxes as $dashboardBox) {
            if (is_null($target_view_id = $dashboardBox->getOption('target_view_id'))) {
                continue;
            }

            if (is_null($view = CustomView::getEloquent($target_view_id))) {
                continue;
            }

            if ($view->view_kind_type != Enums\ViewKindType::AGGREGATE) {
                continue;
            }

            $dashboardBox->setOption('chart_axisx', Define::CHARTITEM_LABEL);
            $dashboardBox->save();
        }
    }

    /**
     * Patch data batchslash
     *
     * @return void
     */
    protected function patchFileNameBackSlash()
    {
        if (!canConnection() || !hasTable(SystemTableName::CUSTOM_TABLE)) {
            return;
        }

        $func = function ($query, $column_name, $setValueCallback) {
            // find file column contains
            $items = $query->where($column_name, 'LIKE', '%\\\\%')
                ->get();
            foreach ($items as $item) {
                $setValueCallback($item);
                $item->save();
            }
        };

        // modify custom table file column
        CustomTable::all()->each(function ($custom_table) use ($func) {
            $custom_columns = $custom_table->custom_columns_cache->filter(function ($column) {
                return ColumnType::isAttachment($column->column_type);
            });

            foreach ($custom_columns as $custom_column) {
                $func($custom_table->getValueModel()->query(), $custom_column->getQueryKey(), function ($item) use ($custom_column) {
                    $value = array_get($item, "value.{$custom_column->column_name}");
                    $item->setValue($custom_column->column_name, str_replace('\\', '/', $value));
                    $item->disable_saved_event(true);
                });
            }
        });

        // Modify system
        $query = System::query()->whereIn('system_name', [
            'site_favicon',
            'site_logo',
            'site_logo_mini'
        ]);

        $func($query, 'system_value', function ($item) {
            $value = array_get($item, "system_value");
            $item->system_value = str_replace('\\', '/', $value);
        });
    }

    /**
     * removeStoredRevision
     *
     * @return void
     */
    protected function removeStoredRevision()
    {
        if (!canConnection() || !hasTable(SystemTableName::CUSTOM_TABLE)) {
            return;
        }

        \DB::transaction(function () {
            // modify custom table file column
            CustomTable::all()->each(function ($custom_table) {
                $dbName = getDBTableName($custom_table);
                if (!hasTable($dbName)) {
                    return;
                }

                $query = \DB::table("$dbName as custom_value")
                    ->join(SystemTableName::REVISION, function ($join) use ($custom_table) {
                        $join->on('custom_value.id', 'revisions.revisionable_id');
                        $join->where('revisions.revisionable_type', $custom_table->table_name);
                    })->whereRaw('custom_value.created_at > (revisions.created_at + INTERVAL 240 SECOND)')
                    ->select(['revisions.id as revision_id', 'revisions.suuid', 'revisionable_type', 'revisionable_id', 'revision_no', 'new_value', 'revisions.created_at as revision_created_at', 'custom_value.created_at as custom_value_created_at']);

                $revisions = $query->get(); // ONLY WORK MYSQL

                foreach ($revisions as $revision) {
                    $r = (array)$revision;

                    // check data
                    $revision_id = array_get($r, 'revision_id');
                    if (is_nullorempty($revision_id)) {
                        continue;
                    }

                    $revision_created_at = \Carbon\Carbon::parse($r['revision_created_at']);
                    $custom_value_created_at = \Carbon\Carbon::parse($r['custom_value_created_at']);
                    if ($revision_created_at->gte($custom_value_created_at)) {
                        continue;
                    }

                    // delete target revision
                    \DB::table(SystemTableName::REVISION)->where('id', $revision_id)->delete();

                    // re-set revision_no
                    $reset_revisions = \DB::table(SystemTableName::REVISION)
                        ->where('revisionable_id', array_get($r, 'revisionable_id'))
                        ->where('revisionable_type', array_get($r, 'revisionable_type'))
                        ->orderBy('revision_no')
                        ->select(['id', 'revision_no'])
                        ->get();
                    
                    foreach ($reset_revisions as $index => $reset_revision) {
                        $reset_r = (array)$reset_revision;
                        \DB::table(SystemTableName::REVISION)->where('id', $reset_r['id'])->update(['revision_no' => ($index + 1)]);
                    }
                }
            });
        });
    }
    
    /**
     * setLoginType
     *
     * @return void
     */
    protected function setLoginTypeSso()
    {
        // patch login provider already logined.
        \DB::table('login_users')->whereNotNull('login_provider')->where('login_type', LoginType::PURE)->update(['login_type' => LoginType::OAUTH]);

        
        // update system value
        System::show_default_login_provider(config('exment.show_default_login_provider', true));


        // move to config to database
        $providers = stringToArray(config('exment.login_providers', ''));
        foreach ($providers as $provider) {
            $config = config("services.$provider");
            if (is_nullorempty($config)) {
                continue;
            }

            $oauth_provider_type = Enums\LoginProviderType::getEnum($provider);
            $oauth_provider_name = !isset($oauth_provider_type) ? $provider : null;
            $oauth_provider_type = isset($oauth_provider_type) ? $oauth_provider_type->getValue() : Enums\LoginProviderType::OTHER;
            
            // check has already executed
            if (Model\LoginSetting::where('login_type', Enums\LoginType::OAUTH)
            ->where('options->oauth_provider_type', $oauth_provider_type)
            ->where('options->oauth_provider_name', $oauth_provider_name)
            ->count() > 0) {
                continue;
            }

            $name = array_get($config, 'display_name') ?? pascalize($provider);

            $login_setting = new Model\LoginSetting([
                'login_view_name' => $name,
                'login_type' => Enums\LoginType::OAUTH,
                'active_flg' => true,
                'options' => [
                    'oauth_provider_type' => $oauth_provider_type,
                    'oauth_provider_name' => $oauth_provider_name,
                    'oauth_client_id' => array_get($config, 'client_id'),
                    'oauth_client_secret' => array_get($config, 'client_secret'),
                    'oauth_redirect_url' => array_get($config, 'redirect'),
                    'oauth_scope' => array_get($config, 'scope'),
                    'login_button_label' => exmtrans('login.login_button_format', ['display_name' => $name]),
                    'login_button_icon' => array_get($config, 'font_owesome'),
                    'login_button_background_color' => array_get($config, 'background_color'),
                    'login_button_background_color_hover' => array_get($config, 'background_color_hover'),
                    'login_button_font_color' => array_get($config, 'font_color'),
                    'login_button_font_color_hover' => array_get($config, 'font_color_hover'),

                    'mapping_user_column' => 'email',
                    'sso_jit' => false,
                    'jit_rolegroups' => [],
                    'update_user_info' => true,
                ]
            ]);

            $login_setting->save();
        }
    }
    
    /**
     * removeStoredRevision
     *
     * @return void
     */
    protected function patchLogOpelation()
    {
        if (!canConnection() || !hasTable('admin_operation_log')) {
            return;
        }

        $columns = \Exceedone\Exment\Middleware\LogOperation::getHideColumns();
        \Encore\Admin\Auth\Database\OperationLog::query()->chunk(1000, function ($logs) use ($columns) {
            foreach ($logs as $log) {
                $input = $log->input;
                if (is_nullorempty($input)) {
                    continue;
                }

                $isUpdate = false;
                $json = json_decode($input, true);
                if (is_nullorempty($json)) {
                    continue;
                }
                if(!is_array($json)){
                    continue;
                }
                foreach ($json as $key => &$value) {
                    if (!in_array($key, $columns)) {
                        continue;
                    }

                    if ($value == '***') {
                        continue;
                    }
                    
                    $value = '***';
                    $isUpdate = true;
                }

                if (!$isUpdate) {
                    continue;
                }

                $log->input = json_encode($json);
                $log->save();
            }
        });
    }
    
    /**
     * removeStoredRevision
     *
     * @return void
     */
    protected function patchAllUserEnabled()
    {
        if (!canConnection() || !hasTable('plugins')) {
            return;
        }

        Plugin::all()->each(function ($plugin) {
            if (!$plugin->matchPluginType(Enums\PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE())) {
                return;
            }

            $plugin->setOption('all_user_enabled', "1");
            $plugin->save();
        });
    }
    
    /**
     * patchFormColumnRelation
     *
     * @return void
     */
    protected function patchFormColumnRelation()
    {
        if (!canConnection() || !hasTable('custom_form_columns')) {
            return;
        }
        if (boolval(config('exment.select_relation_linkage_disabled', false))) {
            return;
        }
        
        CustomFormColumn::all()->each(function ($custom_form_column) {
            if ($custom_form_column->form_column_type != FormColumnType::COLUMN) {
                return true;
            }

            $custom_column = CustomColumn::getEloquent($custom_form_column->form_column_target_id);
            if (!isset($custom_column)) {
                return true;
            }

            if (!ColumnType::isSelectTable($custom_column['column_type'])) {
                return true;
            }

            if (!is_null($custom_form_column->getOption('relation_filter_target_column_id'))) {
                return true;
            }

            // get relation columns.
            $relationColumn = collect(Model\Linkage::getSelectTableLinkages($custom_column->custom_table_cache, false))
                ->filter(function ($c) use ($custom_column) {
                    return $c['searchType'] != Enums\SearchType::MANY_TO_MANY && $c['child_column']->id == $custom_column->id;
                })->first();

            if (!isset($relationColumn)) {
                return true;
            }

            $custom_form_column->setOption('relation_filter_target_column_id', $relationColumn['parent_column']->id);
            $custom_form_column->save();
        });
    }

    /**
     * clearFormColumnRelation
     *
     * @return void
     */
    protected function clearFormColumnRelation()
    {
        if (!canConnection() || !hasTable('custom_form_columns')) {
            return;
        }

        CustomFormColumn::all()->each(function ($custom_form_column) {
            if (is_null($custom_form_column->getOption('relation_filter_target_column_id'))) {
                return true;
            }

            $custom_form_column->forgetOption('relation_filter_target_column_id');
            $custom_form_column->save();
        });
    }

    /**
     * patchViewColumnSuuid
     *
     * @return void
     */
    protected function patchViewColumnSuuid()
    {
        if (!canConnection() || !hasTable('custom_view_columns')) {
            return;
        }

        $classes = [
            CustomViewColumn::class,
            CustomViewSummary::class,
        ];
        foreach ($classes as $c) {
            $c::all()->each(function ($v) {
                if (!is_nullorempty($v->suuid)) {
                    return true;
                }

                $v->suuid = short_uuid();
                $v->save();
            });
        }
    }
    
    /**
     * setEnv
     *
     * @return void
     */
    protected function setEnv()
    {
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
            return;
        }

        if (!boolval(System::initialized())) {
            return;
        }

        // write env
        try {
            EnvService::setEnv(['EXMENT_INITIALIZE' => 1]);
        }
        // if cannot write, nothing do
        catch (\Exception $ex) {
        } catch (\Throwable $ex) {
        }
    }

    /**
     * Initialize operation_type in custom_operations.
     *
     * @return void
     */
    protected function initCustomOperationType()
    {
        // remove "role" menu
        Model\CustomOperation::whereNull('operation_type')
            ->get()
            ->each(function ($custom_operation) {
                $custom_operation->update([
                    'operation_type' => [Enums\CustomOperationType::BULK_UPDATE],
                    'options' => ['button_label' => $custom_operation->operation_name],
                ]);

                $custom_operation->custom_operation_columns->each(function ($custom_operation_column) {
                    $custom_operation_column->setOption('operation_update_type', Enums\OperationUpdateType::DEFAULT)
                        ->save();
                });
            });
    }
        
    /**
     * setLoginType
     *
     * @return void
     */
    protected function patchViewDashboard()
    {
        if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
            return;
        }

        // update system value
        System::userdashboard_available(!boolval(config('exment.userdashboard_disabled', false)));
        System::userview_available(!boolval(config('exment.userview_disabled', false)));
    }
}
