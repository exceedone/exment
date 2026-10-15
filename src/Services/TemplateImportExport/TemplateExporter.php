<?php

namespace Exceedone\Exment\Services\TemplateImportExport;

use Illuminate\Support\Facades\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CellStylePreset;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Enums\RoleType;
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
            static::setTemplateRole($config, $options['target_tables'] ?? [], $is_lang);
        }
        if (in_array(TemplateExportTarget::PUBLIC_FORM, $options['export_target'])) {
            static::setTemplatePublicForm($config, array_get($options, 'public_form_uuid'), $is_lang);
        }
        if (in_array(TemplateExportTarget::WORKFLOW, $options['export_target'])) {
            static::setTemplateWorkflow($config, $options['target_tables'] ?? [], $is_lang);
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
        $configFormPriorities = [];
        foreach ($custom_forms as $custom_form) {
            if (count($target_tables) > 0 && !in_array(array_get($custom_form, 'custom_table.table_name'), $target_tables)) {
                continue;
            }
            $form = $custom_form->getTemplateExportItems($is_lang);

            // An older Exment writes every form key it does not know into the
            // form row and the whole import fails, so the display priorities
            // travel in a top-level list it skips. The importer puts them back.
            $priorities = array_get($form, 'custom_form_priorities');
            array_forget($form, 'custom_form_priorities');
            if (!empty($priorities)) {
                $configFormPriorities[] = [
                    'suuid' => array_get($form, 'suuid'),
                    'custom_form_priorities' => $priorities,
                ];
            }
            $configForms[] = $form;
        }
        $config['custom_forms'] = $configForms;
        if (count($configFormPriorities) > 0) {
            $config['custom_form_priorities'] = $configFormPriorities;
        }

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

        // get cell style presets -----------------------------------------
        static::setTemplateCellStylePreset($config, $is_lang);
    }

    /**
     * Carry the cell style presets the exported tables and views point at.
     *
     * A column stores only the preset key, so a template that leaves the
     * preset behind lands on a system where that key resolves to nothing and
     * the column quietly renders unstyled.
     *
     * Only presets made on this system are carried. The "sys." catalog is
     * seeded by a migration, so every installation already has it, and
     * writing it back would repaint lists that have nothing to do with the
     * imported tables.
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateCellStylePreset(&$config, $is_lang = false)
    {
        $config['cell_style_presets'] = [];

        if (!CellStylePreset::available()) {
            return;
        }

        $keys = static::collectCellStylePresetKeys(array_only($config, ['custom_tables', 'custom_views']));
        $keys = array_filter($keys, function ($key) {
            return strpos($key, CellStylePreset::BUILTIN_PREFIX) !== 0;
        });

        if (empty($keys)) {
            return;
        }

        $presets = CellStylePreset::whereIn('suuid', array_values($keys))->orderBy('order')->orderBy('id')->get();

        $configPresets = [];
        foreach ($presets as $preset) {
            $configPresets[] = $preset->getTemplateExportItems($is_lang);
        }
        $config['cell_style_presets'] = $configPresets;
    }

    /**
     * Every grid_preset value found anywhere in an exported structure.
     *
     * The key appears in a custom column's options and in a view column's
     * options, and the surrounding shape differs between the two, so the
     * whole structure is walked rather than read at fixed paths.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    protected static function collectCellStylePresetKeys($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $k => $v) {
            if ($k === 'grid_preset') {
                if (is_string($v) && !is_nullorempty($v)) {
                    $keys[] = $v;
                }
                continue;
            }
            $keys = array_merge($keys, static::collectCellStylePresetKeys($v));
        }

        return array_values(array_unique($keys));
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
     * set Role info to config.
     * If target_tables is not empty, only export role groups that grant a
     * permission on one of those tables. Without this a plugin template drags
     * in every unrelated role group of the source system.
     */
    // @phpstan-ignore-next-line
    protected static function setTemplateRole(&$config, $target_tables = [], $is_lang = false)
    {
        // Get Roles --------------------------------------------------
        $roles = RoleGroup::with('role_group_permissions')->get();
        $configRoles = [];

        foreach ($roles as $role) {
            if (count($target_tables) > 0 && !static::roleGroupMatchesTargetTables($role, $target_tables)) {
                continue;
            }
            $configRoles[] = $role->getTemplateExportItems($is_lang);
        }
        $config['roles'] = $configRoles;
    }


    /**
     * Whether a RoleGroup grants a table permission on any of the target_tables.
     */
    // @phpstan-ignore-next-line
    protected static function roleGroupMatchesTargetTables($role_group, array $target_tables): bool
    {
        foreach ($role_group->role_group_permissions as $permission) {
            if (!isMatchString($permission->role_group_permission_type, RoleType::TABLE)) {
                continue;
            }
            $table = CustomTable::getEloquent($permission->role_group_target_id);
            if ($table && in_array($table->table_name, $target_tables)) {
                return true;
            }
        }

        return false;
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
        // Workflow relations are declared without return types, so larastan cannot see them.
        // @phpstan-ignore-next-line
        $workflows = Workflow::with([
            'workflow_tables',
            'workflow_tables.custom_table',
            'workflow_statuses',
            'workflow_actions',
        ])->get();

        $configWorkflows = [];
        foreach ($workflows as $workflow) {
            if (count($target_tables) > 0) {
                $attached = $workflow->workflow_tables
                    ->pluck('custom_table.table_name')
                    ->filter()
                    ->intersect($target_tables);
                if ($attached->isEmpty()) {
                    continue;
                }
            }
            $configWorkflows[] = $workflow->exportTemplateDesignerLayout($workflow->getTemplateExportItems($is_lang));
        }
        $config['workflows'] = $configWorkflows;
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
