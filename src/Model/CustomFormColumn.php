<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums\FormColumnType;

class CustomFormColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    use Traits\UniqueKeyCustomColumnTrait;
    
    protected $casts = ['options' => 'json'];
    protected $appends = ['form_column_target'];
    protected $with = ['custom_column'];

    public static $templateItems = [
        'excepts' => ['custom_column', 'form_column_target', 'options.changedata_target_column_id', 'options.changedata_column_id', 'options.relation_filter_target_column_id'],
        'langs' => [
            'keys' => ['form_column_target_name'],
            'values' => ['options.html', 'options.text'],
        ],
        'enums' => [
            'form_column_type' => FormColumnType::class,
        ],
        'parent' => 'custom_form_block_id',
        'uniqueKeys' => [
            'export' => ['form_column_type', 'form_column_target_name'],
            'import' => ['custom_form_block_id', 'form_column_target_id', 'form_column_type'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'form_column_target_id',
                        'replacedName' => [
                            'column_name' => 'form_column_target_name',
                        ],
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValuesFormColumn',
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.changedata_column_table_name',
                            'column_name' => 'options.changedata_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.changedata_column_id'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.changedata_target_table_name',
                            'column_name' => 'options.changedata_target_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.changedata_target_column_id'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.relation_filter_target_table_name',
                            'column_name' => 'options.relation_filter_target_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.relation_filter_target_column_id'],
            ],
        ]
    ];

    public function custom_form_block()
    {
        return $this->belongsTo(CustomFormBlock::class, 'custom_form_block_id');
    }

    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'form_column_target_id');
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
    
    protected function getFormColumnTargetAttribute()
    {
        if ($this->form_column_type == FormColumnType::COLUMN) {
            return $this->view_column_target_id;
        } elseif ($this->form_column_type == FormColumnType::OTHER) {
            $form_column_obj = FormColumnType::getOption(['id' => $this->form_column_target_id])['column_name'] ?? null;
        }
        return null;
    }
    
    public function getColumnItemAttribute()
    {
        // if tagret is number, column type is column.
        if ($this->form_column_type == FormColumnType::COLUMN) {
            return $this->custom_column->column_item ?? null;
        }
        // other column
        else {
            return ColumnItems\FormOtherItem::getItem($this);
        }
    }

    protected function getCustomColumnCacheAttribute()
    {
        if ($this->form_column_type != FormColumnType::COLUMN) {
            return null;
        }
        
        return CustomColumn::getEloquent($this->form_column_target_id);
    }
    
    /**
     * get Table And Column Name
     */
    protected function getUniqueKeyValuesFormColumn()
    {
        switch ($this->form_column_type) {
            case FormColumnType::COLUMN:
                return [
                    'column_name' => $this->custom_column->column_name ?? null,
                ];
            case FormColumnType::OTHER:
                return [
                    'column_name' => FormColumnType::getOption(['id' => $this->form_column_target_id])['column_name'],
                ];
        }
        return [];
    }
    
    protected static function importReplaceJson(&$json, $options = [])
    {
        // set form column type
        if (array_key_exists('form_column_type', $json)) {
            $form_column_type = FormColumnType::getEnumValue(array_get($json, "form_column_type"));
        } else {
            $form_column_type = FormColumnType::COLUMN;
        }
        $json['form_column_type'] = $form_column_type;

        $form_column_name = array_get($json, "form_column_target_name");
        switch ($form_column_type) {
            // for table column
            case FormColumnType::COLUMN:
                // get column name
                $form_column_target = CustomColumn::getEloquent($form_column_name, $options['parent']->target_table);
                $form_column_target_id = isset($form_column_target) ? $form_column_target->id : null;
                break;
            default:
                $form_column_target_id = FormColumnType::getOption(['column_name' => $form_column_name])['id'] ?? null;
                break;
        }
        array_set($json, 'form_column_target_id', $form_column_target_id);
        array_forget($json, 'form_column_target_name');


        // set changedata_custom_table_id
        static::replaceChangedata($json, 'options.changedata_column_table_name', 'options.changedata_column_name', 'options.changedata_column_id');
        static::replaceChangedata($json, 'options.changedata_target_table_name', 'options.changedata_target_column_name', 'options.changedata_target_column_id');
        static::replaceChangedata($json, 'options.relation_filter_target_table_name', 'options.relation_filter_target_column_name', 'options.relation_filter_target_column_id');
    }

    /**
     * replace options change data
     *
     * @param [type] $json
     * @param string $table_key_name
     * @param string $column_key_name
     * @param string $column_key_id
     * @return void
     */
    protected static function replaceChangedata(&$json, $table_key_name, $column_key_name, $column_key_id)
    {
        // set changedata_custom_table_id
        if (array_key_value_exists($column_key_name, $json)) {
            $changedata_target_column_name = array_get($json, $column_key_name);

            // get changedata target table name and column
            // if changedata_target_column_name value has dotted, get parent table name
            if (str_contains($changedata_target_column_name, ".")) {
                list($changedata_target_table_name, $changedata_target_column_name) = explode(".", $changedata_target_column_name);
                $changedata_target_table = CustomTable::getEloquent($changedata_target_table_name);
            } elseif (array_key_value_exists($table_key_name, $json)) {
                $changedata_target_table_name = array_get($json, $table_key_name);
                $changedata_target_table = CustomTable::getEloquent($changedata_target_table_name);
            } else {
                $changedata_target_table = null;
                //$changedata_target_table = $options['parent']->target_table;
            }

            if (isset($changedata_target_column_name) && isset($changedata_target_table)) {
                $changedata_target_column = CustomColumn::getEloquent($changedata_target_column_name, $changedata_target_table);
                array_set($json, $column_key_id, $changedata_target_column->id ?? null);
            }
        }

        array_forget($json, $table_key_name);
        array_forget($json, $column_key_name);
    }

    protected function importSetValue(&$json, $options = [])
    {
        if (!$this->exists) {
            $this->order = array_get($options, 'count', 0);
        }
        $this->column_no = array_get($json, 'column_no', 1) ?? 1;

        return ['count', 'column_no'];
    }
    
    protected static function boot()
    {
        parent::boot();

        // add default order
        static::addGlobalScope(new OrderScope('order'));
        
        static::addGlobalScope('remove_system_column', function ($builder) {
            $builder->where('form_column_type', '<>', FormColumnType::SYSTEM);
        });
    }
}
