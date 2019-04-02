<?php
namespace Exceedone\Exment\Services\TemplateImportExport;

use Illuminate\Support\Facades\File;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\DashboardBoxType;
use ZipArchive;

/**
 * Export Template
 */
class TemplateExporter
{
    /**
     * Create template from this system .
     */
    public static function exportTemplate($template_name, $template_view_name, $description, $thumbnail, $options = [])
    {
        // set options
        $options = array_merge([
            'export_target' => [],
            'target_tables' => [],
        ], $options);

        // set config info
        $config = static::getExportData($template_name, $template_view_name, $description, $options);
        // set language info
        $lang = static::getExportData($template_name, $template_view_name, $description, $options, true);
        
        // create ZIP file --------------------------------------------------
        $tmpdir = getTmpFolderPath('template', false);
        $tmpFulldir = getFullpath($tmpdir, 'admin_tmp', true);
        $tmpfilename = make_uuid();

        $zip = new ZipArchive();
        $zipfilename = short_uuid().'.zip';
        $zipfillpath = path_join($tmpFulldir, $zipfilename);
        if ($zip->open($zipfillpath, ZipArchive::CREATE)!==true) {
            //TODO:error
        }
        
        // add thumbnail
        if (isset($thumbnail)) {
            // save thumbnail
            $thumbnail_dir = path_join($tmpdir, short_uuid());
            $thumbnail_dirpath = getFullpath($thumbnail_dir, 'admin_tmp');

            $thumbnail_name = 'thumbnail.' . $thumbnail->extension();
            $thumbnail_path = $thumbnail->store($thumbnail_dir, 'admin_tmp');
            $thumbnail_fullpath = getFullpath($thumbnail_path, 'admin_tmp');
            $zip->addFile($thumbnail_fullpath, $thumbnail_name);

            $config['thumbnail'] = $thumbnail_name;
        }

        // add config array
        $locale = \App::getLocale();
        $zip->addFromString('config.json', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString("lang/$locale/lang.json", json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        // isset $thumbnail_fullpath, remove
        if (isset($thumbnail_dirpath)) {
            File::deleteDirectory($thumbnail_dirpath);
        }
        // create response
        $filename = $template_name.'.zip';
        $response = response()->download($zipfillpath, $filename)->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * get export data array
     */
    public static function getExportData($template_name, $template_view_name, $description, $options = [], $is_lang = false)
    {
        $config = [];

        if (!$is_lang) {
            $config['template_name'] = $template_name;
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
        if (in_array(TemplateExportTarget::AUTHORITY, $options['export_target'])) {
            static::setTemplateRole($config, $is_lang);
        }

        return $config;
    }
    /**
     * set table info to config
     */
    protected static function setTemplateTable(&$config, $target_tables, $is_lang = false)
    {
        // get customtable and columns --------------------------------------------------
        $custom_tables = CustomTable::with('custom_columns')->get();

        $configTables = [];
        foreach ($custom_tables as $custom_table) {
            $configTables[] = $custom_table->getTemplateExportItems($is_lang);
        }
        $config['custom_tables'] = $configTables;

        // get forms --------------------------------------------------
        $custom_forms = CustomForm::with('custom_form_blocks')
            ->with('custom_table')
            ->with('custom_form_blocks.custom_form_columns')
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->get();
        $configForms = [];
        foreach ($custom_forms as $custom_form) {
            $form = $custom_form->getTemplateExportItems($is_lang);
            $configForms[] = $form;
        }
        $config['custom_forms'] = $configForms;

        // get views --------------------------------------------------
        $custom_views = CustomView
            ::with('custom_view_columns')
            ->with('custom_view_filters')
            ->with('custom_view_sorts')
            ->with('custom_view_summaries')
            ->with('custom_table')
            ->with('custom_view_columns.custom_column')
            ->with('custom_view_filters.custom_column')
            ->with('custom_view_sorts.custom_column')
            ->with('custom_view_summaries.custom_column')
            ->get();
        $configViews = [];
        foreach ($custom_views as $custom_view) {
            $configViews[] = $custom_view->getTemplateExportItems($is_lang);
        }
        $config['custom_views'] = $configViews;
        
        // get relations --------------------------------------------------
        $custom_relations = CustomRelation
            ::with('parent_custom_table')
            ->with('child_custom_table')
            ->get();
        $configRelations = [];
        foreach ($custom_relations as $custom_relation) {
            $configRelations[] = $custom_relation->getTemplateExportItems($is_lang);
        }
        $config['custom_relations'] = $configRelations;
        
        // get copies --------------------------------------------------
        $custom_copies = CustomCopy
            ::with('custom_copy_columns')
            ->get();
        $configCopies = [];
        foreach ($custom_copies as $custom_copy) {
            $configCopies[] = $custom_copy->getTemplateExportItems($is_lang);
        }
        $config['custom_copies'] = $configCopies;
    }
    
    /**
     * set menu info to config
     */
    protected static function setTemplateMenu(&$config, $target_tables, $is_lang = false)
    {
        // get menu --------------------------------------------------
        $menuTree = (new Menu)->toTree(); // menutree:hierarchy
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
    protected static function setTemplateDashboard(&$config, $is_lang = false)
    {
        // get dashboards --------------------------------------------------
        $dashboards = Dashboard
            ::with('dashboard_boxes')
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
    protected static function setTemplateRole(&$config, $is_lang = false)
    {
        // Get Roles --------------------------------------------------
        $roles = Role::all();
        $configRoles = [];

        foreach ($roles as $role) {
            $configRoles[] = $role->getTemplateExportItems($is_lang);
        }
        $config['roles'] = $configRoles;
    }
    
    protected static function getTemplateMenuItems($menu, $target_tables, $is_lang = false)
    {
        // checking target table visible. if false, return empty array
        if (count($target_tables) > 0 && !\Admin::user()->visible($menu, $target_tables)) {
            return [];
        }
        
        $menus = [];
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
