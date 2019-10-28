<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\RelationType;

class CustomRelation extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\UniqueKeyCustomColumnTrait;

    //protected $with = ['parent_custom_table', 'child_custom_table'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['parent_custom_table', 'child_custom_table'],
        'langs' => [
            'keys' => ['parent_custom_table_name', 'child_custom_table_name'],
            'values' => ['view_view_name'],
        ],
        'uniqueKeys' => [
            'export' => ['parent_custom_table_name', 'child_custom_table_name'],
            'import' => ['parent_custom_table_id', 'child_custom_table_id'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'parent_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'parent_custom_table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'child_custom_table_id',
                        'replacedName' => [
                            'table_name' => 'child_custom_table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.parent_import_table_name',
                            'column_name' => 'options.parent_import_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.parent_import_column_id'],
            ],
        ]
    ];
    
    public function parent_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'parent_custom_table_id');
    }

    public function child_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'child_custom_table_id');
    }

    /**
     * get relations by parent table
     */
    public static function getRelationsByParent($parent_table, $relation_type = null, $reget_database = false)
    {
        $parent_table = CustomTable::getEloquent($parent_table);

        return static::allRecordsCache(function ($record) use ($parent_table, $relation_type) {
            if ($record->parent_custom_table_id != array_get($parent_table, 'id')) {
                return false;
            }
            if (isset($relation_type) && $record->relation_type != $relation_type) {
                return false;
            }
            return true;
        }, $reget_database);
    }

    /**
     * get relation by child table. (Only one record)
     */
    public static function getRelationByChild($child_table, $relation_type = null, $reget_database = false)
    {
        $items = static::getRelationsByChild($child_table, $relation_type, $reget_database);
        if (isset($items)) {
            return $items->first();
        }
        return null;
    }

    /**
     * get relation by child table.
     */
    public static function getRelationsByChild($child_table, $relation_type = null, $reget_database = false)
    {
        $child_table = CustomTable::getEloquent($child_table);

        return static::allRecordsCache(function ($record) use ($child_table, $relation_type) {
            if ($record->child_custom_table_id != array_get($child_table, 'id')) {
                return false;
            }
            if (isset($relation_type) && $record->relation_type != $relation_type) {
                return false;
            }
            return true;
        }, $reget_database);
    }

    /**
     * Get relation name.
     * @param CustomRelation $relation_obj
     * @return string
     */
    public function getRelationName()
    {
        return static::getRelationNameByTables($this->parent_custom_table_id, $this->child_custom_table_id);
    }

    /**
     * Get relation name using parent and child table.
     * @param $parent
     * @param $child
     * @return string
     */
    public static function getRelationNamebyTables($parent, $child)
    {
        $parent_suuid = CustomTable::getEloquent($parent)->suuid ?? null;
        $child_suuid = CustomTable::getEloquent($child)->suuid ?? null;
        if (is_null($parent_suuid) || is_null($child_suuid)) {
            return null;
        }
        return "pivot__{$parent_suuid}_{$child_suuid}";
    }

    /**
     * get sheet name for excel, csv
     */
    public function getSheetName()
    {
        if ($this->relation_type == RelationType::MANY_TO_MANY) {
            return $this->parent_custom_table->table_name . '_' . $this->child_custom_table->table_name;
        }
        return $this->child_custom_table->table_name;
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
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
    
    public function getParentImportColumnAttribute()
    {
        return CustomColumn::getEloquent($this->getOption('parent_import_column_id'));
    }
    
    public static function importReplaceJson(&$json, $options = [])
    {
        static::importReplaceJsonCustomColumn($json, 'options.parent_import_column_id', 'options.parent_import_column_name', 'options.parent_import_table_name', $options);
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::saved(function ($model) {
            $model->clearCache();
        });
    }

    /**
     * Clear cache
     *
     * @return void
     */
    public function clearCache()
    {
        static::resetAllRecordsCache();
    }
    
}
