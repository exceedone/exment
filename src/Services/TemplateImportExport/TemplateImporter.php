<?php
namespace Exceedone\Exment\Services\TemplateImportExport;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomCopyColumn;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Services\DataImportExport\DataImporterBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

/**
 * Import Template
 */
class TemplateImporter
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
     * Import template (from display. select item)
     */
    public static function importTemplate($templateName)
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
                
                static::importFromFile($path);
            }
        }
    }


    /**
     * Import System template (from command)
     */
    public static function importSystemTemplate()
    {
        // get vendor folder
        $templates_base_path = base_path() . '/vendor/exceedone/exment/system_template';
        $path = "$templates_base_path/config.json";

        static::importFromFile($path, true);
    }

    /**
     * Upload template and import (from display)
     */
    public static function uploadTemplate($uploadFile)
    {
        // store uploaded file
        $tmpdir = getTmpFolderPath('template', false);
        $tmpfolderpath = getFullPath(path_join($tmpdir, short_uuid()), 'local', true);

        $filename = $uploadFile->store($tmpdir, 'local');
        $fullpath = getFullpath($filename, 'local');

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
                // TODO:Error
                return;
            }

            // get template name
            $template_name = array_get($json, 'template_name');
            if (!isset($template_name)) {
                // TODO:Error
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
            
        }

        // delete zip
        File::deleteDirectory($tmpfolderpath);
        unlink($fullpath);
        
        return $template_name ?? null;
    }

    /**
     * upload from excel and import
     */
    public static function uploadTemplateExcel($file)
    {
        // template file settings as json
        $settings = [];

        // loop for excel sheets
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($file->getRealPath());
        foreach (Define::TEMPLATE_IMPORT_EXCEL_SHEETNAME as $sheetname) {
            $sheet = $spreadsheet->getSheetByName($sheetname);

            // if not exists sheet, set empty array
            if (!isset($sheet)) {
                $settings[$sheetname] = [];
                continue;
            }

            $data = getDataFromSheet($sheet, 2, true);
            // set config json
            $settings[$sheetname] = $data;
        }

        if (array_key_value_exists('custom_tables', $settings)) {
            foreach ($settings['custom_tables'] as &$custom_table) {
                $custom_table = array_dot_reverse($custom_table);
            }
        }

        // convert custom_columns to custom_tables->custom_columns
        if (array_key_exists('custom_columns', $settings) && array_key_exists('custom_tables', $settings)) {
            $custom_columns = array_get($settings, 'custom_columns');
            foreach ($custom_columns as &$custom_column) {
                // set calc formula
                if (array_key_value_exists('options.calc_formula', $custom_column)) {
                    // split new line
                    $calc_formula_strings = preg_split('/$\R?^/m', $custom_column['options.calc_formula']);
                    $calc_formula = [];
                    foreach ($calc_formula_strings as $calc_formula_string) {
                        // split ","
                        $c =  explode(",", $calc_formula_string);
                        if (count($c) < 2) {
                            continue;
                        }
                        $type = $c[0];

                        // if select table, set type, val and from
                        if ($type == 'select_table') {
                            // get custom_column using target column name and from column_name
                            $vals = explode(".", $c[1]);
                            if (count($vals) < 2) {
                                continue;
                            }
                            // set calc formula. val and from
                            $calc_formula[] = ['type' => $type, 'val' => $vals[0], 'from' => $vals[1]];
                        }
                        // if select table, set type, val and from
                        else {
                            $calc_formula[] = ['type' => $type, 'val' => $c[1]];
                        }
                    }

                    $custom_column['options.calc_formula'] = $calc_formula;
                }

                // get table name
                $table_name = array_get($custom_column, 'table_name');
                // find $settings->custom_tables
                if (!isset($table_name)) {
                    continue;
                }
                // get target custom table
                foreach ($settings['custom_tables'] as &$custom_table) {
                    if ($table_name != array_get($custom_table, 'table_name')) {
                        continue;
                    }
                    // set custom_column to $custom_table
                    $target_custom_table_columns = array_get($custom_table, 'custom_columns', []);
                    // remove custom column table name
                    array_forget($custom_column, 'table_name');
                    $target_custom_table_column = array_dot_reverse($custom_column);
                    $target_custom_table_columns[] = array_dot_reverse($custom_column);
                    $custom_table['custom_columns'] = $target_custom_table_columns;
                    // jump to next column
                    break;
                }
            }
        }
        // forget custom_columns array
        array_forget($settings, 'custom_columns');

        // convert custom_form_columns to custom_form_blocks->custom_columns
        if (array_key_exists('custom_form_columns', $settings) && array_key_exists('custom_form_blocks', $settings)) {
            $custom_form_columns = array_get($settings, 'custom_form_columns');
            foreach ($custom_form_columns as &$custom_form_column) {
                // get target_form_name etc
                $target_form_name = array_get($custom_form_column, 'target_form_name');
                $form_block_target_table_name = array_get($custom_form_column, 'form_block_target_table_name');
                // find $settings->custom_tables
                if (!isset($target_form_name) || !isset($form_block_target_table_name)) {
                    continue;
                }
                // get target custom_form_blocks
                foreach ($settings['custom_form_blocks'] as &$custom_form_block) {
                    // if not match, continue
                    if ($target_form_name != array_get($custom_form_block, 'target_form_name')) {
                        continue;
                    }
                    if ($form_block_target_table_name != array_get($custom_form_block, 'form_block_target_table_name')) {
                        continue;
                    }

                    // set custom_column to $custom_table
                    $target_custom_form_columns = array_get($custom_form_block, 'custom_form_columns', []);
                    // remove custom form column form name and table name
                    array_forget($custom_form_column, 'target_form_name');
                    array_forget($custom_form_column, 'form_block_target_table_name');
                    // add array
                    $target_custom_form_columns[] = array_dot_reverse($custom_form_column);
                    $custom_form_block['custom_form_columns'] = $target_custom_form_columns;
                    // jump to next column
                    break;
                }
            }
        }
        // forget custom_columns array
        array_forget($settings, 'custom_form_columns');


        // convert custom_form_blocks to custom_forms->custom_form_blocks
        if (array_key_exists('custom_form_blocks', $settings) && array_key_exists('custom_forms', $settings)) {
            $custom_form_blocks = array_get($settings, 'custom_form_blocks');
            foreach ($custom_form_blocks as &$custom_form_block) {
                // get target_form_name etc
                $target_form_name = array_get($custom_form_block, 'target_form_name');
                if (!isset($target_form_name)) {
                    continue;
                }
                // get target custom_form_blocks
                foreach ($settings['custom_forms'] as &$custom_form) {
                    // if not match, continue
                    if ($target_form_name != array_get($custom_form, 'form_name')) {
                        continue;
                    }

                    // set custom_column to $custom_table
                    $target_custom_form_blocks = array_get($custom_form, 'custom_form_blocks', []);
                    // remove custom form column form name and table name
                    array_forget($custom_form_block, 'target_form_name');
                    $target_custom_form_blocks[] = array_dot_reverse($custom_form_block);
                    $custom_form['custom_form_blocks'] = $target_custom_form_blocks;
                    // jump to next column
                    break;
                }
            }
        }
        // forget custom_blocks array
        array_forget($settings, 'custom_form_blocks');

        $targets = ['custom_view_columns', 'custom_view_filters', 'custom_view_sorts'];
        foreach($targets as $multi){
            // convert custom_view_columns to custom_views->custom_view_columns
            if (array_key_exists($multi, $settings) && array_key_exists('custom_views', $settings)) {
                $custom_view_columns = array_get($settings, $multi);
                foreach ($custom_view_columns as &$custom_view_column) {
                    // get target_view_name etc
                    $target_view_name = array_get($custom_view_column, 'target_view_name');
                    if (!isset($target_view_name)) {
                        continue;
                    }
                    // get target views
                    foreach ($settings['custom_views'] as &$custom_view) {
                        // if not match, continue
                        if ($target_view_name != array_get($custom_view, 'target_view_name')) {
                            continue;
                        }

                        // set custom_view_column to $custom_view
                        $target_custom_view_columns = array_get($custom_view, $multi, []);
                        // remove custom view column view name
                        array_forget($custom_view_column, 'target_view_name');
                        $target_custom_view_columns[] = array_dot_reverse($custom_view_column);
                        $custom_view[$multi] = $target_custom_view_columns;
                        // jump to next column
                        break;
                    }
                }
            }
            // forget custom_view_columns array
            array_forget($settings, $multi);
        }

        // loop custom_copies and array_dot_reverse for setting options
        if (array_key_exists('custom_copies', $settings)) {
            foreach ($settings['custom_copies'] as &$custom_copy) {
                $custom_copy = array_dot_reverse($custom_copy);
            }
        }
        
        // convert custom_copy_columns to custom_copies->custom_copy_columns
        if (array_key_exists('custom_copy_columns', $settings) && array_key_exists('custom_copies', $settings)) {
            $custom_copy_columns = array_get($settings, 'custom_copy_columns');
            foreach ($custom_copy_columns as &$custom_copy_column) {
                // get target_copy_name etc
                $target_copy_name = array_get($custom_copy_column, 'target_copy_name');
                if (!isset($target_copy_name)) {
                    continue;
                }
                // get  target_copy_name
                foreach ($settings['custom_copies'] as &$custom_copy) {
                    // if not match, continue
                    if ($target_copy_name != array_get($custom_copy, 'target_copy_name')) {
                        continue;
                    }

                    // set custom_copy_column to $custom_copy
                    $target_custom_copy_columns = array_get($custom_copy, 'custom_copy_columns', []);
                    // remove custom copy column copy name
                    array_forget($custom_copy_column, 'target_copy_name');
                    $target_custom_copy_columns[] = array_dot_reverse($custom_copy_column);
                    $custom_copy['custom_copy_columns'] = $target_custom_copy_columns;
                    // jump to next column
                    break;
                }
            }
        }
        // forget custom_copy_columns array
        array_forget($settings, 'custom_copy_columns');

        return $settings;
    }

    /**
     * execute import from file
     */
    protected static function importFromFile($basePath, $system_flg=false)
    {
        // If file not exists
        if (!File::exists($basePath)) {
            // TODO:Error
        }

        // Get file
        $filestring = File::get($basePath);
        $json = json_decode($filestring, true);
        if (!isset($json)) {
            // TODO:Error
            return;
        }

        static::import($json, $system_flg);

        // get data path
        $basePath = pathinfo($basePath)['dirname'];
        $dataPath = path_join($basePath, 'data');
        // if exists, execute data copy
        if(is_dir($dataPath)){
            static::importData($dataPath);
        }
    }

    /**
     * import data using csv, xlsx
     */
    public static function importData($dataPath){
        // get all csv files
        $files = collect(\File::files($dataPath))->filter(function($value){
            return in_array(pathinfo($value)['extension'], ['csv', 'xlsx']);
        });
        
        // loop csv file
        foreach($files as $file){
            $table_name = file_ext_strip($file->getBasename());
            $format = file_ext($file->getBasename());
            $custom_table = CustomTable::getEloquent($table_name);
            if(!isset($custom_table)){
                continue;
            }

            // execute import
            $importer = DataImporterBase::getModel($custom_table, $format);
            $importer->import($file->getRealPath());
        }
    }

    /**
     * execute
     */
    public static function import($json, $system_flg = false)
    {
        DB::transaction(function () use ($json, $system_flg) {
            // Loop by tables
            foreach (array_get($json, "custom_tables") as $table) {
                // Create tables. --------------------------------------------------
                $table_name = array_get($table, 'table_name');
                $obj_table = CustomTable::firstOrNew(['table_name' => $table_name]);
                $obj_table->table_name = $table_name;
                $obj_table->description = array_get($table, 'description');
                // system flg checks 1. whether import from system, 2. table setting sets 1
                $table_system_flg = array_get($table, 'system_flg');
                $obj_table->system_flg = ($system_flg && (is_null($table_system_flg) || $table_system_flg != 0));

                // set showlist_flg
                if (!array_has($table, 'showlist_flg')) {
                    $obj_table->showlist_flg = true;
                } elseif (boolval(array_get($table, 'showlist_flg'))) {
                    $obj_table->showlist_flg = true;
                } else {
                    $obj_table->showlist_flg = false;
                }

                // if contains table view name in config
                if (array_key_value_exists('table_view_name', $table)) {
                    $obj_table->table_view_name = array_get($table, 'table_view_name');
                }
                // not exists, get lang using app config
                else {
                    $obj_table->table_view_name = exmtrans("custom_table.system_definitions.$table_name");
                }
                $obj_table->setOption(array_get($table, 'options', []));                    
                $obj_table->saveOrFail();

                // Create database table.
                $table_name = array_get($table, 'table_name');
                $obj_table->createTable();
            }

            // Re-Loop by tables and create columns
            foreach (array_get($json, "custom_tables") as $table) {
                // find tables. --------------------------------------------------
                $obj_table = CustomTable::firstOrNew(['table_name' => array_get($table, 'table_name')]);
                
                // Create columns. --------------------------------------------------
                if (array_key_exists('custom_columns', $table)) {
                    $table_columns = [];
                    foreach (array_get($table, 'custom_columns') as $column) {
                        $column_name = array_get($column, 'column_name');
                        $obj_column = CustomColumn::firstOrNew(['custom_table_id' => $obj_table->id, 'column_name' => $column_name]);
                        $obj_column->column_name = $column_name;
                        $obj_column->column_type = array_get($column, 'column_type');
                        // system flg checks 1. whether import from system, 2. table setting sets 1
                        $column_system_flg = array_get($column, 'system_flg');
                        $obj_column->system_flg = ($system_flg && (is_null($column_system_flg) || $column_system_flg != 0));

                        ///// set options
                        collect(array_get($column, 'options', []))->each(function ($option, $key) use($obj_column) {
                            $obj_column->setOption($key, $option, true);
                        });

                        // if options has select_target_table_name, get id
                        if (array_key_value_exists('options.select_target_table_name', $column)) {
                            if (is_nullorempty(array_get($column, 'options.select_target_table_name'))) {
                                $obj_column->forgetOption('select_target_table');
                            } else {
                                $custom_table = CustomTable::getEloquent(array_get($column, 'options.select_target_table_name'));
                                $id = $custom_table->id ?? null;
                                // not set id, continue
                                if (!isset($id)) {
                                    continue;
                                }
                                $obj_column->setOption('select_target_table', $id);
                            }
                        }else{
                            $obj_column->forgetOption('select_target_table');
                        }
                        $obj_column->forgetOption('select_target_table_name');

                        // set characters
                        if (array_key_value_exists('options.available_characters', $column)) {
                            $available_characters = array_get($column, 'options.available_characters');
                            // if string, convert to array
                            if (is_string($available_characters)) {
                                $obj_column->setOption('available_characters', explode(",", $available_characters));
                            }
                        }

                        ///// set view name
                        // if contains column view name in config
                        if (array_key_value_exists('column_view_name', $column)) {
                            $obj_column->column_view_name = array_get($column, 'column_view_name');
                        }
                        // not exists, get lang using app config
                        else {
                            $obj_column->column_view_name = exmtrans("custom_column.system_definitions.$column_name");
                        }

                        array_push($table_columns, $obj_column);
                    }

                    if (count($table_columns) > 0) {
                        $obj_table->custom_columns()->saveMany($table_columns);
                    }
                }

                // Create database table.
                $table_name = array_get($table, 'table_name');
                
                // alter table
                $columns = $obj_table->getSearchEnabledColumns();
                foreach ($columns as $column) {
                    $column->alterColumn();
                }
            }

            // re-loop columns. because we have to get other column id --------------------------------------------------
            foreach (array_get($json, "custom_tables") as $table) {
                // find tables. --------------------------------------------------
                $obj_table = CustomTable::firstOrNew(['table_name' => array_get($table, 'table_name')]);
                // get columns. --------------------------------------------------
                if (array_key_exists('custom_columns', $table)) {
                    foreach (array_get($table, 'custom_columns') as $column) {
                        $column_name = array_get($column, 'column_name');
                        $obj_column = CustomColumn::firstOrNew(['custom_table_id' => $obj_table->id, 'column_name' => $column_name]);
                        
                        ///// set options                        
                        // check need update
                        $update_flg = false;
                        // if column type is calc, set dynamic val
                        if (ColumnType::isCalc(array_get($column, 'column_type'))) {
                            $calc_formula = array_get($column, 'options.calc_formula', []);
                            if (is_null($calc_formula)) {
                                $obj_column->forgetOption('calc_formula');
                                continue;
                            }
                            // if $calc_formula is string, convert to json
                            if (is_string($calc_formula)) {
                                $calc_formula = json_decode($calc_formula, true);
                            }
                            if (is_array($calc_formula)) {
                                foreach ($calc_formula as &$c) {
                                    $val = $c['val'];
                                    // if dynamic or select table
                                    if (in_array(array_get($c, 'type'), ['dynamic', 'select_table'])) {
                                        $c['val'] = $obj_table->custom_columns()->where('column_name', $val)->first()->id ?? null;
                                    }
                                    
                                    // if select_table
                                    if (array_get($c, 'type') == 'select_table') {
                                        // get select table
                                        $select_table_id = CustomColumn::find($c['val'])->getOption('select_target_table') ?? null;
                                        $select_table = CustomTable::find($select_table_id) ?? null;
                                        // get select from column
                                        $from_column_id = $select_table->custom_columns()->where('column_name', array_get($c, 'from'))->first()->id ?? null;
                                        $c['from'] = $from_column_id;
                                    }
                                }
                            }
                            // set as json string
                            $obj_column->setOption('calc_formula', $calc_formula);
                            $update_flg = true;
                        }

                        if ($update_flg) {
                            $obj_column->save();
                        }
                    }
                }
            }

            // Loop relations.
            if (array_key_exists('custom_relations', $json)) {
                foreach (array_get($json, "custom_relations") as $relation) {
                    $parent_id = CustomTable::getEloquent(array_get($relation, 'parent_custom_table_name'))->id ?? null;
                    $child_id = CustomTable::getEloquent(array_get($relation, 'child_custom_table_name'))->id ?? null;
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
                    $obj_relation->relation_type = RelationType::getEnum(array_get($relation, 'relation_type'))->getValue() ?? null;
                    $obj_relation->saveOrFail();
                }
            }

            // loop for form
            if (array_key_exists('custom_forms', $json)) {
                foreach (array_get($json, "custom_forms") as $form) {
                    $table = CustomTable::getEloquent(array_get($form, 'table_name'));
                    // Create form --------------------------------------------------
                    $obj_form = CustomForm::firstOrNew([
                        'custom_table_id' => $table->id
                        ]);
                    $obj_form->form_view_name = array_get($form, 'form_view_name');
                    $obj_form->default_flg = boolval(array_get($form, 'default_flg'));
                    $obj_form->saveOrFail();

                    // Create form block
                    if (array_get($form, "custom_form_blocks")) {
                        foreach (array_get($form, "custom_form_blocks") as $form_block) {
                            // target block id
                            if (isset($form_block['form_block_target_table_name'])) {
                                $target_table = CustomTable::getEloquent($form_block['form_block_target_table_name']);
                            } else {
                                $target_table = $table;
                            }

                            // get form_block_type
                            if (isset($form_block['form_block_type'])) {
                                $form_block_type = $form_block['form_block_type'];
                            } else {
                                $self = $target_table->id == $table->id;
                                if ($self) {
                                    $form_block_type = FormBlockType::DEFAULT;
                                } else {
                                    // get relation
                                    $block_relation = CustomRelation
                                                    ::where('parent_custom_table_id', $table->id)
                                                    ->where('child_custom_table_id', $target_table->id)
                                                    ->first();
                                    if (isset($block_relation)) {
                                        $form_block_type = $block_relation->relation_type;
                                    } else {
                                        $form_block_type = FormBlockType::ONE_TO_MANY;
                                    }
                                }
                            }

                            $obj_form_block = CustomFormBlock::firstOrNew([
                            'custom_form_id' => $obj_form->id,
                            'form_block_target_table_id' => $target_table->id,
                        ]);
                            $obj_form_block->custom_form_id = $obj_form->id;
                            $obj_form_block->form_block_type = FormBlockType::getEnum($form_block_type)->getValue();
                            $obj_form_block->form_block_view_name = array_get($form_block, 'form_block_view_name');
                            $obj_form_block->form_block_target_table_id = $target_table->id;
                            if (!$obj_form_block->exists) {
                                $obj_form_block->available = true;
                            }

                            // set option
                            collect(array_get($form_block, 'options', []))->each(function ($option, $key) use($obj_form_block) {
                                $obj_form_block->setOption($key, $option, true);
                            });
                            $obj_form_block->saveOrFail();

                            // create form colunms --------------------------------------------------
                            if (array_key_exists('custom_form_columns', $form_block)) {
                                // get column counts
                                $count = count($obj_form_block->custom_form_columns);
                                foreach (array_get($form_block, "custom_form_columns") as $form_column) {
                                    //
                                    if (array_key_exists('form_column_type', $form_column)) {
                                        $form_column_type = FormColumnType::getEnum(array_get($form_column, "form_column_type"))->getValue();
                                    } else {
                                        $form_column_type = FormColumnType::COLUMN;
                                    }

                                    $form_column_name = array_get($form_column, "form_column_target_name");
                                    switch ($form_column_type) {
                                    // for table column
                                    case FormColumnType::COLUMN:
                                        // get column name
                                        $form_column_target = CustomColumn
                                            ::where('column_name', $form_column_name)
                                            ->where('custom_table_id', $target_table->id)
                                            ->first();
                                        $form_column_target_id = isset($form_column_target) ? $form_column_target->id : null;
                                        break;
                                    case FormColumnType::SYSTEM:
                                        $form_column_target_id = SystemColumn::getOption(['name' => $form_column_name])['id'] ?? null;
                                        break;
                                    default:
                                        $form_column_target_id = FormColumnType::getOption(['column_name' => $form_column_name])['id'] ?? null;
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
                                    $obj_form_column->column_no = array_get($form_column, 'column_no', 1) ?? 1;
                                
                                    // set option
                                    collect(array_get($form_column, 'options', []))->each(function ($option, $key) use($obj_form_column) {
                                        $obj_form_column->setOption($key, $option, true);
                                    });

                                    // if has changedata_column_name and changedata_target_column_name, set id
                                    if (array_key_value_exists('options.changedata_column_name', $form_column) && array_key_value_exists('options.changedata_column_table_name', $form_column)) {
                                        // get using changedata_column_table_name
                                        $id = CustomTable::getEloquent(array_get($form_column, 'options.changedata_column_table_name'))
                                            ->custom_columns()
                                            ->where('column_name', array_get($form_column, 'options.changedata_column_name'))
                                            ->first()
                                            ->id
                                        ?? null;
                                        $obj_form_column->setOption('changedata_column_id', $id);
                                        $obj_form_column->forgetOption('changedata_column_name');
                                    }
                                    if (array_key_value_exists('options.changedata_target_column_name', $form_column)) {
                                        $changedata_target_column_name = array_get($form_column, 'options.changedata_target_column_name');
                                        // get changedata target table name and column
                                        // if changedata_target_column_name value has dotted, get parent table name
                                        if (str_contains($changedata_target_column_name, ".")) {
                                            list($changedata_target_table_name, $changedata_target_column_name) = explode(".", $changedata_target_column_name);
                                            $changedata_target_table = CustomTable::getEloquent($changedata_target_table_name);
                                        } else {
                                            $changedata_target_table = $target_table;
                                            $changedata_target_column_name = $changedata_target_column_name;
                                        }
                                        $id = $changedata_target_table
                                            ->custom_columns()
                                            ->where('column_name', $changedata_target_column_name)
                                            ->first()
                                            ->id
                                        ?? null;
                                        $obj_form_column->setOption('changedata_target_column_id', $id);
                                        $obj_form_column->forgetOption('changedata_target_column_name');
                                    }
                                
                                    $obj_form_column->saveOrFail();
                                }
                            }
                        }
                    }
                }
            }
            // loop for view
            if (array_key_value_exists('custom_views', $json)) {
                foreach (array_get($json, "custom_views") as $view) {
                    $table = CustomTable::getEloquent(array_get($view, 'table_name'));
                    $findArray = [
                        'custom_table_id' => $table->id
                    ];
                    // if set suuid in json, set suuid(for dashbrord list)
                    if (array_key_value_exists('suuid', $view)) {
                        $findArray['suuid'] =  array_get($view, 'suuid');
                    } else {
                        $findArray['suuid'] =  short_uuid();
                    }
                    // Create view --------------------------------------------------
                    $obj_view = CustomView::firstOrNew($findArray);
                    $obj_view->custom_table_id = $table->id;
                    $obj_view->suuid = $findArray['suuid'];
                    $obj_view->view_type = (ViewType::getEnum(array_get($view, 'view_type')) ?? ViewType::SYSTEM())->getValue();
                    $obj_view->view_view_name = array_get($view, 'view_view_name');
                    $obj_view->default_flg = boolval(array_get($view, 'default_flg'));
                    $obj_view->saveOrFail();
                    
                    // create view columns --------------------------------------------------
                    if (array_key_exists('custom_view_columns', $view)) {
                        foreach (array_get($view, "custom_view_columns") as $view_column) {
                            $view_column_type = array_get($view_column, "view_column_type");
                            $view_column_target_id = static::getColumnIdOrName(
                                $view_column_type, 
                                array_get($view_column, "view_column_target_name"), 
                                $table,
                                true
                            );
                            // if not set column id, continue
                            if ($view_column_type != ViewColumnType::PARENT_ID && !isset($view_column_target_id)) {
                                continue;
                            }

                            $view_column_type = ViewColumnType::getEnum($view_column_type)->getValue() ?? null;
                            $obj_view_column = CustomViewColumn::firstOrNew([
                                'custom_view_id' => $obj_view->id,
                                'view_column_type' => $view_column_type,
                                'view_column_target_id' => $view_column_target_id,
                            ]);
                            $obj_view_column->order = array_get($view_column, "order");
                            $obj_view_column->saveOrFail();
                        }
                    }
                    
                    // create view filters --------------------------------------------------
                    if (array_key_exists('custom_view_filters', $view)) {
                        foreach (array_get($view, "custom_view_filters") as $view_filter) {
                            // if not set filter_target id, continue
                            $view_column_target = static::getColumnIdOrName(
                                array_get($view_filter, "view_column_type"), 
                                array_get($view_filter, "view_column_target_name"), 
                                $table,
                                true
                            );

                            if (!isset($view_column_target)) {
                                continue;
                            }

                            $view_column_type = ViewColumnType::getEnum(array_get($view_filter, "view_column_type"))->getValue() ?? null;
                            $obj_view_filter = CustomviewFilter::firstOrNew([
                                'custom_view_id' => $obj_view->id,
                                'view_column_type' => $view_column_type,
                                'view_column_target_id' => $view_column_target,
                                'view_filter_condition' => array_get($view_filter, "view_filter_condition"),
                            ]);
                            $obj_view_filter->view_filter_condition_value_text = array_get($view_filter, "view_filter_condition_value_text");
                            $obj_view_filter->saveOrFail();
                        }
                    }
                    
                    // create view sorts --------------------------------------------------
                    if (array_key_exists('custom_view_sorts', $view)) {
                        foreach (array_get($view, "custom_view_sorts") as $view_column) {
                            $view_column_target = static::getColumnIdOrName(
                                array_get($view_column, "view_column_type"), 
                                array_get($view_column, "view_column_target_name"), 
                                $table,
                                true
                            );
                            // if not set filter_target id, continue
                            if (!isset($view_column_target)) {
                                continue;
                            }

                            $view_column_type = ViewColumnType::getEnum(array_get($view_column, "view_column_type"))->getValue() ?? null;
                            $obj_view_sort = CustomviewSort::firstOrNew([
                                'custom_view_id' => $obj_view->id,
                                'view_column_type' => $view_column_type,
                                'view_column_target_id' => $view_column_target,
                            ]);
                            
                            $obj_view_sort->sort = array_get($view_column, "sort", 1);
                            $obj_view_sort->priority = array_get($view_column, "priority", 0);
                            $obj_view_sort->saveOrFail();
                        }
                    }
                }
            }

            // loop for copy
            if (array_key_value_exists('custom_copies', $json)) {
                foreach (array_get($json, "custom_copies") as $copy) {
                    $from_table = CustomTable::getEloquent(array_get($copy, 'from_custom_table_name'));
                    $to_table = CustomTable::getEloquent(array_get($copy, 'to_custom_table_name'));

                    // id not funnd
                    if (!isset($from_table) && !isset($to_table)) {
                        continue;
                    }
                    $findArray = [
                        'from_custom_table_id' => $from_table->id,
                        'to_custom_table_id' => $to_table->id,
                    ];
                    // if set suuid in json, set suuid(for dashbrord list)
                    if (array_key_value_exists('suuid', $copy)) {
                        $findArray['suuid'] =  array_get($copy, 'suuid');
                    } else {
                        $findArray['suuid'] =  short_uuid();
                    }
                    // Create copy --------------------------------------------------
                    $obj_copy = CustomCopy::firstOrNew($findArray);
                    $obj_copy->from_custom_table_id = $from_table->id;
                    $obj_copy->to_custom_table_id = $to_table->id;
                    $obj_copy->suuid = $findArray['suuid'];
                    
                    // set option
                    collect(array_get($copy, 'options', []))->each(function ($option, $key) use($obj_copy) {
                        $obj_copy->setOption($key, $option, true);
                    });
                    $obj_copy->saveOrFail();
                    
                    // create copy columns --------------------------------------------------
                    if (array_key_exists('custom_copy_columns', $copy)) {
                        foreach (array_get($copy, "custom_copy_columns") as $copy_column) {
                            // get from and to column
                            $from_column_target = static::getColumnIdOrName(
                                array_get($copy_column, "from_column_type"), 
                                array_get($copy_column, "from_column_name"), 
                                $from_table,
                                true
                            );
                            $to_column_target = static::getColumnIdOrName(
                                array_get($copy_column, "to_column_type"), 
                                array_get($copy_column, "to_column_name"), 
                                $to_table,
                                true
                            );

                            if(is_null($to_column_target)){
                                continue;
                            }

                            $from_column_type = array_get($copy_column, "from_column_type")? CopyColumnType::getEnum(array_get($copy_column, "from_column_type"))->getValue() : null;
                            $to_column_type = CopyColumnType::getEnum(array_get($copy_column, "to_column_type"))->getValue() ?? null;
                            $obj_copy_column = CustomCopyColumn::firstOrNew([
                                'custom_copy_id' => $obj_copy->id,
                                'from_column_type' => $from_column_type,
                                'from_column_target_id' => $from_column_target ?? null,
                                'to_column_type' => $to_column_type,
                                'to_column_target_id' => $to_column_target ?? null,
                                'copy_column_type' => array_get($copy_column, "copy_column_type"),
                            ]);
                            $obj_copy_column->saveOrFail();
                        }
                    }
                }
            }

            // Loop for roles.
            if (array_key_exists('roles', $json)) {
                foreach (array_get($json, "roles") as $role) {
                    // Create role. --------------------------------------------------
                    $role_type = RoleType::getEnum(array_get($role, 'role_type'))->getValue() ?? null;
                    $obj_role = Role::firstOrNew(['role_type' => $role_type, 'role_name' => array_get($role, 'role_name')]);
                    $obj_role->role_type = $role_type;
                    $obj_role->role_name = array_get($role, 'role_name');
                    $obj_role->role_view_name = array_get($role, 'role_view_name');
                    $obj_role->description = array_get($role, 'description');
                    $obj_role->default_flg = boolval(array_get($role, 'default_flg'));

                    // Create role detail.
                    if (array_key_exists('permissions', $role)) {
                        $permissions = [];
                        foreach (array_get($role, "permissions") as $permission) {
                            $permissions[$permission] = "1";
                        }
                        $obj_role->permissions = $permissions;
                    }
                    $obj_role->saveOrFail();
                }
            }

            // loop for dashboard
            if (array_key_value_exists('dashboards', $json)) {
                foreach (array_get($json, "dashboards") as $dashboard) {
                    // Create dashboard --------------------------------------------------
                    $obj_dashboard = Dashboard::firstOrNew([
                        'dashboard_name' => array_get($dashboard, "dashboard_name")
                    ]);

                    $dashboard_type = DashboardType::getEnum(array_get($dashboard, 'dashboard_type'))->getValue() ?? DashboardType::SYSTEM;
                    $obj_dashboard->dashboard_type = $dashboard_type;
                    $obj_dashboard->dashboard_view_name = array_get($dashboard, 'dashboard_view_name');
                    $obj_dashboard->setOption('row1', array_get($dashboard, 'options.row1'), 1);
                    $obj_dashboard->setOption('row2', array_get($dashboard, 'options.row2'), 2);
                    $obj_dashboard->setOption('row3', array_get($dashboard, 'options.row3'), 0);
                    $obj_dashboard->setOption('row4', array_get($dashboard, 'options.row4'), 0);
                    $obj_dashboard->default_flg = boolval(array_get($dashboard, 'default_flg'));
                    // if set suuid in json, set suuid(for dashbrord list)
                    if (array_key_value_exists('suuid', $dashboard)) {
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
                            $obj_dashboard_box->dashboard_box_type = DashboardBoxType::getEnum(array_get($dashboard_box, "dashboard_box_type"))->getValue() ?? null;

                            // set options
                            collect(array_get($dashboard_box, 'options', []))->each(function ($option, $key) use($obj_dashboard_box) {
                                $obj_dashboard_box->setOption($key, $option);
                            });
                            
                            // switch dashboard_box_type
                            switch ($obj_dashboard_box->dashboard_box_type) {
                                // system box
                                case DashboardBoxType::SYSTEM:
                                    $id = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function ($value) use ($dashboard_box) {
                                        return array_get($value, 'name') == array_get($dashboard_box, 'options.target_system_name');
                                    })['id'] ?? null;
                                    $obj_dashboard_box->setOption('target_system_id', $id);
                                    break;
                                
                                // list
                                case DashboardBoxType::LIST:
                                    // get target table
                                    $obj_dashboard_box->setOption('target_table_id', CustomTable::getEloquent(array_get($dashboard_box, 'options.target_table_name'))->id ?? null);
                                    // get target view using suuid
                                    $obj_dashboard_box->setOption('target_view_id', CustomView::findBySuuid(array_get($dashboard_box, 'options.target_view_suuid'))->id ?? null);
                                    break;
                            }

                            $obj_dashboard_box->saveOrFail();
                        }
                    }
                }
            }

            // loop for menu
            if (array_key_exists('admin_menu', $json)) {
                // order by parent_name is null, not null
                $menulist = collect(array_get($json, "admin_menu"));
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
                        if (array_key_value_exists('title', $menu)) {
                            $title = array_get($menu, 'title');
                        }
                        // title not exists, translate
                        else {
                            $translate_key = array_key_value_exists('menu_target_name', $menu) ? array_get($menu, 'menu_target_name') : array_get($menu, 'menu_name');
                            $title = exmtrans('menu.system_definitions.'.$translate_key);
                        }

                        $menu_type = MenuType::getEnum(array_get($menu, 'menu_type'))->getValue() ?? null;
                        $obj_menu = Menu::firstOrNew(['menu_name' => array_get($menu, 'menu_name'), 'parent_id' => $parent_id]);
                        $obj_menu->menu_type = $menu_type;
                        $obj_menu->menu_name = array_get($menu, 'menu_name');
                        $obj_menu->title = $title;
                        $obj_menu->parent_id = $parent_id;

                        // get menu target id
                        if (isset($menu['menu_target_id'])) {
                            $obj_menu->menu_target = $menu['menu_target_id'];
                        }
                        // get menu target id from menu_target_name
                        elseif (isset($menu['menu_target_name'])) {
                            // case plugin or table
                            switch ($menu_type) {
                                case MenuType::PLUGIN:
                                    $parent = Plugin::where('plugin_name', $menu['menu_target_name'])->first();
                                    if (isset($parent)) {
                                        $obj_menu->menu_target = $parent->id;
                                    }
                                    break;
                                case MenuType::TABLE:
                                    $parent = CustomTable::getEloquent($menu['menu_target_name']);
                                    if (isset($parent)) {
                                        $obj_menu->menu_target = $parent->id;
                                    }
                                    break;
                                case MenuType::SYSTEM:
                                    $menus = collect(Define::MENU_SYSTEM_DEFINITION)->filter(function($system_menu, $key) use($menu){
                                        return $key == $menu['menu_target_name'];
                                    })->each(function($system_menu, $key) use($obj_menu){
                                        $obj_menu->menu_target = $key;
                                    });
                                    break;
                            }
                        }

                        // get order
                        if (isset($menu['order'])) {
                            $obj_menu->order = $menu['order'];
                        } else {
                            $obj_menu->order = Menu::where('parent_id', $obj_menu->parent_id)->max('order') + 1;
                        }

                        ///// icon
                        if (isset($menu['icon'])) {
                            $obj_menu->icon = $menu['icon'];
                        }
                        // else, get icon from table, system, etc
                        else {
                            switch ($obj_menu->menu_type) {
                                case MenuType::SYSTEM:
                                    $obj_menu->icon = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".icon");
                                    break;
                                case MenuType::TABLE:
                                    $obj_menu->icon = CustomTable::getEloquent($obj_menu->menu_name)->icon ?? null;
                                    break;
                            }
                        }
                        if (is_null($obj_menu->icon)) {
                            $obj_menu->icon = '';
                        }

                        ///// uri
                        if (isset($menu['uri'])) {
                            $obj_menu->uri = $menu['uri'];
                        }
                        // else, get icon from table, system, etc
                        else {
                            switch ($obj_menu->menu_type) {
                                case MenuType::SYSTEM:
                                    $obj_menu->uri = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".uri");
                                    break;
                                case MenuType::TABLE:
                                    $obj_menu->uri = $obj_menu->menu_name;
                                    break;
                                case MenuType::TABLE:
                                    $obj_menu->uri = '#';
                                    break;
                            }
                        }

                        $obj_menu->saveOrFail();
                    }
                }
            }
        });
    }

    /**
     * get column name or id.
     * if column_type is string
     */
    protected static function getColumnIdOrName($column_type, $column_name, $custom_table = null, $returnNumber = false){
        if(!isset($column_type)){
            $column_type = ViewColumnType::COLUMN;
        }

        switch ($column_type) {
            // for table column
            case ViewColumnType::COLUMN:
                // get column name
                return CustomColumn::getEloquent($column_name, $custom_table)->id ?? null;
            // system column
            default:
                // set parent id
                if ($column_name == ViewColumnType::PARENT_ID || $column_type == ViewColumnType::PARENT_ID) {
                    return $returnNumber ? Define::CUSTOM_COLUMN_TYPE_PARENT_ID : 'parent_id';
                } 
                return SystemColumn::getOption(['name' => $column_name])[$returnNumber ? 'id' : 'name'];
        }
        return null;
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
