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
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\MailTemplate;
use Maatwebsite\Excel\Facades\Excel;
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
            
            return $template_name;
        }
    }

    /**
     * upload from excel and import
     */
    public static function uploadTemplateExcel($file){
        // template file settings as json
        $settings = [];

        // loop for excel sheets
        foreach(Define::TEMPLATE_IMPORT_EXCEL_SHEETNAME as $sheetname){
            $reader = Excel::selectSheets($sheetname)->load($file->getRealPath());
            // if null(cannot find sheet), add as empty array.
            if ($reader == null)
            {
                $settings[$sheetname] = [];
                continue;
            }

            // read cell
            $sheet = $reader->getSheet();
            $data = [];
            foreach ($reader->all() as $index => $cells)
            {
                // if first row, it's view name row, so continue
                if($index == 0){continue;}
                // set row no. row no is $index + 1;
                $rowno = $index + 2;
                // check has cell value. if empty row, break
                $cell = $sheet->getCellByColumnAndRow(0,$rowno)->getValue();
                if(!isset($cell)){
                    break;
                }

                $data[] = $cells->all();
            }
        
            // set config json
            $settings[$sheetname] = $data;
        }

        // convert custom_columns to custom_tables->custom_columns
        if(array_key_exists('custom_columns', $settings) && array_key_exists('custom_tables', $settings)){
            $custom_columns = array_get($settings, 'custom_columns');
            foreach($custom_columns as &$custom_column){
                // set calc formula
                if(array_key_value_exists('options.calc_formula', $custom_column)){
                    // split new line
                    $calc_formula_strings = preg_split ('/$\R?^/m', $custom_column['options.calc_formula']);
                    $calc_formula = [];
                    foreach($calc_formula_strings as $calc_formula_string){
                        // split ","
                        $c =  explode(",", $calc_formula_string);
                        if(count($c) < 2){continue;}
                        $type = $c[0];

                        // if select table, set type, val and from
                        if($type == 'select_table'){
                            // get custom_column using target column name and from column_name
                            $vals = explode(".", $c[1]);
                            if(count($vals) < 2){continue;}
                            // set calc formula. val and from
                            $calc_formula[] = ['type' => $type, 'val' => $vals[0], 'from' => $vals[1]];
                        }
                        // if select table, set type, val and from
                        else{
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
                foreach($settings['custom_tables'] as &$custom_table){
                    if($table_name != array_get($custom_table, 'table_name')){
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
        if(array_key_exists('custom_form_columns', $settings) && array_key_exists('custom_form_blocks', $settings)){
            $custom_form_columns = array_get($settings, 'custom_form_columns');
            foreach($custom_form_columns as &$custom_form_column){
                // get target_form_name etc
                $target_form_name = array_get($custom_form_column, 'target_form_name');
                $form_block_target_table_name = array_get($custom_form_column, 'form_block_target_table_name');
                // find $settings->custom_tables
                if (!isset($target_form_name) || !isset($form_block_target_table_name)) {
                    continue;
                }
                // get target custom_form_blocks
                foreach($settings['custom_form_blocks'] as &$custom_form_block){
                    // if not match, continue
                    if($target_form_name != array_get($custom_form_block, 'target_form_name')){
                        continue;
                    }
                    if($form_block_target_table_name != array_get($custom_form_block, 'form_block_target_table_name')){
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
        if(array_key_exists('custom_form_blocks', $settings) && array_key_exists('custom_forms', $settings)){
            $custom_form_blocks = array_get($settings, 'custom_form_blocks');
            foreach($custom_form_blocks as &$custom_form_block){
                // get target_form_name etc
                $target_form_name = array_get($custom_form_block, 'target_form_name');
                if (!isset($target_form_name)) {
                    continue;
                }
                // get target custom_form_blocks
                foreach($settings['custom_forms'] as &$custom_form){
                    // if not match, continue
                    if($target_form_name != array_get($custom_form, 'form_name')){
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


        // convert custom_view_columns to custom_views->custom_view_columns
        if(array_key_exists('custom_view_columns', $settings) && array_key_exists('custom_views', $settings)){
            $custom_view_columns = array_get($settings, 'custom_view_columns');
            foreach($custom_view_columns as &$custom_view_column){
                // get target_view_name etc
                $target_view_name = array_get($custom_view_column, 'target_view_name');
                if (!isset($target_view_name)) {
                    continue;
                }
                // get target views
                foreach($settings['custom_views'] as &$custom_view){
                    // if not match, continue
                    if($target_view_name != array_get($custom_view, 'target_view_name')){
                        continue;
                    }

                    // set custom_view_column to $custom_view
                    $target_custom_view_columns = array_get($custom_view, 'custom_view_columns', []);
                    // remove custom view column view name
                    array_forget($custom_view_column, 'target_view_name');
                    $target_custom_view_columns[] = array_dot_reverse($custom_view_column);
                    $custom_view['custom_view_columns'] = $target_custom_view_columns;
                    // jump to next column
                    break;
                }
            }
        }
        // forget custom_view_columns array
        array_forget($settings, 'custom_view_columns');

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
    }

    /**
     * execute
     */
    public static function import($json, $system_flg = false)
    {
        DB::transaction(function() use($json, $system_flg){
            // Loop by tables
            foreach (array_get($json, "custom_tables") as $table) {
                // Create tables. --------------------------------------------------
                $table_name = array_get($table, 'table_name');
                $obj_table = CustomTable::firstOrNew(['table_name' => $table_name]);
                $obj_table->table_name = $table_name;
                $obj_table->description = array_get($table, 'description');
                $obj_table->icon = array_get($table, 'icon');
                $obj_table->color = array_get($table, 'color');
                $obj_table->one_record_flg = boolval(array_get($table, 'one_record_flg'));
                $obj_table->search_enabled = boolval(array_get($table, 'search_enabled'));
                $obj_table->system_flg = $system_flg;

                // set showlist_flg
                if(!array_has($table, 'showlist_flg')){
                    $obj_table->showlist_flg = true;
                }
                elseif(boolval(array_get($table, 'showlist_flg'))){
                    $obj_table->showlist_flg = true;
                }else{
                    $obj_table->showlist_flg = false;
                }

                // if contains table view name in config
                if(array_key_value_exists('table_view_name', $table)){
                    $obj_table->table_view_name = array_get($table, 'table_view_name');
                }
                // not exists, get lang using app config
                else{
                    $obj_table->table_view_name = exmtrans("custom_table.system_definitions.$table_name");
                }
                $obj_table->saveOrFail();

                // Create database table.
                $table_name = array_get($table, 'table_name');
                createTable($table_name);
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
                        $obj_column->system_flg = $system_flg;

                        ///// set options
                        $options = array_get($column, 'options', []);
                        // remove null value
                        $options = collect($options)->filter(function($option){
                            return isset($option);
                        })->toArray();

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

                        // set characters
                        if(array_key_value_exists('available_characters', $options)){
                            $available_characters = array_get($options, 'available_characters');
                            // if string, convert to array
                            if(is_string($available_characters)){
                                $options['available_characters'] = explode(",", $available_characters);
                            }
                        }

                        // remove calc_formula(after getting)
                        array_forget($options, 'calc_formula');

                        $obj_column->options = $options;

                        ///// set view name
                        // if contains column view name in config
                        if(array_key_value_exists('column_view_name', $column)){
                            $obj_column->column_view_name = array_get($column, 'column_view_name');
                        }
                        // not exists, get lang using app config
                        else{
                            $obj_column->column_view_name = exmtrans("custom_column.system_definitions.$column_name");
                        }

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
                        $options = array_get($column, 'options', []);
                        if (is_null($options)) {
                            $options = [];
                        }
                        
                        // check need update
                        $update_flg = false;
                        // if column type is calc, set dynamic val
                        if (in_array(array_get($column, 'column_type'), Define::TABLE_COLUMN_TYPE_CALC)) {
                            $calc_formula = array_get($column, 'options.calc_formula', []);
                            if(is_null($calc_formula)){continue;}
                            // if $calc_formula is string, convert to json
                            if(is_string($calc_formula)){
                                $calc_formula = json_decode($calc_formula, true);
                            }
                            if(is_array($calc_formula)){
                                foreach($calc_formula as &$c){
                                    $val = $c['val'];
                                    // if dynamic or select table
                                    if(in_array(array_get($c, 'type'), ['dynamic', 'select_table'])){
                                        $c['val'] = $obj_table->custom_columns()->where('column_name', $val)->first()->id ?? null;
                                    }
                                    
                                    // if select_table
                                    if(array_get($c, 'type') == 'select_table'){
                                        // get select table
                                        $select_table_id = array_get(CustomColumn::find($c['val']), 'options.select_target_table');
                                        $select_table = CustomTable::find($select_table_id) ?? null;
                                        // get select from column
                                        $from_column_id = $select_table->custom_columns()->where('column_name', array_get($c, 'from'))->first()->id ?? null;
                                        $c['from'] = $from_column_id;
                                    }
                                }
                            }
                            // set as json string
                            $options['calc_formula'] = json_encode($calc_formula);
                            $update_flg = true;
                        }

                        if ($update_flg) {
                            $obj_column->options = $options;
                            $obj_column->save();
                        }
                    }
                }
            }

            // Loop relations.
            if (array_key_exists('custom_relations', $json)) {
                foreach (array_get($json, "custom_relations") as $relation) {
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
            if (array_key_exists('custom_forms', $json)) {
                foreach (array_get($json, "custom_forms") as $form) {
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
                        $obj_form_block->form_block_view_name = array_get($form_block, 'form_block_view_name');
                        $obj_form_block->form_block_target_table_id = $target_table->id;
                        if (!$obj_form_block->exists) {
                            $obj_form_block->available = true;
                        }

                        // set option
                        $options = array_get($form_block, 'options', []);
                        $options = collect($options)->filter(function($option){
                            return isset($option);
                        })->toArray();
                        $obj_form_block->options = $options;

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
                                    case Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM:
                                        $form_column_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($form_column_name) {
                                            return $item['name'] == $form_column_name;
                                        });
                                        $form_column_target_id = isset($form_column_target) ? $form_column_target['id'] : null;
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
                                $obj_form_column->column_no = array_get($form_column, 'column_no', 1) ?? 1;
                                
                                $options = array_get($form_column, 'options', []);
                                $options = collect($options)->filter(function($option){
                                    return isset($option);
                                })->toArray();

                                // if has changedata_column_name and changedata_target_column_name, set id
                                if(array_key_value_exists('changedata_column_name', $options) && array_key_value_exists('changedata_column_table_name', $options)){
                                    //*caution!! Don't use where 'column_name' because if same name but other table, wrong match.
                                    //$options['changedata_column_id'] = CustomColumn::where('column_name', $options['changedata_column_name'])->first()->id?? null;
                                    // get using changedata_column_table_name
                                    $options['changedata_column_id'] = CustomTable::findByName($options['changedata_column_table_name'])->custom_columns()->where('column_name', $options['changedata_column_name'])->first()->id?? null;
                                    array_forget($options, 'changedata_column_name');
                                }
                                if(array_key_value_exists('changedata_target_column_name', $options)){
                                    //*caution!! Don't use where 'column_name' because if same name but other table, wrong match.
                                    //$options['changedata_target_column_id'] = CustomColumn::where('column_name', $options['changedata_target_column_name'])->first()->id?? null;
                                    
                                    // get changedata target table name and column
                                    // if changedata_target_column_name value has dotted, get parent table name
                                    if(str_contains($options['changedata_target_column_name'], ".")){
                                        list($changedata_target_table_name, $changedata_target_column_name) = explode(".", $options['changedata_target_column_name']);
                                        $changedata_target_table = CustomTable::findByName($changedata_target_table_name);
                                    }
                                    else{
                                        $changedata_target_table = $target_table;
                                        $changedata_target_column_name = $options['changedata_target_column_name'];
                                    }
                                    $options['changedata_target_column_id'] = $changedata_target_table->custom_columns()->where('column_name', $changedata_target_column_name)->first()->id?? null;
                                    array_forget($options, 'changedata_target_column_name');
                                }

                                $obj_form_column->options = $options;
                                
                                $obj_form_column->saveOrFail();
                            }
                        }
                    }
                }
            }
            
            // loop for view
            if (array_key_value_exists('custom_views', $json)) {
                foreach (array_get($json, "custom_views") as $view) {
                    $table = CustomTable::findByName(array_get($view, 'table_name'));
                    $findArray = [
                        'custom_table_id' => $table->id
                    ];
                    // if set suuid in json, set suuid(for dashbrord list)
                    if(array_key_value_exists('suuid', $view)){
                        $findArray['suuid'] =  array_get($view, 'suuid');
                    }else{
                        $findArray['suuid'] =  short_uuid();
                    }
                    // Create view --------------------------------------------------
                    $obj_view = Customview::firstOrNew($findArray);
                    $obj_view->custom_table_id = $table->id;
                    $obj_view->suuid = $findArray['suuid'];
                    $obj_view->view_type = array_get($view, 'view_type') ?? Define::VIEW_COLUMN_TYPE_SYSTEM;
                    $obj_view->view_view_name = array_get($view, 'view_view_name');
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
                                        ->where('custom_table_id', $table->id)
                                        ->first()->id ?? null;
                                    break;
                                // system column
                                default:
                                    // set parent id
                                    if($view_column_name == 'parent_id'){
                                        $view_column_target = 'parent_id';
                                    }else{
                                        $view_column_target = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($view_column_name) {
                                            return $item['name'] == $view_column_name;
                                        })['name'] ?? null;
                                    }
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
                            $permissions[$permission] = "1";
                        }
                        $obj_authority->permissions = $permissions;
                    }
                    $obj_authority->saveOrFail();
                }
            }

            // loop for dashboard
            if (array_key_value_exists('dashboards', $json)) {
                foreach (array_get($json, "dashboards") as $dashboard) {
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
                            $options = collect($options)->filter(function($option){
                                return isset($option);
                            })->toArray();
                            
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
                        if(array_key_value_exists('title', $menu)){
                            $title = array_get($menu, 'title');
                        }
                        // title not exists, translate
                        else{
                            $translate_key = array_key_value_exists('menu_target_name', $menu) ? array_get($menu, 'menu_target_name') : array_get($menu, 'menu_name');
                            $title = exmtrans('menu.system_definitions.'.$translate_key);
                        }

                        $obj_menu = Menu::firstOrNew(['menu_name' => array_get($menu, 'menu_name'), 'parent_id' => $parent_id]);
                        $obj_menu->menu_type = array_get($menu, 'menu_type');
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
                            switch ($menu['menu_type']) {
                                case Define::MENU_TYPE_PLUGIN:
                                    $parent = Plugin::where('plugin_name', $menu['menu_target_name'])->first();
                                    if (isset($parent)) {
                                        $obj_menu->menu_target = $parent->id;
                                    }
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $parent = CustomTable::findByName($menu['menu_target_name']);
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

                        ///// icon
                        if (isset($menu['icon'])) {
                            $obj_menu->icon = $menu['icon'];
                        }
                        // else, get icon from table, system, etc
                        else{
                            switch($obj_menu->menu_type){
                                case Define::MENU_TYPE_SYSTEM:
                                    $obj_menu->icon = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".icon");
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->icon = CustomTable::findByName($obj_menu->menu_name)->icon ?? null;
                                    break;
                            }
                        }
                        if(is_null($obj_menu->icon)){$obj_menu->icon = '';}

                        ///// uri
                        if (isset($menu['uri'])) {
                            $obj_menu->uri = $menu['uri'];
                        }
                        // else, get icon from table, system, etc
                        else{
                            switch($obj_menu->menu_type){
                                case Define::MENU_TYPE_SYSTEM:
                                    $obj_menu->uri = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".uri");
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->uri = $obj_menu->menu_name;
                                    break;
                                case Define::MENU_TYPE_TABLE:
                                    $obj_menu->uri = '#';
                                    break;
                            }
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
            

        });
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
