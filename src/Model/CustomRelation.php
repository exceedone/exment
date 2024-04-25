<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ConditionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-consistent-constructor
 * @property mixed $relation_type
 * @property mixed $parent_custom_table_id
 * @property mixed $child_custom_table_id
 * @method static int count($columns = '*')
 * @method static \Illuminate\Database\Query\Builder where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Query\Builder orderBy($column, $direction = 'asc')
 */
class CustomRelation extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;
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
                    ],
                    [
                        'replacedName' => [
                            'table_name' => 'options.parent_export_table_name',
                            'column_name' => 'options.parent_export_column_name',
                        ]
                    ],
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.parent_import_column_id', 'options.parent_export_column_id'],
            ],
        ]
    ];

    public function parent_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'parent_custom_table_id');
    }

    public function child_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'child_custom_table_id');
    }

    public function getParentCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->parent_custom_table_id);
    }
    public function getChildCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->child_custom_table_id);
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
     * get relation by parent and child table.
     */
    public static function getRelationByParentChild($parent_table, $child_table, $relation_type = null, $reget_database = false)
    {
        $parent_table = CustomTable::getEloquent($parent_table);
        $child_table = CustomTable::getEloquent($child_table);

        return static::firstRecordCache(function ($record) use ($parent_table, $child_table, $relation_type) {
            if ($record->parent_custom_table_id != array_get($parent_table, 'id')) {
                return false;
            }
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
     *
     * @return string
     */
    public function getRelationName()
    {
        return static::getRelationNameByTables($this->parent_custom_table_id, $this->child_custom_table_id);
    }

    /**
     * Get relation name using parent and child table.
     * @param CustomTable|string|null $parent
     * @param CustomTable|string|null $child
     * @return string|null
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
     * Get dynamic relation value for custom value.
     *
     * @param CustomValue $custom_value
     * @param boolean $isCallAsParent
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany|\Illuminate\Database\Eloquent\Relations\MorphMany|\Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function getDynamicRelationValue(CustomValue $custom_value, bool $isCallAsParent)
    {
        if ($isCallAsParent) {
            $child_custom_table = CustomTable::getEloquent($this->child_custom_table_id);
            $pivot_table_name = $this->getRelationName();

            // Get Parent and child table Name.
            // case 1 to many
            if ($this->relation_type == RelationType::ONE_TO_MANY) {
                return $custom_value->morphMany(getModelName($child_custom_table), 'parent');
            }
            // case many to many
            else {
                // Create pivot table
                if (!hasTable($pivot_table_name)) {
                    \Schema::createRelationValueTable($pivot_table_name);
                }

                return $custom_value->belongsToMany(getModelName($child_custom_table), $pivot_table_name, "parent_id", "child_id")->withPivot("id");
            }
        } else {
            $parent_custom_table = CustomTable::getEloquent($this->parent_custom_table_id);
            $pivot_table_name = $this->getRelationName();

            // Get Parent and child table Name.
            // case 1 to many
            if ($this->relation_type == RelationType::ONE_TO_MANY) {
                return $custom_value->belongsTo(getModelName($parent_custom_table, true), "parent_id");
            }
            // case many to many
            else {
                // Create pivot table
                if (!hasTable($pivot_table_name)) {
                    \Schema::createRelationValueTable($pivot_table_name);
                }

                return $custom_value->belongsToMany(getModelName($parent_custom_table, true), $pivot_table_name, "child_id", "parent_id")->withPivot("id");
            }
        }
    }

    /**
     * get sheet name for excel, csv
     */
    public function getSheetName()
    {
        if ($this->relation_type == RelationType::MANY_TO_MANY) {
            $sheetname = $this->parent_custom_table->table_name . '_' . $this->child_custom_table->table_name;

            // if length is too long, use id instead of name
            if (mb_strlen($sheetname) > 30) {
                return $this->parent_custom_table_id . '_' . $this->child_custom_table_id;
            } else {
                return $sheetname;
            }
        }

        return $this->child_custom_table->table_name;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentCache($id, $withs);
    }

    public function getParentImportColumnAttribute()
    {
        return CustomColumn::getEloquent($this->getOption('parent_import_column_id'));
    }

    public function getParentExportColumnAttribute()
    {
        return CustomColumn::getEloquent($this->getOption('parent_export_column_id'));
    }

    public static function importReplaceJson(&$json, $options = [])
    {
        static::importReplaceJsonCustomColumn($json, 'options.parent_import_column_id', 'options.parent_import_column_name', 'options.parent_import_table_name', $options);
        static::importReplaceJsonCustomColumn($json, 'options.parent_export_column_id', 'options.parent_export_column_name', 'options.parent_export_table_name', $options);
    }

    protected static function boot()
    {
        parent::boot();

        // saved event
        static::saved(function ($model) {
            // Create pivot table
            if ($model->relation_type != RelationType::MANY_TO_MANY) {
                return;
            }

            $pivot_table_name = $model->getRelationName();
            if (!hasTable($pivot_table_name)) {
                \Schema::createRelationValueTable($pivot_table_name);
            }
        });

        // update event
        static::updating(function ($model) {
            if ($model->isDirty('child_custom_table_id')) {
                // Delete items
                $model->deletingChildren();
            }
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
    }

    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        $target = $this->parent_custom_table;
        $original_child_id = $this->getRawOriginal('child_custom_table_id');

        // delete child form block
        foreach ($target->custom_forms as $item) {
            foreach ($item->custom_form_blocks as $block) {
                if ($block->form_block_target_table_id == $original_child_id) {
                    $block->delete();
                }
            }
        }

        // delete view column
        foreach ($target->custom_views as $item) {
            foreach ($item->custom_view_columns as $column) {
                if (ConditionType::isTableItem($column->view_column_type) &&
                    $column->view_column_table_id == $original_child_id) {
                    $column->delete();
                }
            }
            foreach ($item->custom_view_summaries as $column) {
                if (ConditionType::isTableItem($column->view_column_type) &&
                    $column->view_column_table_id == $original_child_id) {
                    $column->delete();
                }
            }
            foreach ($item->custom_view_filters as $column) {
                if (ConditionType::isTableItem($column->view_column_type) &&
                    $column->view_column_table_id == $original_child_id) {
                    $column->delete();
                }
            }
        }
    }
}
