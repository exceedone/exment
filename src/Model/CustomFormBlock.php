<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\FormBlockType;

class CustomFormBlock extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['custom_form_id', 'target_table'],
        'langs' => [
            'keys' => ['form_block_target_table_name'],
            'values' => ['form_block_view_name'],
        ],
        'uniqueKeys' => [
            'export' => ['form_block_target_table_name'],
            'import' => ['custom_form_id', 'form_block_target_table_id'],
        ],
        'enums' => [
            'form_block_type' => FormBlockType::class,
        ],
        'parent' => 'custom_form_id',
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'form_block_target_table_id',
                        'replacedName' => [
                            'table_name' => 'form_block_target_table_name',
                        ],
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'children' =>[
            'custom_form_columns' => CustomFormColumn::class
        ],
    ];

    public function custom_form()
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function custom_form_columns()
    {
        return $this->hasMany(CustomFormColumn::class, 'custom_form_block_id');
    }

    public function target_table()
    {
        return $this->belongsTo(CustomTable::class, 'form_block_target_table_id');
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
    
    protected static function importReplaceJson(&$json, $options = []){
        // get custom table
        $custom_table = $options['parent']->custom_table;
        // target block table
        if (!isset($json['form_block_target_table_name'])) {
            $json['form_block_target_table_name'] = $custom_table->table_name;
        }

        // get form_block_type
        if (!isset($json['form_block_type'])) {
            $self = $target_table->id == $custom_table->id;
            if ($self) {
                $form_block_type = FormBlockType::DEFAULT;
            } else {
                // get relation
                $block_relation = CustomRelation::where('parent_custom_table_id', $custom_table->id)
                                ->where('child_custom_table_id', $target_table->id)
                                ->first();
                if (isset($block_relation)) {
                    $form_block_type = $block_relation->relation_type;
                } else {
                    $form_block_type = FormBlockType::ONE_TO_MANY;
                }
            }
            $json['form_block_type'] = $form_block_type;
        }
    }

    protected function importSetValue(&$json, $options = []){
        if (!$this->exists) {
            $this->available = true;
        }
    }
    
    public function deletingChildren()
    {
        $this->custom_form_columns()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
