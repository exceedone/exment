<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\CompareColumnType;
use Exceedone\Exment\Enums\FilterOption;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom column multiple settings
 *
 * @property mixed $custom_table_id
 * @property mixed $multisetting_type
 * @phpstan-consistent-constructor
 */
class CustomColumnMulti extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;
    use Traits\UniqueKeyCustomColumnTrait;

    protected $appends = ['unique1', 'unique2', 'unique3', 'compare_column1_id', 'compare_column2_id', 'compare_type', 'table_label_id', 'share_trigger_type', 'share_column_id', 'share_permission'];
    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid'];
    protected $table = 'custom_column_multisettings';

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public static $templateItems = [
        'excepts' => [
            'export' => [
                'unique1', 'unique2', 'unique3', 'share_column_id', 'compare_type', 'options.unique1_id', 'options.unique2_id', 'options.unique3_id', 'options.compare_column1_id', 'options.compare_column2_id', 'options.table_label_id', 'options.share_trigger_type', 'options.share_column_id', 'options.share_permission'
            ],
            'import' => [
                'custom_table_id', 'column_name'
            ],
        ],
        'uniqueKeys' => [
            'export' => [
                'custom_table.table_name', 'multisetting_type', 'suuid'
            ],
            'import' => [
                'custom_table_id', 'multisetting_type', 'suuid'
            ],
        ],
        'langs' => [
            'keys' => [],
            'values' => [],
        ],
        'parent' => 'custom_table_id',
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.unique1_table_name',
                            'column_name' => 'options.unique1_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['unique1'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.unique2_table_name',
                            'column_name' => 'options.unique2_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['unique2'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.unique3_table_name',
                            'column_name' => 'options.unique3_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['unique3'],
            ],

            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.compare_column1_table_name',
                            'column_name' => 'options.compare_column1_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['compare_column1_id'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.compare_column2_table_name',
                            'column_name' => 'options.compare_column2_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['compare_column2_id'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.share_table_name',
                            'column_name' => 'options.share_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['share_column_id'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.table_label_table_name',
                            'column_name' => 'options.table_label_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['table_label_id'],
            ],
        ]
    ];

    public function getUnique1Attribute()
    {
        return $this->getOption('unique1_id');
    }
    public function setUnique1Attribute($unique1)
    {
        $this->setOption('unique1_id', $unique1);
        return $this;
    }

    public function getUnique2Attribute()
    {
        return $this->getOption('unique2_id');
    }
    public function setUnique2Attribute($unique2)
    {
        $this->setOption('unique2_id', $unique2);
        return $this;
    }

    public function getUnique3Attribute()
    {
        return $this->getOption('unique3_id');
    }
    public function setUnique3Attribute($unique3)
    {
        $this->setOption('unique3_id', $unique3);
        return $this;
    }

    public function getShareTriggerTypeAttribute()
    {
        return $this->getOption('share_trigger_type');
    }
    public function setShareTriggerTypeAttribute($share_trigger_type)
    {
        $this->setOption('share_trigger_type', $share_trigger_type);
        return $this;
    }

    public function getShareColumnIdAttribute()
    {
        return $this->getOption('share_column_id');
    }
    public function setShareColumnIdAttribute($share_column_id)
    {
        $this->setOption('share_column_id', $share_column_id);
        return $this;
    }

    public function getSharePermissionAttribute()
    {
        return $this->getOption('share_permission');
    }
    public function setSharePermissionAttribute($share_permission)
    {
        $this->setOption('share_permission', $share_permission);
        return $this;
    }

    public function getCompareColumn1IdAttribute()
    {
        return $this->getOption('compare_column1_id');
    }
    public function setCompareColumn1IdAttribute($compare_column)
    {
        $this->setOption('compare_column1_id', $compare_column);
        return $this;
    }

    public function getCompareColumn2IdAttribute()
    {
        return $this->getOption('compare_column2_id');
    }
    public function setCompareColumn2IdAttribute($compare_column)
    {
        $this->setOption('compare_column2_id', $compare_column);
        return $this;
    }

    public function getShareColumnAttribute()
    {
        return CustomColumn::getEloquent($this->share_column_id);
    }

    public function getCompareColumn1Attribute()
    {
        return CustomColumn::getEloquent($this->compare_column1_id);
    }

    public function getCompareColumn2Attribute()
    {
        if (in_array($this->compare_column2_id, CompareColumnType::arrays())) {
            return $this->compare_column2_id;
        }
        return CustomColumn::getEloquent($this->compare_column2_id);
    }

    public function getCompareTypeAttribute()
    {
        return $this->getOption('compare_type');
    }
    public function setCompareTypeAttribute($unique1)
    {
        $this->setOption('compare_type', $unique1);
        return $this;
    }


    public function getTableLabelIdAttribute()
    {
        return $this->getOption('table_label_id');
    }
    public function setTableLabelIdAttribute($value)
    {
        $this->setOption('table_label_id', $value);
        return $this;
    }


    /**
     * Compare two value.
     *
     * @param array $input
     * @param CustomValue|null $custom_value
     * @return bool
     */
    public function compareValue($input, $custom_value = null, array $options = [])
    {
        $column1 = $this->compare_column1;
        $column2 = $this->compare_column2;

        $options = array_merge([
            'addValue' => true, // add value. to column name
        ], $options);

        if (!isset($column1) || !isset($column2)) {
            return true;
        }

        // get value function
        $getValueFunc = function ($input, $column, $custom_value) use ($options) {
            if (is_string($column)) {
                return CompareColumnType::getCompareValue($column);
            }

            $prefix = $options['addValue'] ? 'value.' : '';

            // if key has value in input
            if (array_has($input, "$prefix{$column->column_name}")) {
                return array_get($input, "$prefix{$column->column_name}");
            }

            // if not has, get from custom value
            if (!isset($custom_value) || !$custom_value->exists) {
                return null;
            }

            return array_get($custom_value, 'value.' . $column->column_name);
        };

        $value1 = $getValueFunc($input, $column1, $custom_value);
        $value2 = $getValueFunc($input, $column2, $custom_value);

        switch ($this->compare_type) {
            case FilterOption::EQ:
                if (empty($value1)) {
                    if (empty($value2)) {
                        return true;
                    }
                } elseif (!empty($value2)) {
                    if ($value1 == $value2) {
                        return true;
                    }
                }

                return $this->getCompareErrorMessage('validation.not_match', $column1, $column2);

            case FilterOption::NE:
                if (empty($value1)) {
                    if (!empty($value2)) {
                        return true;
                    }
                } elseif (empty($value2)) {
                    return true;
                } else {
                    if ($value1 != $value2) {
                        return true;
                    }
                }

                return $this->getCompareErrorMessage('validation.not_notmatch', $column1, $column2);
            default:
                if (empty($value1) || empty($value2)) {
                    return true;
                }

                return $column1->column_item->compareTwoValues($this, $value1, $value2);
        }
    }

    public function getCompareErrorMessage($transKey, $column1, $column2)
    {
        $attribute1 = null;
        $attribute2 = null;
        if ($column1 instanceof CustomColumn) {
            $attribute1 = $column1->column_view_name;
        }
        if ($column2 instanceof CustomColumn) {
            $attribute2 = $column2->column_view_name;
        } elseif (is_string($column2)) {
            $enum = CompareColumnType::getEnum($column2);
            if ($enum) {
                $attribute2 = $enum->transKey('custom_table.custom_column_multi.compare_column_options');
            }
        }
        return exmtrans($transKey, [
            'attribute1' => $attribute1,
            'attribute2' => $attribute2,
        ]);
    }


    // Template Output ----------------------------------------

    /**
     * Set json value calling import
     *
     * @param array $json
     * @param array $options
     */
    protected static function importReplaceJson(&$json, $options = [])
    {
        static::importReplaceJsonTableColumn('unique1', $json);
        static::importReplaceJsonTableColumn('unique2', $json);
        static::importReplaceJsonTableColumn('unique3', $json);
        static::importReplaceJsonTableColumn('compare_column1', $json);
        static::importReplaceJsonTableColumn('compare_column2', $json);
        static::importReplaceJsonTableColumn('share', $json, 'share_column_id');
        static::importReplaceJsonTableColumn('table_label', $json);
    }

    /**
     * Set json value calling import
     *
     * @param string $key
     * @param array $json
     * @param string|null $set_key_name
     * @return void
     */
    protected static function importReplaceJsonTableColumn($key, &$json, $set_key_name = null)
    {
        $table_name = array_get($json, "options.{$key}_table_name");
        $column_name = array_get($json, "options.{$key}_column_name");

        $forget_flg = true;
        if (isset($table_name) && isset($column_name)) {
            $custom_table = CustomTable::getEloquent($table_name);
            $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

            if (isset($custom_column)) {
                $set_key_name = $set_key_name ?? "{$key}_id";
                array_set($json, "options.{$set_key_name}", $custom_column->id);
            }
        }

        array_forget($json, "options.{$key}_table_name");
        array_forget($json, "options.{$key}_column_name");
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new OrderScope('priority'));
    }
}
