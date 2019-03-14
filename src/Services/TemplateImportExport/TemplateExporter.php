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
        // get customtables --------------------------------------------------
        $tables = CustomTable::with('custom_columns')->get()->toArray();
        $configTables = [];
        foreach ($tables as &$table) {
            // if table contains $options->target_tables, continue
            if (count($target_tables) > 0 && !in_array(array_get($table, 'table_name'), $target_tables)) {
                continue;
            }

            // replace id to name
            if (isset($table['custom_columns']) && is_array($table['custom_columns'])) {
                foreach ($table['custom_columns'] as &$custom_column) {
                    // if select_table, change select_target_table to select_target_table_name
                    if (array_get($custom_column, 'column_type') == 'select_table') {
                        $select_target_table = CustomTable::getEloquent(array_get($custom_column['options'], 'select_target_table'));
                        if (isset($select_target_table)) {
                            $custom_column['options']['select_target_table_name'] = CustomTable::getEloquent(array_get($custom_column['options'], 'select_target_table'))->table_name;
                            array_forget($custom_column['options'], 'select_target_table');
                        }
                    }
                    // if column_type is calc, change value dynamic name using calc_formula property
                    if (ColumnType::isCalc(array_get($custom_column, 'column_type'))) {
                        $calc_formula = array_get($custom_column['options'], 'calc_formula');
                        // if $calc_formula is string, convert to json
                        if (is_string($calc_formula)) {
                            $calc_formula = json_decode($calc_formula, true);
                        }
                        if (is_array($calc_formula)) {
                            foreach ($calc_formula as &$c) {
                                // if not dynamic, continue
                                if (array_get($c, 'type') != 'dynamic') {
                                    continue;
                                }
                                // get custom column name
                                $calc_formula_column_name = CustomColumn::getEloquent(array_get($c, 'val'))->column_name ?? null;
                                // set value
                                $c['val'] = $calc_formula_column_name;
                            }
                        }
                        // set options
                        $custom_column['options']['calc_formula'] = $calc_formula;
                    }
                    
                    $select_target_table = CustomTable::getEloquent(array_get($custom_column['options'], 'select_target_table'));
                    if (isset($select_target_table)) {
                        $custom_column['options']['select_target_table_name'] = CustomTable::getEloquent(array_get($custom_column['options'], 'select_target_table'))->table_name;
                        array_forget($custom_column['options'], 'select_target_table');
                    }
                    
                    $custom_column = CustomColumn::filterExportItems($custom_column, $is_lang);
                }
            }

            $table = CustomTable::filterExportItems($table, $is_lang);
            $configTables[] = $table;
        }
        $config['custom_tables'] = $configTables;

        // get forms --------------------------------------------------
        $forms = CustomForm::with('custom_form_blocks')
            ->with('custom_table')
            ->with('custom_form_blocks.custom_form_columns')
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->get()->toArray();
        $configForms = [];
        foreach ($forms as &$form) {
            // replace id to name
            // add table name
            $form['table_name'] = array_get($form, 'custom_table.table_name');
            // if table contains $options->target_tables, continue
            if (count($target_tables) > 0 && !in_array($form['table_name'], $target_tables)) {
                continue;
            }

            // loop custom_block
            if (isset($form['custom_form_blocks']) && is_array($form['custom_form_blocks'])) {
                foreach ($form['custom_form_blocks'] as &$custom_form_block) {
                    // loop custom_block
                    if (isset($custom_form_block['custom_form_columns']) && is_array($custom_form_block['custom_form_columns'])) {
                        foreach ($custom_form_block['custom_form_columns'] as &$custom_form_column) {
                            // replace id to name
                            $form_column_target_id = array_get($custom_form_column, 'form_column_target_id');
                            switch (array_get($custom_form_column, 'form_column_type')) {
                                case FormColumnType::COLUMN:
                                    $custom_form_column['form_column_target_name'] = array_get($custom_form_column, 'custom_column.column_name', null);
                                    break;
                                default:
                                    $custom_form_column['form_column_target_name'] = array_get($custom_form_column, 'form_column_target', null);
                                    break;
                            }
                            
                            if (is_null($custom_form_column['options'])) {
                                $custom_form_column['options'] = [];
                            }
                            // set as changedata_column_id to changedata_column_name
                            if (array_key_value_exists('changedata_column_id', $custom_form_column['options'])) {
                                $changedata_column = CustomColumn::getEloquent($custom_form_column['options']['changedata_column_id']);
                                $custom_form_column['options']['changedata_column_name'] = $changedata_column->column_name ?? null;
                                // set changedata_column table name
                                $custom_form_column['options']['changedata_column_table_name'] = $changedata_column->custom_table->table_name ?? null;
                            }
                            array_forget($custom_form_column['options'], 'changedata_column_id');
                            // set as changedata_target_column_id to changedata_target_column_name
                            if (array_key_value_exists('changedata_target_column_id', $custom_form_column['options'])) {
                                $custom_form_column['options']['changedata_target_column_name'] = CustomColumn::getEloquent($custom_form_column['options']['changedata_target_column_id'])->column_name ?? null;
                            }
                            array_forget($custom_form_column['options'], 'changedata_target_column_id');

                            $custom_form_column = CustomFormColumn::filterExportItems($custom_form_column, $is_lang);
                        }
                    }

                    // add table
                    if (array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT) {
                        $custom_form_block['form_block_target_table_name'] = null;
                    } else {
                        $custom_form_block['form_block_target_table_name'] = CustomTable::getEloquent(array_get($custom_form_block, 'form_block_target_table_id'))->table_name;
                    }

                    $custom_form_block = CustomFormBlock::filterExportItems($custom_form_block, $is_lang);
                }
            }

            $form = CustomForm::filterExportItems($form, $is_lang);
            $configForms[] = $form;
        }
        $config['custom_forms'] = $configForms;

        // get views --------------------------------------------------
        $views = CustomView
            ::with('custom_view_columns')
            ->with('custom_view_filters')
            ->with('custom_view_sorts')
            ->with('custom_view_summaries')
            ->with('custom_table')
            ->with('custom_view_columns.custom_column')
            ->with('custom_view_filters.custom_column')
            ->with('custom_view_sorts.custom_column')
            ->with('custom_view_summaries.custom_column')
            ->get()->toArray();
        $configViews = [];
        foreach ($views as &$view) {
            // replace id to name
            // add table name
            $view['table_name'] = array_get($view, 'custom_table.table_name');
            // if table contains $options->target_tables, continue
            if (count($target_tables) > 0 && !in_array($view['table_name'], $target_tables)) {
                continue;
            }

            // loop custom_view_columns
            if (array_key_value_exists('custom_view_columns', $view)) {
                foreach ($view['custom_view_columns'] as &$custom_view_column) {
                    static::setViewColumnTargetName($custom_view_column);
                    // set $custom_view_column
                    $custom_view_column = CustomViewColumn::filterExportItems($custom_view_column, $is_lang);
                }
            }
            
            // loop custom_view_filters
            if (array_key_value_exists('custom_view_filters', $view)) {
                foreach ($view['custom_view_filters'] as &$custom_view_filter) {
                    static::setViewColumnTargetName($custom_view_filter);
                    // if has value view_filter_condition_value_table_id
                    if (array_key_value_exists('view_filter_condition_value_table_id', $custom_view_filter)) {
                        $custom_view_filter['view_filter_condition_value_table_name'] = CustomTable::getEloquent($custom_view_filter['view_filter_condition_value_table_id'])->table_name ?? null;
                        // TODO:how to set value id
                    }

                    // set $custom_view_filter
                    $custom_view_filter = CustomViewFilter::filterExportItems($custom_view_filter, $is_lang);
                }
            }

            // loop custom_view_sorts
            if (array_key_value_exists('custom_view_sorts', $view)) {
                foreach ($view['custom_view_sorts'] as &$custom_view_column) {
                    static::setViewColumnTargetName($custom_view_column);
                    // set $custom_view_column
                    $custom_view_column = CustomViewSort::filterExportItems($custom_view_column, $is_lang);
                }
            }
            
            // loop custom_view_summaries
            if (array_key_value_exists('custom_view_summaries', $view)) {
                foreach ($view['custom_view_summaries'] as &$custom_view_summary) {
                    static::setViewColumnTargetName($custom_view_summary);
                    // set $custom_view_filter
                    $custom_view_summary = CustomViewSummary::filterExportItems($custom_view_summary, $is_lang);
                }
            }
            
            $view = CustomView::filterExportItems($view, $is_lang);
            $configViews[] = $view;
        }
        $config['custom_views'] = $configViews;
        
        // get relations --------------------------------------------------
        $relations = CustomRelation
        ::with('parent_custom_table')
        ->with('child_custom_table')
        ->get()->toArray();
        $configRelations = [];
        foreach ($relations as &$relation) {
            // replace id to name
            $relation['parent_custom_table_name'] = array_get($relation, 'parent_custom_table.table_name');
            $relation['child_custom_table_name'] = array_get($relation, 'child_custom_table.table_name');
            // if table contains $options->target_tables, continue
            if (count($target_tables) > 0 && !in_array($relation['parent_custom_table_name'], $target_tables)) {
                continue;
            }
            
            // set only columns
            $relation = CustomRelation::filterExportItems($relation, $is_lang);
            if (!empty($relation)) {
                $configRelations[] = $relation;
            }
        }
        $config['custom_relations'] = $configRelations;
    }
    protected static function setViewColumnTargetName(&$custom_view_column){
        switch (array_get($custom_view_column, 'view_column_type')) {
            case ViewColumnType::COLUMN:
                $custom_view_column['view_column_target_name'] = array_get($custom_view_column, 'custom_column.column_name') ?? null;
                break;
            case ViewColumnType::SYSTEM:
                $custom_view_column['view_column_target_name'] = array_get($custom_view_column, 'view_column_target');
                break;
            case ViewColumnType::PARENT_ID:
                $custom_view_column['view_column_target_name'] = 'parent_id';
                break;
        }
    }
    /**
     * set menu info to config
     */
    protected static function setTemplateMenu(&$config, $target_tables, $is_lang = false)
    {
        // get menu --------------------------------------------------
        $menuTree = (new Menu)->toTree(); // menutree:hierarchy
        $menulist = (new Menu)->allNodes(); // allNodes:dimensional
        $menus = [];

        // loop for menutree
        foreach ($menuTree as &$menu) {
            // looping and get menu item
            $menus = array_merge($menus, static::getTemplateMenuItems($menu, $target_tables, $menulist));
        }
        // re-loop and remove others
        foreach ($menus as &$menu) {
            // remove others
            $menu = Menu::filterExportItems($menu, $is_lang);
        }
        $config['admin_menu'] = $menus;
    }

    protected static function getTemplateMenuItems($menu, $target_tables, $menulist)
    {
        // checking target table visible. if false, return empty array
        $menus = [];
        if (count($target_tables) > 0 && !Admin::user()->visible($menu, $target_tables)) {
            return [];
        }

        // add item
        // replace id to name
        //get parent name
        if (!isset($menu['parent_id']) || $menu['parent_id'] == '0') {
            $menu['parent_name'] = null;
        } else {
            $parent_id = $menu['parent_id'];
            $parent = collect($menulist)->first(function ($value, $key) use ($parent_id) {
                return array_get($value, 'id') == $parent_id;
            });
            $menu['parent_name'] = isset($parent) ? array_get($parent, 'menu_name') : null;
        }

        // menu_target
        $menu_type = $menu['menu_type'];
        if (MenuType::TABLE == $menu_type) {
            $menu['menu_target_name'] = CustomTable::getEloquent($menu['menu_target'])->table_name ?? null;
        } elseif (MenuType::PLUGIN == $menu_type) {
            $menu['menu_target_name'] = Plugin::getEloquent($menu['menu_target'])->plugin_name;
        } elseif (MenuType::SYSTEM == $menu_type) {
            $menu['menu_target_name'] = $menu['menu_name'];
        }
        // custom, parent_node
        else {
            $menu['menu_target_name'] = $menu['menu_target'];
        }

        //// url
        // menu type is table, remove uri "data/"
        if (MenuType::TABLE == $menu_type) {
            $menu['uri'] = preg_replace('/^data\//', '', $menu['uri']);
        }

        // add array
        $menus[] = $menu;

        // if has children, loop
        if (array_key_value_exists('children', $menu)) {
            foreach (array_get($menu, 'children') as $child) {
                // set children menu item recursively to $menus.
                $menus = array_merge($menus, static::getTemplateMenuItems($child, $target_tables, $menulist));
            }
        }
        return $menus;
    }

    /**
     * set dashboard info to config
     */
    protected static function setTemplateDashboard(&$config, $is_lang = false)
    {
        // get dashboards --------------------------------------------------
        $dashboards = Dashboard
            ::with('dashboard_boxes')
            ->get()->toArray();
        foreach ($dashboards as &$dashboard) {
            // loop for dashboard box
            if (array_key_value_exists('dashboard_boxes', $dashboard)) {
                foreach ($dashboard['dashboard_boxes'] as &$dashboard_box) {
                    $options = [];
                    switch (array_get($dashboard_box, 'dashboard_box_type')) {
                        // list
                        case DashboardBoxType::LIST:
                            // get table name and view
                            $table_id = array_get($dashboard_box, 'options.target_table_id');
                            $table_name = CustomTable::getEloquent($table_id)->table_name ?? null;
                            // TODO:if null

                            $view_id = array_get($dashboard_box, 'options.target_view_id');
                            $view_suuid = CustomView::getEloquent($view_id)->suuid ?? null;
                            // TODO:null
                            $options = [
                                'target_table_name' => $table_name,
                                'target_view_suuid' => $view_suuid,
                            ];
                            break;
                        // system
                        case DashboardBoxType::SYSTEM:
                            // get target system name
                            $system_id = array_get($dashboard_box, 'options.target_system_id');
                            $system_name = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function ($value) use ($system_id) {
                                return array_get($value, 'id') == $system_id;
                            })['name'] ?? null;
                            // TODO:null
                            $options = [
                                'target_system_name' => $system_name,
                            ];
                            break;
                    }

                    $dashboard_box['options'] = $options;
                    $dashboard_box = DashboardBox::filterExportItems($dashboard_box, $is_lang);
                }
            }

            // set only columns
            $dashboard = Dashboard::filterExportItems($dashboard, $is_lang);
        }
        $config['dashboards'] = $dashboards;
    }

    /**
     * set Role info to config
     */
    protected static function setTemplateRole(&$config, $is_lang = false)
    {
        // Get Roles --------------------------------------------------
        $roles = Role::all()->toArray();
        foreach ($roles as &$role) {
            // redeclare permissions
            if (isset($role['permissions']) && is_array($role['permissions'])) {
                $permissions = [];
                foreach ($role['permissions'] as $key => $value) {
                    $permissions[$key] = $value;
                }
                $role['permissions'] = $permissions;
            }
            $role = Role::filterExportItems($role, $is_lang);
        }
        $config['roles'] = $roles;
    }
}
