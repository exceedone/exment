<?php

namespace Exceedone\Exment\Services\TemplateImportExport;

use Illuminate\Support\Facades\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\DashboardType;
use ZipArchive;

/**
 * Export Template
 */
class TemplateExporter
{
    /**
     * Create template from this system .
     */
    // @phpstan-ignore-next-line
    public static function exportTemplate($template_name, $template_view_name, $description, $thumbnail, $options = [])
    {
        // set options
        $options = array_merge([
            'export_target' => [],
            'target_tables' => [],
            'zip_name' => null,
        ], $options);

        // set config info
        $config = static::getExportData($template_name, $template_view_name, $description, $options);
        // set language info
        $lang = static::getExportData($template_name, $template_view_name, $description, $options, true);

        // create ZIP file --------------------------------------------------
        $tmpdir = \Exment::getTmpFolderPath('template', false);
        $tmpFulldir = getFullpath($tmpdir, Define::DISKNAME_ADMIN_TMP, true);
        \Exment::makeDirectory($tmpFulldir);
        $tmpfilename = make_uuid();

        $zip = new ZipArchive();
        $zipfilename = short_uuid().'.zip';
        $zipfillpath = path_join($tmpFulldir, $zipfilename);
        if ($zip->open($zipfillpath, ZipArchive::CREATE) !== true) {
            //TODO:error
        }

        // add thumbnail
        if (isset($thumbnail)) {
            // save thumbnail
            $thumbnail_dir = path_join($tmpdir, short_uuid());
            $thumbnail_dirpath = getFullpath($thumbnail_dir, Define::DISKNAME_ADMIN_TMP);

            $thumbnail_name = 'thumbnail.' . $thumbnail->extension();
            $thumbnail_path = $thumbnail->store($thumbnail_dir, Define::DISKNAME_ADMIN_TMP);
            $thumbnail_fullpath = getFullpath($thumbnail_path, Define::DISKNAME_ADMIN_TMP);
            $zip->addFile($thumbnail_fullpath, $thumbnail_name);

            $config['thumbnail'] = $thumbnail_name;
        }

        // add config array
        $locale = \App::getLocale();
        // @phpstan-ignore-next-line
        $zip->addFromString('config.json', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // @phpstan-ignore-next-line
        $zip->addFromString("lang/$locale/lang.json", json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        // isset $thumbnail_fullpath, remove
        if (isset($thumbnail_dirpath)) {
            File::deleteDirectory($thumbnail_dirpath);
        }
        // create response
        $filename = ($options['zip_name'] ?? $template_name).'.zip';
        $response = response()->download($zipfillpath, $filename)->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * get export data array
     */
    // @phpstan-ignore-next-line
    public static function getExportData($template_name, $template_view_name, $description, $options = [], $is_lang = false)
    {
        $config = [];

        if (!$is_lang) {
            $config['template_name'] = $template_name;

            // get version
            list($latest, $current) = \Exment::getExmentVersion();
            if (isset($current)) {
                $config['version'] = $current;
            }
        }
        $config['template_view_name'] = $template_view_name;
        $config['description'] = $description;

        ///// set config info
        if (in_array(TemplateExportTarget::TABLE, $options['export_target'])) {
            static::setTemplateTable($config, $options['target_tables'], $is_lang);
        }
        if (in_array(TemplateExportTarget::MENU, $options['export_target'])) {
            static::setTemplateMenu($config, $options['target_tables'], $is_lang);
        }
        if (in_array(TemplateExportTarget::DASHBOARD, $options['export_target'])) {
            static::setTemplateDashboard($config, $is_lang);
        }
        if (in_array(TemplateExportTarget::ROLE_GROUP, $options['export_target'])) {
            static::setTemplateRole($config, $is_lang);
        }
        if (in_array(TemplateExportTarget::PUBLIC_FORM, $options['export_target'])) {
            static::setTemplatePublicForm($config, array_get($options, 'public_form_uuid'), $is_lang);
        }
        if (in_array(TemplateExportTarget::WORKFLOW, $options['export_target'])) {
            static::setTemplateWorkflow($config, $options['target_tables'] ?? [], $is_lang);
        }
        if (in_array(TemplateExportTarget::NOTIFY, $options['export_target'])) {
            static::setTemplateNotify($config, $options['target_tables'] ?? [], $is_lang);
        }

        return $config;
    }

    /**
     * set table info to config
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateTable(&$config, $target_tables, $is_lang = false)
    {
        // get customtable and columns --------------------------------------------------
        $custom_tables = CustomTable::filterList(null, ['with' => ['custom_columns']]);

        $configTables = [];
        foreach ($custom_tables as $custom_table) {
            if (count($target_tables) > 0 && !in_array($custom_table['table_name'], $target_tables)) {
                continue;
            }
            $configTables[] = $custom_table->getTemplateExportItems($is_lang);
        }
        $config['custom_tables'] = $configTables;

        // get relations --------------------------------------------------
        $custom_relations = CustomRelation::with('parent_custom_table')
            ->with('child_custom_table')
            ->get();
        $configRelations = [];
        foreach ($custom_relations as $custom_relation) {
            if (count($target_tables) > 0 && !in_array(array_get($custom_relation, 'parent_custom_table.table_name'), $target_tables)) {
                continue;
            }
            $configRelations[] = $custom_relation->getTemplateExportItems($is_lang);
        }
        $config['custom_relations'] = $configRelations;

        // get forms --------------------------------------------------
        $custom_forms = CustomForm::with('custom_form_blocks')
            ->with('custom_table')
            ->with('custom_form_blocks.custom_form_columns')
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->get();
        $configForms = [];
        foreach ($custom_forms as $custom_form) {
            if (count($target_tables) > 0 && !in_array(array_get($custom_form, 'custom_table.table_name'), $target_tables)) {
                continue;
            }
            $form = $custom_form->getTemplateExportItems($is_lang);
            $configForms[] = $form;
        }
        $config['custom_forms'] = $configForms;

        // get views --------------------------------------------------
        $custom_views = CustomView::with('custom_view_columns')
            ->with('custom_view_filters')
            ->with('custom_view_sorts')
            ->with('custom_view_summaries')
            ->with('custom_view_grid_filters')
            ->with('custom_view_columns.custom_table')
            ->with('custom_view_filters.custom_table')
            ->with('custom_view_sorts.custom_table')
            ->with('custom_view_summaries.custom_table')
            ->where('view_type', ViewType::SYSTEM)
            ->get();
        $configViews = [];
        foreach ($custom_views as $custom_view) {
            if (count($target_tables) > 0 && !in_array(array_get($custom_view, 'custom_table.table_name'), $target_tables)) {
                continue;
            }
            $configViews[] = $custom_view->getTemplateExportItems($is_lang);
        }
        $config['custom_views'] = $configViews;

        // get copies --------------------------------------------------
        $custom_copies = CustomCopy::with('custom_copy_columns')
            ->get();
        $configCopies = [];
        foreach ($custom_copies as $custom_copy) {
            if (count($target_tables) > 0 && !in_array(array_get($custom_copy, 'from_custom_table.table_name'), $target_tables)) {
                continue;
            }
            $configCopies[] = $custom_copy->getTemplateExportItems($is_lang);
        }
        $config['custom_copies'] = $configCopies;
    }

    /**
     * set menu info to config
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateMenu(&$config, $target_tables, $is_lang = false)
    {
        // get menu --------------------------------------------------
        $menuTree = (new Menu())->toTree(); // menutree:hierarchy
        $menus = [];

        // loop for menutree
        foreach ($menuTree as $menu) {
            // looping and get menu item
            $menus = array_merge($menus, static::getTemplateMenuItems($menu, $target_tables, $is_lang));
        }
        $config['admin_menu'] = $menus;
    }

    /**
     * set dashboard info to config
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateDashboard(&$config, $is_lang = false)
    {
        // get dashboards --------------------------------------------------
        $dashboards = Dashboard::with('dashboard_boxes')
            ->where('dashboard_type', DashboardType::SYSTEM)
            ->get();
        $configDashboards = [];
        foreach ($dashboards as $dashboard) {
            $configDashboards[] = $dashboard->getTemplateExportItems($is_lang);
        }
        $config['dashboards'] = $configDashboards;
    }

    /**
     * set Role info to config
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateRole(&$config, $is_lang = false)
    {
        // Get Roles --------------------------------------------------
        $roles = RoleGroup::all();
        $configRoles = [];

        foreach ($roles as $role) {
            $configRoles[] = $role->getTemplateExportItems($is_lang);
        }
        $config['roles'] = $configRoles;
    }


    /**
     * Export public form
     */
    // @phpstan-ignore-next-line
    protected static function setTemplatePublicForm(&$config, $public_form_uuid, $is_lang = false)
    {
        $public_form = PublicForm::getPublicFormByUuid($public_form_uuid, true);
        if (!$public_form) {
            return;
        }
        $config['public_form'] = $public_form->getTemplateExportItems($is_lang);
    }


    /**
     * Export workflows (with workflow_tables, workflow_statuses, workflow_actions and workflow_authorities as children).
     * If target_tables is not empty, only export workflows attached to those tables.
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateWorkflow(&$config, $target_tables = [], $is_lang = false)
    {
        $workflows = Workflow::with([
            'workflow_tables',
            'workflow_tables.custom_table',
            'workflow_statuses',
            'workflow_actions',
        ])->get();

        $configWorkflows = [];
        foreach ($workflows as $workflow) {
            if (count($target_tables) > 0) {
                $attached = collect($workflow->workflow_tables)
                    ->pluck('custom_table.table_name')
                    ->filter()
                    ->intersect($target_tables);
                if ($attached->isEmpty()) {
                    continue;
                }
            }
            $configWorkflows[] = $workflow->getTemplateExportItems($is_lang);
        }
        $config['workflows'] = $configWorkflows;
    }


    /**
     * Export notifies. If target_tables is not empty, only export notifies whose
     * target references those tables (custom_table target or workflow attached to those tables).
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateNotify(&$config, $target_tables = [], $is_lang = false)
    {
        $notifies = Notify::all();
        $configNotifies = [];
        foreach ($notifies as $notify) {
            if (count($target_tables) > 0) {
                if (!static::notifyMatchesTargetTables($notify, $target_tables)) {
                    continue;
                }
            }
            $configNotifies[] = $notify->getTemplateExportItems($is_lang);
        }
        $config['notifies'] = $configNotifies;
    }


    /**
     * Whether a Notify record ultimately references any of the target_tables (by table_name).
     */
    // @phpstan-ignore-next-line
    protected static function notifyMatchesTargetTables($notify, array $target_tables): bool
    {
        $trigger = $notify->notify_trigger;
        $customTableTriggers = \Exceedone\Exment\Enums\NotifyTrigger::CUSTOM_TABLES();
        if (is_array($customTableTriggers) && in_array($trigger, $customTableTriggers)) {
            $table = \Exceedone\Exment\Model\CustomTable::getEloquent($notify->target_id);
            return $table && in_array($table->table_name, $target_tables);
        }
        if ($trigger == \Exceedone\Exment\Enums\NotifyTrigger::WORKFLOW) {
            $workflow = Workflow::find($notify->target_id);
            if (!$workflow) {
                return false;
            }
            $tables = collect($workflow->workflow_tables)->pluck('custom_table.table_name')->filter();
            return $tables->intersect($target_tables)->isNotEmpty();
        }
        // Fallback: include when we can't determine
        return true;
    }


    // @phpstan-ignore-next-line
    protected static function getTemplateMenuItems($menu, $target_tables, $is_lang = false)
    {
        // checking target table visible. if false, return empty array
        if (count($target_tables) > 0 && !\Admin::user()->visible($menu, $target_tables)) {
            return [];
        }

        $menus = [];
        // @phpstan-ignore-next-line
        $menus[] = Menu::find(array_get($menu, 'id'))->getTemplateExportItems($is_lang);

        // if has children, loop
        if (array_key_value_exists('children', $menu)) {
            foreach (array_get($menu, 'children') as $child) {
                // set children menu item recursively to $menus.
                $menus = array_merge($menus, static::getTemplateMenuItems($child, $target_tables, $is_lang));
            }
        }
        return $menus;
    }
}
