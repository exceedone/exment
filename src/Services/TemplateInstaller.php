<?php
namespace Exceedone\Exment\Services;

use Illuminate\Support\Facades\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\MailTemplate;
use ZipArchive;

/**
 * Install Template
 */
class TemplateInstaller
{
    /**
     * get template list (get from app folder and vendor/exceedone/exment/templates)
     */
    public static function getTemplates()
    {
        $templates = [];

        foreach (static::getTemplateBasePaths() as $templates_path) {
            $paths = File::glob("$templates_path/*/config.json");
            foreach ($paths as $path) {
                try {
                    $dirname = pathinfo($path)['dirname'];
                    $json = json_decode(File::get($path), true);
                    // add thumbnail
                    if (isset($json['thumbnail'])) {
                        $thumbnail_fullpath = path_join($dirname, $json['thumbnail']);
                        if (File::exists($thumbnail_fullpath)) {
                            $json['thumbnail_fullpath'] = $thumbnail_fullpath;
                        }
                    }
                    array_push($templates, $json);
                } catch (Exception $exception) {
                    //TODO:error handling
                }
            }
        }

        return $templates;
    }

    /**
     * Install template (from display)
     */
    public static function installTemplate($templateName)
    {
        if (!is_array($templateName)) {
            $templateName = [$templateName];
        }
        
        foreach (static::getTemplateBasePaths() as $templates_path) {
            foreach ($templateName as $t) {
                if (!isset($t)) {
                    continue;
                }
                $path = "$templates_path/$t/config.json";
                if (!File::exists($path)) {
                    continue;
                }
                
                static::install($path);
            }
        }
    }


    /**
     * Install System template (from command)
     */
    public static function installSystemTemplate()
    {
        // get vendor folder
        $templates_base_path = base_path() . '/vendor/exceedone/exment/system_template';
        $path = "$templates_base_path/config.json";

        static::install($path, true);
    }

    /**
     * Upload template (from display)
     */
    public static function uploadTemplate($uploadFile)
    {
        // store uploaded file
        $filename = $uploadFile->store('template_tmp', 'local');
        $fullpath = getFullpath($filename, 'local');
        $tmpfolderpath = path_join(pathinfo($fullpath)['dirname'], pathinfo($fullpath)['filename']);
        // zip
        $zip = new ZipArchive;
        $res = $zip->open($fullpath);
        if ($res !== true) {
            //TODO:error
        }

        //Check existed file config (config.json)
        $config_path = null;
        $thumbnail_path = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $fileInfo = $zip->getNameIndex($i);
            if ($fileInfo === 'config.json') {
                $zip->extractTo($tmpfolderpath);
                $config_path = path_join($tmpfolderpath, array_get($stat, 'name'));
            } elseif (pathinfo($fileInfo)['filename'] === 'thumbnail') {
                $thumbnail_path = path_join($tmpfolderpath, array_get($stat, 'name'));
            }
        }

        //
        if (isset($config_path)) {
            // get config.json
            $json = json_decode(File::get($config_path), true);
            if (!isset($json)) {
                // TODO:エラー
                return;
            }

            // get template name
            $template_name = array_get($json, 'template_name');
            if (!isset($template_name)) {
                //TODO:エラー
                return;
            }

            // copy to app/templates path
            $app_template_path = path_join(static::getTemplatePath(), $template_name);
            
            if (!File::exists($app_template_path)) {
                File::makeDirectory($app_template_path);
            }
            // copy config
            File::copy($config_path, path_join($app_template_path, 'config.json'));
            //
            if (isset($thumbnail_path)) {
                File::copy($thumbnail_path, path_join($app_template_path, pathinfo($thumbnail_path)['basename']));
            }
            
            return $template_name;
        }
    }

    /**
     */
    protected static function install($basePath, $system_flg=false)
    {
        // If file not exists
        if (!File::exists($basePath)) {
            // TODO:エラー
        }

        // Get file
        $filestring = File::get($basePath);
        $json = json_decode($filestring, true);
        if (!isset($json)) {
            // TODO:エラー
            return;
        }

        // Loop by tables
        foreach (array_get($json, "tables") as $table) {
            // Create tables. --------------------------------------------------
            $obj_table = CustomTable::firstOrNew(['table_name' => array_get($table, 'table_name')]);
            $obj_table->table_name = array_get($table, 'table_name');
            $obj_table->table_view_name = array_get($table, 'table_view_name');
            $obj_table->icon = array_get($table, 'icon');
            $obj_table->color = array_get($table, 'color');
            $obj_table->one_record_flg = boolval(array_get($table, 'one_record_flg'));
            $obj_table->search_enabled = boolval(array_get($table, 'search_enabled'));
            $obj_table->system_flg = $system_flg;
            $obj_table->saveOrFail();

            // Create database table.
            $table_name = array_get($table, 'table_name');
            createTable($table_name);
        }

        // Re-Loop by tables and create columns
        foreach (array_get($json, "tables") as $table) {
            // find tables. --------------------------------------------------
            $obj_table = CustomTable::firstOrNew(['table_name' => array_get($table, 'table_name')]);
            
            // Create columns. --------------------------------------------------
            if (array_key_exists('custom_columns', $table)) {
                $table_columns = [];
                foreach (array_get($table, 'custom_columns') as $column) {
                    $obj_column = CustomColumn::firstOrNew(['column_name' => array_get($column, 'column_name')]);
                    $obj_column->column_name = array_get($column, 'column_name');
                    $obj_column->column_view_name = array_get($column, 'column_view_name');
                    $obj_column->column_type = array_get($column, 'column_type');
                    $obj_column->system_flg = $system_flg;

                    $options = array_get($column, 'options');
                    if (is_null($options)) {
                        $options = [];
                    }
                    // if options has select_target_table_name, get id
                    if (array_key_exists('select_target_table_name', $options)) {
                        $custom_table = CustomTable::findByName(array_get($options, 'select_target_table_name'));
                        $id = $custom_table->id ?? null;
                        // not set id, continue
                        if (!isset($id)) {
                            continue;
                        }
                        $options['select_target_table'] = $id;
                        array_forget($options, 'select_target_table_name');
                    }
                    $obj_column->options = $options;

                    array_push($table_columns, $obj_column);
                }

                $obj_table->custom_columns()->saveMany($table_columns);
            }

            // Create database table.
            $table_name = array_get($table, 'table_name');
            
            // alter table
            foreach (getSearchEnabledColumns($table_name) as $column) {
                alterColumn($table_name, array_get($column, 'column_name'));
            }
        }

        // Loop relations.
        if (array_key_exists('relations', $json)) {
            foreach (array_get($json, "relations") as $relation) {
                $parent_id = CustomTable::findByName(array_get($relation, 'parent_custom_table_name'))->id ?? null;
                $child_id = CustomTable::findByName(array_get($relation, 'child_custom_table_name'))->id ?? null;
                if (!isset($parent_id) || !isset($child_id)) {
                    continue;
                }
                
                // Create relations. --------------------------------------------------
                $obj_relation = CustomRelation::firstOrNew([
                    'parent_custom_table_id' => $parent_id
                    , 'child_custom_table_id' => $child_id
                    ]);
                $obj_relation->parent_custom_table_id = $parent_id;
                $obj_relation->child_custom_table_id = $child_id;
                $obj_relation->relation_type = array_get($relation, 'relation_type');
                $obj_relation->saveOrFail();
            }
        }

        // loop for form
        if (array_key_exists('form', $json)) {
            foreach (array_get($json, "form") as $form) {
                $table = CustomTable::findByName(array_get($form, 'table_name'));
                // Create form --------------------------------------------------
                $obj_form = CustomForm::firstOrNew([
                    'custom_table_id' => $table->id
                    ]);
                $obj_form->form_view_name = array_get($form, 'form_view_name');
                $obj_form->saveOrFail();

                // Create form block
                foreach (array_get($form, "custom_form_blocks") as $form_block) {
                    // target block id
                    if (isset($form_block['form_block_target_table_name'])) {
                        $target_table = CustomTable::findByName($form_block['form_block_target_table_name']);
                    } else {
                        $target_table = $table;
                    }

                    // get form_block_type
                    if (isset($form_block['form_block_type'])) {
                        $form_block_type = $form_block['form_block_type'];
                    } else {
                        $self = $target_table->id == $table->id;
                        if ($self) {
                            $form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
                        } else {
                            // get relation
                            $block_relation = CustomRelation
                                                ::where('parent_custom_table_id', $table->id)
                                                ->where('child_custom_table_id', $target_table->id)
                                                ->first();
                            if (isset($block_relation)) {
                                $form_block_type = $block_relation->relation_type;
                            } else {
                                $form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY;
                            }
                        }
                    }

                    $obj_form_block = CustomFormBlock::firstOrNew([
                        'custom_form_id' => $obj_form->id,
                        'form_block_target_table_id' => $target_table->id,
                    ]);
                    $obj_form_block->custom_form_id = $obj_form->id;
                    $obj_form_block->form_block_type = $form_block_type;
                    $obj_form_block->form_block_target_table_id = $target_table->id;
                    if (!$obj_form_block->exists) {
                        $obj_form_block->available = true;
                    }
                    $obj_form_block->saveOrFail();

                    // create form colunms --------------------------------------------------
                    if (array_key_exists('custom_form_columns', $form_block)) {
                        // get column counts
                        $count = count($obj_form_block->custom_form_columns);
                        foreach (array_get($form_block, "custom_form_columns") as $form_column) {
                            //
                            if (array_key_exists('form_column_type', $form_column)) {
                                $form_column_type = array_get($form_column, "form_column_type");
                            } else {
                                $form_column_type = Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN;
                            }

                            $form_column_name = array_get($form_column, "form_column_target_name");
                            switch ($form_column_type) {
                                // for table column
                                case Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN:
                                    // get column name
                                    $form_column_target = CustomColumn
                                        ::where('column_name', $form_column_name)
                                        ->where('custom_table_id', $target_table->id)
                                        ->first();
                                    $form_column_target_id = isset($form_column_target) ? $form_column_target->id : null;
                                    break;
                                default:
                                    $form_column_target = collect(Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE)->first(function ($item) use ($form_column_name) {
                                        return $item['column_name'] == $form_column_name;
                                    });
                                    $form_column_target_id = isset($form_column_target) ? $form_column_target['id'] : null;
                                    break;
                            }

                            // if not set column id, continue
                            if (!isset($form_column_target_id)) {
                                continue;
                            }

                            $obj_form_column = CustomFormColumn::firstOrNew([
                                'custom_form_block_id' => $obj_form_block->id,
                                'form_column_type' => $form_column_type,
                                'form_column_target_id' => $form_column_target_id,
                            ]);
                            $obj_form_column->custom_form_block_id = $obj_form_block->id;
                            $obj_form_column->form_column_type = $form_column_type;
                            $obj_form_column->form_column_target_id = $form_column_target_id;
                            if (!$obj_form_column->exists) {
                                $obj_form_column->order = ++$count;
                            }
                            
                            $options = array_get($form_column, 'options');
                            if (is_null($options)) {
                                $options = null;
                            }
                            $obj_form_column->options = $options;
                            
                            $obj_form_column->saveOrFail();
                        }
                    }
                }
            }
        }
        
        // loop for view
        if (array_key_value_exists('view', $json)) {
            foreach (array_get($json, "view") as $view) {
                $table = CustomTable::findByName(array_get($view, 'table_name'));
                // Create view --------------------------------------------------
                $obj_view = Customview::firstOrNew([
                    'custom_table_id' => $table->id
                    ]);
                $obj_view->view_type = array_get($view, 'view_type') ?? Define::VIEW_COLUMN_TYPE_SYSTEM;
                $obj_view->view_view_name = array_get($view, 'view_view_name');
                // if set suuid in json, set suuid(for dashbrord list)
                if(array_key_value_exists('suuid', $view)){
                    $obj_view->suuid = array_get($view, 'suuid');
                }
                $obj_view->saveOrFail();
                
                // create view columns --------------------------------------------------
                if (array_key_exists('custom_view_columns', $view)) {
                    foreach (array_get($view, "custom_view_columns") as $view_column) {
                        if (array_key_exists('view_column_target_type', $view_column)) {
                            $view_column_target_type = array_get($view_column, "view_column_target_type");
                        } else {
                            $view_column_target_type = Define::VIEW_COLUMN_TYPE_COLUMN;
                        }

                        $view_column_name = array_get($view_column, "view_column_target_name");
                        switch ($view_column_target_type) {
                                // for table column
                                case Define::VIEW_COLUMN_TYPE_COLUMN:
                                    // get column name
                                    $view_column_target = CustomColumn
                                        ::where('column_name', $view_column_name)
                                        ->where('custom_table_id', $target_table->id)
                                        ->first()->id ?? null;
                                    break;
                                // system column
                                default:
                                    $view_column_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($view_column_name) {
                                        return $item['name'] == $view_column_name;
                                    })['name'] ?? null;
                                    break;
                            }

                        // if not set column id, continue
                        if (!isset($view_column_target)) {
                            continue;
                        }

                        $obj_view_column = CustomviewColumn::firstOrNew([
                            'custom_view_id' => $obj_view->id,
                            'view_column_target' => $view_column_target,
                            'order' => array_get($view_column, "order"),
                        ]);                    
                        $obj_view_column->saveOrFail();
                    }
                }
                
                // create view filters --------------------------------------------------
                if (array_key_exists('custom_view_filters', $view)) {
                    foreach (array_get($view, "custom_view_filters") as $view_filter) {
                        // get view_filter_target_type for getting view_filter_target_name
                        if (array_key_exists('view_filter_target_type', $view_filter)) {
                            $view_filter_target_type = array_get($view_filter, "view_filter_target_type");
                        } else {
                            $view_filter_target_type = Define::VIEW_COLUMN_TYPE_COLUMN;
                        }

                        $view_filter_name = array_get($view_filter, "view_filter_target_name");
                        switch ($view_filter_target_type) {
                                // for table column
                                case Define::VIEW_COLUMN_TYPE_COLUMN:
                                    // get column id
                                    $view_filter_target = CustomColumn
                                        ::where('column_name', $view_filter_name)
                                        ->where('custom_table_id', $target_table->id)
                                        ->first()->id ?? null;
                                    break;
                                // system column
                                default:
                                    $view_filter_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($view_filter_name) {
                                        return $item['name'] == $view_filter_name;
                                    })['name'] ?? null;
                                    break;
                            }

                        // if not set filter_target id, continue
                        if (!isset($view_filter_target)) {
                            continue;
                        }

                        $obj_view_filter = CustomviewFilter::firstOrNew([
                            'custom_view_id' => $obj_view->id,
                            'view_filter_target' => $view_filter_target,
                            'view_filter_condition' => array_get($view_filter, "view_filter_condition"),
                            'view_filter_condition_value_text' => array_get($view_filter, "view_filter_condition_value_text"),
                        ]);                    
                        $obj_view_filter->saveOrFail();
                    }
                }
            }
        }

        // Loop for authorities.
        if (array_key_exists('authorities', $json)) {
            foreach (array_get($json, "authorities") as $authority) {
                // Create authority. --------------------------------------------------
                $obj_authority = Authority::firstOrNew(['authority_type' => array_get($authority, 'authority_type'), 'authority_name' => array_get($authority, 'authority_name')]);
                $obj_authority->authority_type = array_get($authority, 'authority_type');
                $obj_authority->authority_name = array_get($authority, 'authority_name');
                $obj_authority->authority_view_name = array_get($authority, 'authority_view_name');
                $obj_authority->description = array_get($authority, 'description');
                $obj_authority->default_flg = boolval(array_get($authority, 'default_flg'));

                // Create authority detail.
                if (array_key_exists('permissions', $authority)) {
                    $permissions = [];
                    foreach (array_get($authority, "permissions") as $permission) {
                        array_push($permissions, [$permission => 1]);
                    }
                    $obj_authority->permissions = $permissions;
                }
                $obj_authority->saveOrFail();
            }
        }

        // loop for dashboard
        if (array_key_value_exists('dashboard', $json)) {
            foreach (array_get($json, "dashboard") as $dashboard) {
                // Create dashboard --------------------------------------------------
                $obj_dashboard = Dashboard::firstOrNew([
                    'dashboard_name' => array_get($dashboard, "dashboard_name")
                ]);
                $obj_dashboard->dashboard_type = array_get($dashboard, 'dashboard_type');
                $obj_dashboard->dashboard_view_name = array_get($dashboard, 'dashboard_view_name');
                $obj_dashboard->row1 = array_get($dashboard, 'row1');
                $obj_dashboard->row2 = array_get($dashboard, 'row2');
                // if set suuid in json, set suuid(for dashbrord list)
                if(array_key_value_exists('suuid', $dashboard)){
                    $obj_dashboard->suuid = array_get($dashboard, 'dashboard_suuid');
                }
                $obj_dashboard->saveOrFail();
                
                // create dashboard boxes --------------------------------------------------
                if (array_key_exists('dashboard_boxes', $dashboard)) {
                    foreach (array_get($dashboard, "dashboard_boxes") as $dashboard_box) {
                        
                        $obj_dashboard_box = DashboardBox::firstOrNew([
                            'dashboard_id' => $obj_dashboard->id,
                            'row_no' => array_get($dashboard_box, "row_no"),
                            'column_no' => array_get($dashboard_box, "column_no"),
                        ]);
                        $obj_dashboard_box->dashboard_box_view_name = array_get($dashboard_box, "dashboard_box_view_name");
                        $obj_dashboard_box->dashboard_box_type = array_get($dashboard_box, "dashboard_box_type");

                        // set options
                        $options = $obj_dashboard_box->options;
                        // switch dashboard_box_type
                        switch($obj_dashboard_box->dashboard_box_type){
                            // system box
                            case Define::DASHBOARD_BOX_TYPE_SYSTEM:
                                $options['target_system_id'] = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function($value) use($dashboard_box){
                                    return array_get($value, 'name') == array_get($dashboard_box, 'options.target_system_name');
                                })['id'] ?? null;
                                break;
                            
                            // list
                            case Define::DASHBOARD_BOX_TYPE_LIST:
                                // get target table
                                $options['target_table_id'] = CustomTable::findByName(array_get($dashboard_box, 'options.target_table_name'))->id ?? null;
                                // get target view using suuid
                                $options['target_view_id'] = CustomView::findBySuuid(array_get($dashboard_box, 'options.target_view_suuid'))->id ?? null;
                                break;
                        }

                        $obj_dashboard_box->options = $options;
                        $obj_dashboard_box->saveOrFail();
                    }
                }
            }
        }

        // loop for menu
        if (array_key_exists('menu', $json)) {
            // order by parent_name is null, not null
            $menulist = collect(array_get($json, "menu"));
            // loop for parent_name is null(root), and next parent name has value
            foreach ([0, 1] as $hasname) {
                foreach ($menulist as $menu) {
                    // Create menu. --------------------------------------------------
                    // get parent id
                    $parent_id = null;
                    // get parent id from parent_name
                    if (array_key_exists('parent_name', $menu)) {
                        // if $hasname is 0, $menu['parent_name'] is not null(not root) then continue
                        if ($hasname == 0 && !is_null($menu['parent_name'])) {
                            continue;
                        }
                        // if $hasname is 1, $menu['parent_name'] is null(root) then continue
                        elseif ($hasname == 1 && is_null($menu['parent_name'])) {
                            continue;
                        }

                        $parent = Menu::where('menu_name', $menu['parent_name'])->first();
                        if (isset($parent)) {
                            $parent_id = $parent->id;
                        }
                    }
                    if (is_null($parent_id)) {
                        $parent_id = 0;
                    }

                    // set title
                    if(array_key_value_exists('title', $menu)){
                        $title = array_get($menu, 'title');
                    }
                    // title not exists, translate
                    else{
                        $title = exmtrans('menu.menu_system_definitions.'.array_get($menu, 'menu_target_name'));
                    }

                    $obj_menu = Menu::firstOrNew(['menu_name' => array_get($menu, 'menu_name'), 'parent_id' => $parent_id]);
                    $obj_menu->menu_type = array_get($menu, 'menu_type');
                    $obj_menu->menu_name = array_get($menu, 'menu_name');
                    $obj_menu->title = $title;
                    $obj_menu->parent_id = $parent_id;

                    // get menu target id
                    if (isset($menu['menu_target'])) {
                        $obj_menu->menu_target = $menu['menu_target_id'];
                    }
                    // get menu target id from menu_target_name
                    elseif (isset($menu['menu_target_name'])) {
                        // case plugin or table
                        switch ($menu['menu_type']) {
                            case Define::MENU_TYPE_PLUGIN:
                                $parent = Plugin::where('plugin_name', $menu['menu_target_name'])->first();
                                if (isset($parent)) {
                                    $obj_menu->menu_target = $parent->id;
                                }
                                break;
                            case Define::MENU_TYPE_TABLE:
                                $parent = CustomTable::where('table_name', $menu['menu_target_name'])->first();
                                if (isset($parent)) {
                                    $obj_menu->menu_target = $parent->id;
                                }
                                break;
                        }
                    }

                    // get order
                    if (isset($menu['order'])) {
                        $obj_menu->order = $menu['order'];
                    } else {
                        $obj_menu->order = Menu::where('parent_id', $obj_menu->parent_id)->max('order') + 1;
                    }

                    // icon
                    if (isset($menu['icon'])) {
                        $obj_menu->icon = $menu['icon'];
                    }

                    $obj_menu->saveOrFail();
                }
            }
        }
        
        // Loop for mail templates
        if (array_key_exists('mail_templates', $json)) {
            foreach (array_get($json, "mail_templates") as $mail_template) {
                // Create mail template --------------------------------------------------
                $obj_mail_template = MailTemplate::firstOrNew(['mail_name' => array_get($mail_template, 'mail_name')]);
                $obj_mail_template->mail_name = array_get($mail_template, 'mail_name');
                $obj_mail_template->mail_view_name = array_get($mail_template, 'mail_view_name');
                $obj_mail_template->mail_subject = array_get($mail_template, 'mail_subject');

                // get body
                $body = array_get($mail_template, 'mail_body');
                $obj_mail_template->mail_body = preg_replace("/\r\n|\r|\n/", "\n", $body);
                $obj_mail_template->system_flg = $system_flg;

                $obj_mail_template->saveOrFail();
            }
        }
    }

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

        $config = [];

        $config['template_name'] = $template_name;
        $config['template_view_name'] = $template_view_name;
        $config['description'] = $description;

        ///// set config info
        if(in_array(Define::TEMPLATE_EXPORT_TARGET_TABLE, $options['export_target'])){
            static::setTemplateTable($config, $options['target_tables']);
        }
        if(in_array(Define::TEMPLATE_EXPORT_TARGET_MENU, $options['export_target'])){
            static::setTemplateMenu($config);
        }
        if(in_array(Define::TEMPLATE_EXPORT_TARGET_DASHBOARD, $options['export_target'])){
            static::setTemplateDashboard($config);
        }
        if(in_array(Define::TEMPLATE_EXPORT_TARGET_AUTHORITY, $options['export_target'])){
            static::setTemplateAuthority($config);
        }
        if(in_array(Define::TEMPLATE_EXPORT_TARGET_MAIL_TEMPLATE, $options['export_target'])){
            static::setTemplateMailTemplate($config);
        }

        // create ZIP file --------------------------------------------------
        $zip = new ZipArchive();
        $tmpfilename = make_uuid();
        $fullpath = getFullpath(path_join('exmtmp', $tmpfilename), 'local');
        $basePath = pathinfo($fullpath)['dirname'];
        if (!File::exists($basePath)) {
            File::makeDirectory($basePath, 0775, true);
        }
        if ($zip->open($fullpath, ZipArchive::CREATE)!==true) {
            //TODO:error
        }
        
        // add thumbnail
        if (isset($thumbnail)) {
            // save thumbnail
            $thumbnail_name = 'thumbnail.' . $thumbnail->extension();
            $thumbnail_path = $thumbnail->store('exmtmp', 'local');
            $thumbnail_fullpath = getFullpath($thumbnail_path, 'local');
            $zip->addFile($thumbnail_fullpath, 'thumbnail.' . $thumbnail->extension());

            $config['thumbnail'] = $thumbnail_name;
        }

        // add config array
        $zip->addFromString('config.json', json_encode($config, JSON_UNESCAPED_UNICODE));

        $zip->close();

        // isset $thumbnail_fullpath, remove
        if (isset($thumbnail_fullpath)) {
            File::delete($thumbnail_fullpath);
        }

        // create response
        $filename = $template_name.'.zip';
        $response = response()->download($fullpath, $filename)->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * set table info to config
     */
    protected static function setTemplateTable(&$config, $target_tables){
        // get customtables --------------------------------------------------
        $tables = CustomTable::with('custom_columns')->get()->toArray();
        $configTables = [];
        foreach ($tables as &$table) {
            // if table contains $options->target_tables, continue
            if(count($target_tables) > 0 && !in_array(array_get($table, 'table_name'), $target_tables)){
                continue;
            }

            // replace id to name
            if (isset($table['custom_columns']) && is_array($table['custom_columns'])) {
                foreach ($table['custom_columns'] as &$custom_column) {
                    // if select_table, change select_target_table to select_target_table_name
                    if (array_get($custom_column, 'column_type') == 'select_table') {
                        $select_target_table = CustomTable::find(array_get($custom_column['options'], 'select_target_table'));
                        if (isset($select_target_table)) {
                            $custom_column['options']['select_target_table_name'] = CustomTable::find(array_get($custom_column['options'], 'select_target_table'))->table_name;
                            array_forget($custom_column['options'], 'select_target_table');
                        }
                    }
                    $custom_column = array_only($custom_column, [
                        'column_name',
                        'column_view_name',
                        'column_type',
                        'description',
                        'options',
                    ]);
                }
            }

            $table = array_only($table, [
                'table_name',
                'table_view_name',
                'description',
                'search_enabled',
                'one_record_flg',
                //'system_flg',
                'icon',
                'color',
                'custom_columns',
            ]);
            $configTables[] = $table;
        }
        $config['tables'] = $configTables;

        // get forms --------------------------------------------------
        $forms = CustomForm::with('custom_form_blocks')
            ->with('custom_table')
            ->with('custom_form_blocks.custom_form_columns')
            ->get()->toArray();
        $configForms = [];
        foreach ($forms as &$form) {
            // replace id to name
            // add table name
            $form['table_name'] = array_get($form, 'custom_table.table_name');
            // if table contains $options->target_tables, continue
            if(count($target_tables) > 0 && !in_array($form['table_name'], $target_tables)){
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
                            if (array_get($custom_form_column, 'form_column_type') == Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN) {
                                $custom_form_column['form_column_target_name'] = CustomColumn::find($form_column_target_id)->column_name;
                            } else {
                                $form_column_target_name = collect(Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE)->first(function ($item) use ($form_column_target_id) {
                                    return $item['id'] == $form_column_target_id;
                                });
                                $custom_form_column['form_column_target_name'] = isset($form_column_target_name) ? array_get($form_column_target_name, 'column_name') : null;
                            }
                            $custom_form_column = array_only($custom_form_column, [
                                'form_column_type',
                                'form_column_target_name',
                                'options',
                            ]);
                        }
                    }

                    // add table
                    if (array_get($custom_form_block, 'form_block_type') == Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT) {
                        $custom_form_block['form_block_target_table_name'] = null;
                    } else {
                        $custom_form_block['form_block_target_table_name'] = CustomTable::find(array_get($custom_form_block, 'form_block_target_table_id'))->table_name;
                    }

                    $custom_form_block = array_only($custom_form_block, [
                        'form_block_type',
                        'form_block_view_name',
                        'form_block_target_table_name',
                        'available',
                        'custom_form_columns',
                    ]);
                }
            }

            $form = array_only($form, [
                'form_view_name',
                'custom_form_blocks',
                'table_name',
            ]);
            $configForms[] = $form;
        }
        $config['form'] = $configForms;

        // get views --------------------------------------------------
        $views = CustomView
            ::with('custom_view_columns')
            ->with('custom_view_filters')
            ->with('custom_table')
            ->with('custom_view_columns.custom_column')
            ->with('custom_view_filters.custom_column')
            ->get()->toArray();
        $configViews = [];
        foreach ($views as &$view) {
            // replace id to name
            // add table name
            $view['table_name'] = array_get($view, 'custom_table.table_name');
            // if table contains $options->target_tables, continue
            if(count($target_tables) > 0 && !in_array($view['table_name'], $target_tables)){
                continue;
            }

            // loop custom_view_columns
            if (array_key_value_exists('custom_view_columns', $view)) {
                foreach ($view['custom_view_columns'] as &$custom_view_column) {
                    // replace id to name
                    $view_column_target = array_get($custom_view_column, 'view_column_target');
                    // if number, get column_name
                    if (is_numeric($view_column_target)) {
                        $custom_view_column['view_column_target_name'] = array_get($custom_view_column, 'custom_column.column_name');
                        $custom_view_column['view_column_target_type'] = Define::VIEW_COLUMN_TYPE_COLUMN;
                    }
                    // else, system column
                    else {
                        $custom_view_column['view_column_target_name'] = $view_column_target;
                        $custom_view_column['view_column_target_type'] = Define::VIEW_COLUMN_TYPE_SYSTEM;
                    }

                    // set $custom_view_column
                    $custom_view_column = array_only($custom_view_column, [
                        'view_column_target_name',
                        'view_column_target_type',
                        'order',
                    ]);
                }
            }
            
            // loop custom_view_filters
            if (array_key_value_exists('custom_view_filters', $view)) {
                foreach ($view['custom_view_filters'] as &$custom_view_filter) {
                    // replace id to name
                    $view_filter_target = array_get($custom_view_filter, 'view_filter_target');
                    // if number, get column_name
                    if (is_numeric($view_filter_target)) {
                        $custom_view_filter['view_filter_target_name'] = array_get($custom_view_filter, 'custom_column.column_name');
                        $custom_view_filter['view_filter_target_type'] = Define::VIEW_COLUMN_TYPE_COLUMN;
                    }
                    // else, system column
                    else {
                        $custom_view_filter['view_filter_target_name'] = $view_filter_target;
                        $custom_view_filter['view_filter_target_type'] = Define::VIEW_COLUMN_TYPE_SYSTEM;
                    }

                    // if has value view_filter_condition_value_table_id
                    if (array_key_value_exists('view_filter_condition_value_table_id', $custom_view_filter)) {
                        $custom_view_filter['view_filter_condition_value_table_name'] = CustomTable::find($custom_view_filter['view_filter_condition_value_table_id'])->table_name ?? null;
                        // TODO:how to set value id
                    }

                    // set $custom_view_filter
                    $custom_view_filter = array_only($custom_view_filter, [
                        'view_filter_target_name',
                        'view_filter_target_type',
                        'view_filter_condition',
                        'view_filter_condition_value_text',
                        'view_filter_condition_value_table_name',
                    ]);
                }
            }

            $view = array_only($view, [
                'view_view_name',
                'suuid',
                'custom_view_columns',
                'custom_view_filters',
                'table_name',
            ]);
            $configViews[] = $view;
        }
        $config['view'] = $configViews;
        
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
            if(count($target_tables) > 0 && !in_array($relation['parent_custom_table_name'], $target_tables)){
                continue;
            }
            
            // set only columns
            $relation = array_only($relation, ['parent_custom_table_name', 'child_custom_table_name', 'relation_type']);
            $configRelations[] = $relation;
        }
        $config['relations'] = $configRelations;
    }

    /**
     * set menu info to config
     */
    protected static function setTemplateMenu(&$config){
        // get menu --------------------------------------------------
        $menulist = (new Menu)->allNodes();
        foreach ($menulist as &$menu) {
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
            if ($menu['menu_type'] == Define::MENU_TYPE_TABLE) {
                $menu['menu_target_name'] = CustomTable::find($menu['menu_target'])->table_name ?? null;
            } elseif ($menu['menu_type'] == Define::MENU_TYPE_PLUGIN) {
                $menu['menu_target_name'] = Plugin::find($menu['menu_target'])->plugin_name;
            } elseif ($menu['menu_type'] == Define::MENU_TYPE_SYSTEM) {
                $menu['menu_target_name'] = $menu['menu_name'];
            } else {
                $menu['menu_target_name'] = $menu['menu_target'];
            }
        }
        // re-loop and remove others
        foreach ($menulist as &$menu) {
            // remove others
            $menu = array_only($menu, ['parent_name', 'menu_type', 'menu_name', 'title', 'menu_target_name', 'order']);
        }
        $config['menu'] = $menulist;
    }

    /**
     * set dashboard info to config
     */
    protected static function setTemplateDashboard(&$config){
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
                        case Define::DASHBOARD_BOX_TYPE_LIST:
                            // get table name and view
                            $table_id = array_get($dashboard_box, 'options.target_table_id');
                            $table_name = CustomTable::find($table_id)->table_name ?? null;
                            // TODO:if null

                            $view_id = array_get($dashboard_box, 'options.target_view_id');
                            $view_suuid = CustomView::find($view_id)->suuid ?? null;
                            // TODO:null
                            $options = [
                                'target_table_name' => $table_name,
                                'target_view_suuid' => $view_suuid,
                            ];
                            break;
                        // system
                        case Define::DASHBOARD_BOX_TYPE_SYSTEM:
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

                    $dashboard_box = array_only($dashboard_box, [
                        'row_no',
                        'column_no',
                        'dashboard_box_view_name',
                        'dashboard_box_type',
                    ]);
                    $dashboard_box['options'] = $options;
                }
            }

            // set only columns
            $dashboard = array_only($dashboard, [
                'dashboard_type',
                'dashboard_name',
                'dashboard_view_name',
                'row1',
                'row2',
                'dashboard_boxes',
            ]);
        }
        $config['dashboard'] = $dashboards;
    }

    /**
     * set Authority info to config
     */
    protected static function setTemplateAuthority(&$config){
        // Get Authorities --------------------------------------------------
        $authorities = Authority::all()->toArray();
        foreach ($authorities as &$authority) {
            // redeclare permissions
            if (isset($authority['permissions']) && is_array($authority['permissions'])) {
                $permissions = [];
                foreach ($authority['permissions'] as $key => $value) {
                    $permissions[] = key($value);
                }
                $authority['permissions'] = $permissions;
            }
            $authority = array_only($authority, [
                'authority_type',
                'authority_name',
                'authority_view_name',
                'description',
                'permissions',
            ]);
        }
        $config['authorities'] = $authorities;
    }

    /**
     * set MailTemplate info to config
     */
    protected static function setTemplateMailTemplate(&$config){
        // get mail_templates --------------------------------------------------
        $mail_templates = MailTemplate::all()->toArray();
        foreach ($mail_templates as &$mail_template) {
            // remove others
            $mail_template = array_only($mail_template, ['mail_name', 'mail_subject', 'mail_body']);
        }
        $config['mail_templates'] = $mail_templates;
    }

    protected static function getTemplateBasePaths()
    {
        return [static::getTemplatePath(), base_path().'/vendor/exceedone/exment/templates'];
    }

    protected static function getTemplatePath()
    {
        $path = app_path("Templates");
        if (!File::exists($path)) {
            File::makeDirectory($path, 0775, true);
        }
        return $path;
    }
}
