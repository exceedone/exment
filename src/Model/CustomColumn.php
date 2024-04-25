<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @phpstan-consistent-constructor
 * @property mixed $id
 * @property mixed $system_flg
 * @property mixed $custom_table_id
 * @property mixed $column_type
 * @property mixed $column_name
 * @property mixed $column_view_name
 * @property mixed $options
 * @property mixed $index_enabled
 * @method static bool indexEnabled()
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder whereIn($column, $values, $boolean = 'and', $not = false)
 * @method static ExtendedBuilder whereNotIn($column, $values, $boolean = 'and')
 * @method static ExtendedBuilder whereNotNull($columns, $boolean = 'and')
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder create(array $attributes = [])
 * @method static ExtendedBuilder where($column, $operator = null, $value = null, $boolean = 'and')
 */
class CustomColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;
    use Traits\UniqueKeyCustomColumnTrait;

    protected $appends = ['required', 'index_enabled', 'unique'];
    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid'];
    // protected $with = ['custom_table'];

    private $_column_item;


    /**
     * $custom available_characters
     * @var array
     */
    protected static $customAvailableCharacters = [];


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

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_columns(): HasMany
    {
        return $this->hasMany(CustomFormColumn::class, 'form_column_target_id')
            ->where('form_column_type', FormColumnType::COLUMN);
    }

    public function custom_view_columns(): HasMany
    {
        return $this->hasMany(CustomViewColumn::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_sorts(): HasMany
    {
        return $this->hasMany(CustomViewSort::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_filters(): HasMany
    {
        return $this->hasMany(CustomViewFilter::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_summaries(): HasMany
    {
        return $this->hasMany(CustomViewSummary::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_view_grid_filters(): HasMany
    {
        return $this->hasMany(CustomViewGridFilter::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function custom_operation_columns(): HasMany
    {
        return $this->hasMany(CustomOperationColumn::class, 'view_column_target_id')
            ->where('view_column_type', ConditionType::COLUMN);
    }

    public function conditions(): HasMany
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

    public function scopeSelectTargetTable($query, $custom_table_id)
    {
        // check user or org table.
        $select_target_table = CustomTable::getEloquent($custom_table_id);
        if ($select_target_table && in_array($select_target_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            // if target is user or org, set filter as column_type.
            return $query->where('column_type', $select_target_table->table_name);
        }

        return $query->whereIn('options->select_target_table', [$custom_table_id, strval($custom_table_id)]);
    }

    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this);
    }

    public function getColumnItemAttribute()
    {
        if (isset($this->_column_item)) {
            return $this->_column_item;
        }

        $this->_column_item = ColumnItems\CustomItem::getItem($this);
        return $this->_column_item;
    }

    public function getSelectTargetTableAttribute()
    {
        if (ColumnType::isUserOrganization($this->column_type)) {
            return CustomTable::getEloquent($this->column_type);
        }
        return CustomTable::getEloquent($this->getOption('select_target_table'));
    }
    public function getSelectTargetViewAttribute()
    {
        return CustomView::getEloquent($this->getOption('select_target_view'));
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

    public function deletingChildren()
    {
        $this->custom_form_columns()->delete();
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
        $this->custom_view_sorts()->delete();
        $this->custom_view_summaries()->delete();
        $this->custom_view_grid_filters()->delete();
        $this->custom_operation_columns()->delete();
        $this->conditions()->delete();

        // remove reference with pivot column
        $items = CustomViewColumn::where(function ($query) {
            $query->where('options->view_pivot_column_id', strval($this->id))
                ->where('options->view_pivot_table_id', $this->custom_table_id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });
        $items = CustomViewSummary::where(function ($query) {
            $query->where('options->view_pivot_column_id', strval($this->id))
                ->where('options->view_pivot_table_id', $this->custom_table_id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });
        $items = CustomViewFilter::where(function ($query) {
            $query->where('options->view_pivot_column_id', strval($this->id))
                ->where('options->view_pivot_table_id', $this->custom_table_id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });
        $items = CustomViewSort::where(function ($query) {
            $query->where('options->view_pivot_column_id', strval($this->id))
                ->where('options->view_pivot_table_id', $this->custom_table_id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });
        $items = CustomViewGridFilter::where(function ($query) {
            $query->where('options->view_pivot_column_id', strval($this->id))
                ->where('options->view_pivot_table_id', $this->custom_table_id);
        })->get();
        $items->each(function ($item) {
            $item->delete();
        });

        // remove multisettings
        $items = CustomColumnMulti::where(function ($query) {
            $query->where('options->table_label_id', strval($this->id))
                ->orWhere('options->unique1_id', strval($this->id))
                ->orWhere('options->unique2_id', strval($this->id))
                ->orWhere('options->unique3_id', strval($this->id));
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

        static::saved(function ($model) {
            // create or drop index --------------------------------------------------
            $model->alterColumn();
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();

            // execute alter column
            $model->alterColumn(true);
        });

        // deleted event
        static::deleted(function ($model) {
            $model->custom_table_cache->getValueQuery()->
                updateRemovingJsonKey("value->{$model->column_name}");
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
        /** @phpstan-ignore-next-line unreachable statement */
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
        $column_type = $this->column_item->getVirtualColumnTypeName();

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
            \Schema::alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name, $this);
            System::clearCache();
        }
        // checking multiple enabled change
        else {
            $original = jsonToArray($this->getOriginal('options'));
            if (boolval(array_get($original, 'multiple_enabled')) !== boolval(array_get($this, 'options.multiple_enabled'))) {
                \Schema::dropIndexColumn($db_table_name, $db_column_name, $index_name);
                \Schema::alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name, $this);
                System::clearCache();
            }
        }
    }

    /**
     * Get index column column name. This function uses only search-enabled column.
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
     * Get where query. index name or value->XXXX
     *
     * @return string database query key.
     */
    public function getQueryKey()
    {
        return $this->index_enabled ? $this->getIndexColumnName() : 'value->' . $this->column_name;
    }

    /**
     * Get font awesome class
     *
     * @return string|null
     */
    public function getFontAwesomeClass(): ?string
    {
        return ColumnType::getFontAwesomeClass($this);
    }

    /**
     * Is get all user or org. Not filtering display table.
     *
     * @return boolean
     */
    public function isGetAllUserOrganization()
    {
        return ColumnType::isUserOrganization($this->column_type) && boolval($this->getOption('showing_all_user_organizations'));
    }


    /**
     * Whether this column is isMultipleEnabled
     *
     * @return boolean
     */
    public function isMultipleEnabled()
    {
        return ColumnType::isMultipleEnabled($this) && boolval($this->getOption('multiple_enabled'));
    }

    /**
     * Set customAvailableCharacters
     *
     * @param array $array
     * @return void
     */
    public static function customAvailableCharacters(array $array)
    {
        static::$customAvailableCharacters = array_merge($array, static::$customAvailableCharacters);
    }

    /**
     * Get AvailableCharacters Definitions.
     * Default and Append custom.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAvailableCharacters()
    {
        ///// get system definitions
        $results = collect(Define::CUSTOM_COLUMN_AVAILABLE_CHARACTERS)->map(function ($val) {
            return [
                'key' => $val['key'],
                'regex' => $val['regex'],
                'label' => exmtrans("custom_column.available_characters.{$val['key']}"),
            ];
        });

        ///// add user definitions
        /** @var Collection $results */
        $results = $results->merge(
            collect(static::$customAvailableCharacters)->map(function ($val) {
                return [
                    'key' => $val['key'],
                    'regex' => $val['regex'],
                    'label' => $val['label'],
                ];
            })
        );
        return $results;
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
            if (!is_nullorempty($str) && mb_strlen($str) > 0) {
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
                $key = mbTrim($splits[0]);
                $val = count($splits) > 1 ? mbTrim($splits[1]) : mbTrim($splits[0]);
            } else {
                $key = mbTrim($item);
                $val = mbTrim($item);
            }

            $options[$key] = $val;
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
    public static function importTemplateLinkage($json, $is_update, $options = [])
    {
        $custom_table = array_get($options, 'parent');
        $column_name = array_get($json, 'column_name');

        $obj_column = CustomColumn::firstOrNew([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name
        ]);

        // importReplaceJsonCustomColumn using import and update column
        $update_flg = false;
        if (static::importReplaceJsonCustomColumn($json, 'options.select_import_column_id', 'options.select_import_column_name', 'options.select_import_table_name', $options)) {
            $update_flg = true;
            $obj_column->setOption('select_import_column_id', array_get($json, 'options.select_import_column_id'));
        }
        if (static::importReplaceJsonCustomColumn($json, 'options.select_export_column_id', 'options.select_export_column_name', 'options.select_export_table_name', $options)) {
            $update_flg = true;
            $obj_column->setOption('select_export_column_id', array_get($json, 'options.select_export_column_id'));
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
}
