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

    protected static $templateItems = [
        'excepts' => ['id', 'custom_form_id', 'target_table', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['form_block_target_table_name'],
        'langs' => ['form_block_view_name'],
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
            'custom_form_columns'
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
    
    /**
     * import template
     */
    public static function importTemplate($form_block, $options = [])
    {
        $custom_table = array_get($options, 'custom_table');
        $custom_form = array_get($options, 'custom_form');

        // target block id
        if (isset($form_block['form_block_target_table_name'])) {
            $target_table = CustomTable::getEloquent($form_block['form_block_target_table_name']);
        } else {
            $target_table = $custom_table;
        }

        // get form_block_type
        if (isset($form_block['form_block_type'])) {
            $form_block_type = $form_block['form_block_type'];
        } else {
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
        }

        $custom_form_block = static::firstOrNew([
            'custom_form_id' => $custom_form->id,
            'form_block_target_table_id' => $target_table->id,
        ]);
        $custom_form_block->custom_form_id = $custom_form->id;
        $custom_form_block->form_block_type = FormBlockType::getEnumValue($form_block_type);
        $custom_form_block->form_block_view_name = array_get($form_block, 'form_block_view_name');
        $custom_form_block->form_block_target_table_id = $target_table->id;
        if (!$custom_form_block->exists) {
            $custom_form_block->available = true;
        }

        // set option
        collect(array_get($form_block, 'options', []))
            ->each(function ($option, $key) use ($custom_form_block) {
                $custom_form_block->setOption($key, $option, true);
            });
        $custom_form_block->saveOrFail();

        // create form colunms --------------------------------------------------
        if (array_key_exists('custom_form_columns', $form_block)) {
            // get column counts
            $count = count($custom_form_block->custom_form_columns);
            foreach (array_get($form_block, "custom_form_columns") as $form_column) {
                CustomFormColumn::importTemplate($form_column, [
                    'custom_table' => $custom_table,
                    'custom_form' => $custom_form,
                    'custom_form_block' => $custom_form_block,
                    'target_table' => $target_table,
                    'count' => ++$count,
                ]);
            }
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
