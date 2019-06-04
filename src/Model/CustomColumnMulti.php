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

    protected $appends = ['unique1', 'unique2', 'unique3'];
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
                'unique1', 'unique2', 'unique3', 'options.unique1_id', 'options.unique2_id', 'options.unique3_id'
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
                'uniqueKeyFunction' => 'getUniqueKeyValuesUnique1',
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
                'uniqueKeyFunction' => 'getUniqueKeyValuesUnique2',
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
                'uniqueKeyFunction' => 'getUniqueKeyValuesUnique3',
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
    

    // Template Output ----------------------------------------
    protected function getUniqueKeyValuesUnique1()
    {
        return $this->getUniqueKeyValues('unique1');
    }

    protected function getUniqueKeyValuesUnique2()
    {
        return $this->getUniqueKeyValues('unique2');
    }

    protected function getUniqueKeyValuesUnique3()
    {
        return $this->getUniqueKeyValues('unique3');
    }

    /**
     * get Table And Column Name
     */
    protected function getUniqueKeyValues($key)
    {
        $custom_column = CustomColumn::getEloquent($this->{$key});
        if (!isset($custom_column)) {
            return [
                'table_name' => null,
                'column_name' => null,
            ];
        }

        return [
            'table_name' => $custom_column->custom_table->table_name,
            'column_name' => $custom_column->column_name,
        ];
    }
    
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
}
