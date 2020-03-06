<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CalcFormulaType;
use Exceedone\Exment\Enums\ConditionType;
use Illuminate\Support\Facades\DB;

class CustomColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    use Traits\UniqueKeyCustomColumnTrait;

    protected $appends = ['required', 'index_enabled', 'unique'];
    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid'];
    // protected $with = ['custom_table'];


    public static $templateItems = [
        'excepts' => ['suuid', 'required', 'index_enabled', 'unique', 'custom_table'],
        'uniqueKeys' => [
            'export' => [
                'custom_table.table_name', 'column_name'
            ],
            'import' => [
                'custom_table_id', 'column_name'
            ],
        ],
        'langs' => [
            'keys' => ['column_name'],
            'values' => ['column_view_name', 'description', 'options.help', 'options.placeholder', 'options.select_item_valtext'],
        ],
        'parent' => 'custom_table_id',
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.select_target_table',
                        'replacedName' => [
                            'table_name' => 'options.select_target_table_name',
                        ],
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.select_target_view',
                        'replacedName' => [
                            'suuid' => 'options.select_target_view_suuid',
                        ],
                    ]
                ],
                'uniqueKeyClassName' => CustomView::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.select_import_table_name',
                            'column_name' => 'options.select_import_column_name',
                        ]
                    ],
                    [
                        'replacedName' => [
                            'table_name' => 'options.select_export_table_name',
                            'column_name' => 'options.select_export_column_name',
                        ]
                    ], 
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.select_import_column_id', 'options.select_export_column_id'],
            ],
        ]
    ];

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_columns()
    {
        return $this->hasMany(CustomFormColumn::class, 'form_column_target_id')
            ->where('form_column_type', FormColumnType::COLUMN);
    }

    public function custom_view_columns()
    {
        return $this->hasMany(CustomViewColumn::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_sorts()
    {
        return $this->hasMany(CustomViewSort::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_filters()
    {
        return $this->hasMany(CustomViewFilter::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_summaries()
    {
        return $this->hasMany(CustomViewSummary::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_operation_columns()
    {
        return $this->hasMany(CustomOperationColumn::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function conditions()
    {
        return $this->hasMany(Condition::class, 'target_column_id')
            ->where('condition_type', ConditionType::COLUMN);
    }

    public function scopeIndexEnabled($query)
    {
        return $query->whereIn('options->index_enabled', [1, "1", true]);
    }

    public function scopeRequired($query)
    {
        return $query->whereIn('options->required', [1, "1", true]);
    }

    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this);
    }

    public function getColumnItemAttribute()
    {
        return ColumnItems\CustomItem::getItem($this);
    }

    public function getSelectTargetTableAttribute()
    {
        if (ColumnType::isUserOrganization($this->column_type)) {
            return CustomTable::getEloquent($this->column_type);
        }
        return CustomTable::getEloquent($this->getOption('select_target_table'));
    }

    public function getRequiredAttribute()
    {
        return $this->getOption('required', false);
    }

    public function getIndexEnabledAttribute()
    {
        return $this->getOption('index_enabled', false);
    }

    public function getUniqueAttribute()
    {
        return $this->getOption('unique', false);
    }

    public function setRequiredAttribute($value)
    {
        return $this->setOption('required', $value);
    }

    public function setIndexEnabledAttribute($value)
    {
        return $this->setOption('index_enabled', $value);
    }

    public function setUniqueAttribute($value)
    {
        return $this->setOption('unique', $value);
    }

    public function getSelectImportColumnAttribute()
    {
        return CustomColumn::getEloquent($this->getOption('select_import_column_id'));
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->system_flg);
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    public function deletingChildren()
    {
        $this->custom_form_columns()->delete();
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
        $this->custom_view_sorts()->delete();
        $this->custom_view_summaries()->delete();
        $this->custom_operation_columns()->delete();
        $this->conditions()->delete();

        // remove multisettings
        $items = CustomColumnMulti::where(function ($query) {
            $query->where('options->table_label_id', $this->id)
                ->orWhere('options->unique1_id', $this->id)
                ->orWhere('options->unique2_id', $this->id)
                ->orWhere('options->unique3_id', $this->id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });
    }

    protected static function boot()
    {
        parent::boot();
                
        // add default order
        static::addGlobalScope(new OrderScope('order'));

        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();

            // execute alter column
            $model->alterColumn(true);
        });
    }

    /**
     * get custom column eloquent. (use table)
     */
    public static function getEloquent($column_obj, $table_obj = null)
    {
        if (!isset($column_obj)) {
            return null;
        }

        // get column eloquent model
        if ($column_obj instanceof CustomColumn) {
            return $column_obj;
        }

        if ($column_obj instanceof \stdClass) {
            $column_obj = array_get((array)$column_obj, 'id');
        }
        
        if (is_array($column_obj)) {
            $column_obj = array_get($column_obj, 'id');
        }
        
        if (is_numeric($column_obj)) {
            return static::allRecordsCache(function ($record) use ($column_obj) {
                return $record->id == $column_obj;
            })->first();
        }
        // else,call $table_obj
        else {
            // get table Eloquent
            $table_obj = CustomTable::getEloquent($table_obj);
            // if not exists $table_obj, return null.
            if (!isset($table_obj)) {
                return null;
            }
            
            return static::allRecordsCache(function ($record) use ($table_obj, $column_obj) {
                return $record->column_name == $column_obj && $record->custom_table_id == $table_obj->id;
            })->first();
        }
        return null;
    }
    
    /**
     * Alter table column
     * For add table virtual column
     * @param bool $forceDropIndex drop index. calling when remove column.
     */
    public function alterColumn($forceDropIndex = false)
    {
        // Create index --------------------------------------------------
        $table = $this->custom_table_cache;
        $column_name = $this->column_name;

        //DB table name
        $db_table_name = getDBTableName($table);
        $db_column_name = $this->getIndexColumnName(false);

        // Create table
        $table->createTable();

        // get whether index_enabled column
        $index_enabled = $this->index_enabled;
        
        // check table column field exists.
        $exists = hasColumn($db_table_name, $db_column_name);

        $index_name = "index_$db_column_name";
        //  if index_enabled = false, and exists, then drop index
        // if column exists and (index_enabled = false or forceDropIndex)
        if ($exists && ($forceDropIndex || (!boolval($index_enabled)))) {
            \Schema::dropIndexColumn($db_table_name, $db_column_name, $index_name);
            System::clearCache();
        }
        // if index_enabled = true, not exists, then create index
        elseif ($index_enabled && !$exists) {
            \Schema::alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name);
            System::clearCache();
        }
    }
    
    /**
     * Get index column column name. This function uses only search-enabled column.
     * @param CustomColumn|array $obj
     * @param boolean $alterColumn if not exists column on db, execute alter column. if false, only get name
     * @return string
     */
    public function getIndexColumnName($alterColumn = true)
    {
        $name = 'column_'.array_get($this, 'suuid');
        $db_table_name = getDBTableName($this->custom_table_cache);

        // if not exists, execute alter column
        if ($alterColumn && !hasColumn($db_table_name, $name)) {
            $this->alterColumn();
        }
        return $name;
    }

    
    /**
     * Get select table relation name.
     * @param CustomColumn|array $obj
     * @param boolean $alterColumn if not exists column on db, execute alter column. if false, only get name
     * @return string
     */
    public function getSelectTableRelationName()
    {
        $name = 'select_table_'.array_get($this->custom_table_cache, 'suuid') . '_' . $this->select_target_table->suuid . '_' . $this->id;
        return $name;
    }

    /**
     * Create laravel-admin select box options. for column_type "select", "select_valtext"
     */
    public function createSelectOptions()
    {
        // get value
        $column_type = array_get($this, 'column_type');
        $column_options = array_get($this, 'options');

        // get select item string
        $array_get_key = $column_type == 'select' ? 'select_item' : 'select_item_valtext';
        $select_item = array_get($column_options, $array_get_key);
        $isValueText = ($column_type == 'select_valtext');
        
        $options = [];
        if (is_null($select_item)) {
            return $options;
        }

        if (is_string($select_item)) {
            $str = str_replace(array("\r\n","\r","\n"), "\n", $select_item);
            if (isset($str) && mb_strlen($str) > 0) {
                // loop for split new line
                $array = explode("\n", $str);
                foreach ($array as $a) {
                    $this->setSelectOptionItem($a, $options, $isValueText);
                }
            }
        } elseif (is_array($select_item)) {
            foreach ($select_item as $key => $value) {
                $this->setSelectOptionItem($value, $options, $isValueText);
            }
        }

        return $options;
    }
    
    /**
     * Create laravel-admin select box option item.
     */
    protected function setSelectOptionItem($item, &$options, $isValueText)
    {
        if (is_string($item)) {
            // $isValueText is true(split comma)
            if ($isValueText) {
                $splits = explode(',', $item);
                if (count($splits) > 1) {
                    $options[mbTrim($splits[0])] = mbTrim($splits[1]);
                } else {
                    $options[mbTrim($splits[0])] = mbTrim($splits[0]);
                }
            } else {
                $options[mbTrim($item)] = mbTrim($item);
            }
        }
    }

    protected function importSetValue(&$json, $options = [])
    {
        // set characters
        if (array_key_value_exists('options.available_characters', $json)) {
            $available_characters = array_get($json, 'options.available_characters');
            // if string, convert to array
            if (is_string($available_characters)) {
                $this->setOption('available_characters', explode(",", $available_characters));
            }
        }

        //return expects array
        return ['options.available_characters'];
    }
    
    public function importSaved($json, $options = [])
    {
        if (!$this->index_enabled) {
            return $this;
        }
        $this->alterColumn();

        return $this;
    }

    /**
     * import template (for setting other custom column id)
     */
    public static function importTemplateRelationColumn($json, $is_update, $options = [])
    {
        $custom_table = array_get($options, 'parent');
        $column_name = array_get($json, 'column_name');

        $obj_column = CustomColumn::firstOrNew([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name
        ]);
        
        // importReplaceJsonCustomColumn using import and update column
        $importExportColumn = false;
        if(static::importReplaceJsonCustomColumn($json, 'options.select_import_column_id', 'options.select_import_column_name', 'options.select_import_table_name', $options)){
            $importExportColumn = true;
            $obj_column->setOption('select_import_column_id', array_get($json, 'options.select_import_column_id'));
        }
        if(static::importReplaceJsonCustomColumn($json, 'options.select_export_column_id', 'options.select_export_column_name', 'options.select_export_table_name', $options)){
            $importExportColumn = true;
            $obj_column->setOption('select_export_column_id', array_get($json, 'options.select_export_column_id'));
        }
        if($importExportColumn){
            $obj_column->save();
        }

        // if record is already exists skip process, when update
        if ($is_update && $obj_column->exists) {
            return $obj_column;
        }
        
        ///// set options
        // check need update
        $update_flg = false;
        // if column type is calc, set dynamic val
        if (ColumnType::isCalc(array_get($json, 'column_type'))) {
            $calc_formula = array_get($json, 'options.calc_formula');
            if (is_null($calc_formula)) {
                $obj_column->forgetOption('calc_formula');
            }
            // if $calc_formula is string, convert to json
            if (is_string($calc_formula)) {
                $calc_formula = json_decode($calc_formula, true);
            }
            if (is_array($calc_formula)) {
                foreach ($calc_formula as &$c) {
                    // if dynamic or select table
                    if (in_array(array_get($c, 'type'), [CalcFormulaType::DYNAMIC, CalcFormulaType::SELECT_TABLE])) {
                        $c['val'] = static::getEloquent($c['val'], $custom_table)->id ?? null;
                    }
                    
                    // if select_table
                    if (array_get($c, 'type') == CalcFormulaType::SELECT_TABLE) {
                        // get select table
                        $select_table_column = static::getEloquent($c['val']);
                        if (isset($select_table_column)) {
                            $select_table_id = $select_table_column->getOption('select_target_table') ?? null;
                            // get select from column
                            $from_column = static::getEloquent(array_get($c, 'from'), $select_table_id);
                            $c['from'] = $from_column->id ?? null;
                        }
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
        return $obj_column;
    }

    /**
     * import template (for setting other custom view id)
     */
    public static function importTemplateTargetView($json, $is_update, $options = [])
    {
        $custom_table = array_get($options, 'parent');
        $column_name = array_get($json, 'column_name');

        $obj_column = CustomColumn::firstOrNew([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name
        ]);

        // if record is already exists skip process, when update
        if ($is_update && $obj_column->exists) {
            return $obj_column;
        }

        ///// set view
        $update_flg = false;
        if (!is_null(array_get($json, 'options.select_target_view_suuid'))) {
            $view = CustomView::where('suuid', array_get($json, 'options.select_target_view_suuid'))->first();
            if (isset($view)) {
                $obj_column->setOption('select_target_view', $view->id);
                $update_flg = true;
            }
        }

        if ($update_flg) {
            $obj_column->save();
        }
        return $obj_column;
    }

    /**
     * Perform special processing when outputting template
     */
    protected function replaceTemplateSpecially($array)
    {
        // if column_type is calc, change value dynamic name using calc_formula property
        if (!ColumnType::isCalc(array_get($this, 'column_type'))) {
            return $array;
        }

        $calc_formula = array_get($this, 'options.calc_formula');
        if (!isset($calc_formula)) {
            return $array;
        }

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
                $calc_formula_column_name = static::getEloquent(array_get($c, 'val'))->column_name ?? null;
                // set value
                $c['val'] = $calc_formula_column_name;
            }
        }

        array_set($array, 'options.calc_formula', $calc_formula);
        
        return $array;
    }
}
