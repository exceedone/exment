<?php

namespace Exceedone\Exment\Model;

/**
 * Custom column multiple settings
 */
class CustomColumnMulti extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    use Traits\UniqueKeyCustomColumnTrait;

    protected $appends = ['unique1', 'unique2', 'unique3', 'table_label_column_id'];
    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid'];
    protected $table = 'custom_column_multisettings';

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public static $templateItems = [
        'excepts' => [
            'export' => [
                'unique1', 'unique2', 'unique3', 'options.unique1_id', 'options.unique2_id', 'options.unique3_id', 'options.table_label_column_id'
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
                            'table_name' => 'options.table_label_table_name',
                            'column_name' => 'options.table_label_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['table_label_column_id'],
            ],
        ]
    ];

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    
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
    
    public function getTableLabelColumnIdAttribute()
    {
        return $this->getOption('table_label_column_id');
    }
    public function setTableLabelColumnIdAttribute($value)
    {
        $this->setOption('table_label_column_id', $value);
        return $this;
    }

    // Template Output ----------------------------------------
    
    /**
     * Set json value calling import
     *
     * @param [type] $json
     * @param array $options
     * @return void
     */
    protected static function importReplaceJson(&$json, $options = [])
    {
        static::importReplaceJsonTableColumn('unique1', $json, $options);
        static::importReplaceJsonTableColumn('unique2', $json, $options);
        static::importReplaceJsonTableColumn('unique3', $json, $options);
        static::importReplaceJsonTableColumn('table_label_column_id', $json, $options);
    }

    /**
     * Set json value calling import
     *
     * @param [type] $json
     * @param array $options
     * @return void
     */
    protected static function importReplaceJsonTableColumn($key, &$json, $options = [])
    {
        $table_name = array_get($json, "options.{$key}_table_name");
        $column_name = array_get($json, "options.{$key}_column_name");

        $forget_flg = true;
        if (isset($table_name) && isset($column_name)) {
            $custom_table = CustomTable::getEloquent($table_name);
            $custom_column = CustomColumn::getEloquent($column_name, $custom_table);

            if (isset($custom_column)) {
                array_set($json, "options.{$key}_id", $custom_column->id);
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
