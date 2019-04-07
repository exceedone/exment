<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Illuminate\Database\Eloquent\Builder;

class CustomFormColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    
    protected $casts = ['options' => 'json'];
    protected $appends = ['form_column_target'];
    protected $with = ['custom_column'];

    protected static $templateItems = [
        'excepts' => ['custom_column', 'custom_form_block_id'],
        'langs' => [
            'keys' => ['form_column_target_name'],
            'values' => ['options.html', 'options.text'],
        ],
        'enums' => [
            'form_column_type' => FormColumnType::class,
        ],
        'parent' => 'custom_form_block_id',
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
                'uniqueKeyClassName' => CustomColumn::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.changedata_column_id',
                        'replacedName' => [
                            'import' => [
                                'custom_table_id' => 'options.changedata_custom_table_id',
                                'column_name' => 'options.changedata_column_name',
                            ],
                            'export' => [
                                'table_name' => 'options.changedata_column_table_name',
                                'column_name' => 'options.changedata_column_name',
                            ],
                        ],
                    ]
                ],
                'uniqueKeyClassName' => CustomColumn::class,
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
        if ($this->form_column_type == FormColumnType::SYSTEM) {
            return SystemColumn::getOption(['id' => $this->form_column_target_id])['name'] ?? null;
        } elseif ($this->form_column_type == FormColumnType::COLUMN) {
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
            return $this->custom_column->column_item;
        }
        // system
        elseif ($this->form_column_type == FormColumnType::SYSTEM) {
            return ColumnItems\SystemItem::getItem($this->custom_form_block->target_table, $this->form_column_target, null);
        }
        // other column
        else {
            return ColumnItems\FormOtherItem::getItem($this);
        }
    }

    protected static function importReplaceJson(&$json, $options = []){
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
            case FormColumnType::SYSTEM:
                $form_column_target_id = SystemColumn::getOption(['name' => $form_column_name])['id'] ?? null;
                break;
            default:
                $form_column_target_id = FormColumnType::getOption(['column_name' => $form_column_name])['id'] ?? null;
                break;
        }

        // set changedata_custom_table_id
        if (array_key_value_exists('options.changedata_target_column_name', $json)) {
            $changedata_target_column_name = array_get($json, 'options.changedata_target_column_name');
            // get changedata target table name and column
            // if changedata_target_column_name value has dotted, get parent table name
            if (str_contains($changedata_target_column_name, ".")) {
                list($changedata_target_table_name, $changedata_target_column_name) = explode(".", $changedata_target_column_name);
                $changedata_target_table = CustomTable::getEloquent($changedata_target_table_name);
            } else {
                $changedata_target_table = $target_table;
            }
            array_set($json, 'options.changedata_custom_table_id', $changedata_target_table->id);
            array_set($json, 'options.changedata_target_column_name', $changedata_target_column_name);
        }
    }

    protected function importSetValue(&$json, $options = []){
        if (!$this->exists) {
            $this->order = array_get($options, 'count', 0);
        }
        $this->column_no = array_get($json, 'column_no', 1) ?? 1;

        return ['count', 'column_no'];
    }
    
    protected static function boot()
    {
        parent::boot();

        // Order by name ASC
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('order', 'asc');
        });
    }
}
