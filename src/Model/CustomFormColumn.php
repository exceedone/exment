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
        'excepts' => ['id', 'custom_column', 'custom_form_block_id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['form_column_target_name'],
        'langs' => ['options.html', 'options.text'],
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
                            'table_name' => 'options.changedata_column_table_name',
                            'column_name' => 'options.changedata_column_name',
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

    /**
     * import template
     */
    public static function importTemplate($form_column, $options = [])
    {
        $custom_table = array_get($options, 'custom_table');
        $custom_form = array_get($options, 'custom_form');
        $custom_form_block = array_get($options, 'custom_form_block');
        $target_table = array_get($options, 'target_table');
        $count = array_get($options, 'count', 0);

        if (array_key_exists('form_column_type', $form_column)) {
            $form_column_type = FormColumnType::getEnumValue(array_get($form_column, "form_column_type"));
        } else {
            $form_column_type = FormColumnType::COLUMN;
        }

        $form_column_name = array_get($form_column, "form_column_target_name");
        switch ($form_column_type) {
            // for table column
            case FormColumnType::COLUMN:
                // get column name
                $form_column_target = CustomColumn::getEloquent($form_column_name, $target_table);
                $form_column_target_id = isset($form_column_target) ? $form_column_target->id : null;
                break;
            case FormColumnType::SYSTEM:
                $form_column_target_id = SystemColumn::getOption(['name' => $form_column_name])['id'] ?? null;
                break;
            default:
                $form_column_target_id = FormColumnType::getOption(['column_name' => $form_column_name])['id'] ?? null;
                break;
        }

        // if not set column id, continue
        if (!isset($form_column_target_id)) {
            return null;
        }

        $custom_form_column = CustomFormColumn::firstOrNew([
            'custom_form_block_id' => $custom_form_block->id,
            'form_column_type' => $form_column_type,
            'form_column_target_id' => $form_column_target_id,
        ]);
        $custom_form_column->custom_form_block_id = $custom_form_block->id;
        $custom_form_column->form_column_type = $form_column_type;
        $custom_form_column->form_column_target_id = $form_column_target_id;
        if (!$custom_form_column->exists) {
            $custom_form_column->order = $count;
        }
        $custom_form_column->column_no = array_get($form_column, 'column_no', 1) ?? 1;
    
        // set option
        collect(array_get($form_column, 'options', []))->each(function ($option, $key) use ($custom_form_column) {
            $custom_form_column->setOption($key, $option, true);
        });

        // if has changedata_column_name and changedata_target_column_name, set id
        if (array_key_value_exists('options.changedata_column_name', $form_column) && array_key_value_exists('options.changedata_column_table_name', $form_column)) {
            // get using changedata_column_table_name
            $changedata_column_name = array_get($form_column, 'options.changedata_column_name');
            $changedata_column_table_name = array_get($form_column, 'options.changedata_column_table_name');
            $id = CustomColumn::getEloquent($changedata_column_name, $changedata_column_table_name)->id ?? null;
            
            $custom_form_column->setOption('changedata_column_id', $id);
            $custom_form_column->forgetOption('changedata_column_name');
        }
        if (array_key_value_exists('options.changedata_target_column_name', $form_column)) {
            $changedata_target_column_name = array_get($form_column, 'options.changedata_target_column_name');
            // get changedata target table name and column
            // if changedata_target_column_name value has dotted, get parent table name
            if (str_contains($changedata_target_column_name, ".")) {
                list($changedata_target_table_name, $changedata_target_column_name) = explode(".", $changedata_target_column_name);
                $changedata_target_table = CustomTable::getEloquent($changedata_target_table_name);
            } else {
                $changedata_target_table = $target_table;
                $changedata_target_column_name = $changedata_target_column_name;
            }
            $id = CustomColumn::getEloquent($changedata_target_column_name, $changedata_target_table)->id ?? null;
            $custom_form_column->setOption('changedata_target_column_id', $id);
            $custom_form_column->forgetOption('changedata_target_column_name');
        }
    
        $custom_form_column->saveOrFail();

        return $custom_form_column;
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
