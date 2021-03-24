<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\FormBlockType;

class CustomFormBlock extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;
    
    protected $casts = ['options' => 'json'];

    /**
     * request key. Used by custom form setting display. Ex. NEW__f482dce0-662c-11eb-8f65-5f9d12681ab1
     *
     * @var string
     */
    protected $_request_key;

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

    public function getCustomFormCacheAttribute()
    {
        return CustomForm::getEloquent($this->custom_form_id);
    }

    public function getTargetTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->form_block_target_table_id);
    }

    public function getFormTableCacheAttribute()
    {
        $custom_form = $this->custom_form_cache;
        return $custom_form ? $custom_form->custom_table_cache : null;
    }

    public function getCustomFormColumnsCacheAttribute()
    {
        return $this->hasManyCache(CustomFormColumn::class, 'custom_form_block_id');
    }

    public function getRequestKeyAttribute()
    {
        return $this->_request_key ?? $this->id;
    }

    public function setRequestKeyAttribute($request_key)
    {
        $this->_request_key = $request_key;
        return $this;
    }

    
    public function isMultipleColumn()
    {
        foreach ($this->custom_form_columns as $custom_form_column) {
            if (array_get($custom_form_column, 'column_no') != 1) {
                return true;
            }
        }
        return false;
    }

    
    /**
     * get relation name etc for form block
     *
     * @return array offset 0 : CustomRelation, 1:relation name, 2:block label.
     */
    public function getRelationInfo(?CustomTable $custom_form_table = null)
    {
        $target_table = $this->target_table;
        // get label hasmany
        $block_label = $this->form_block_view_name;

        if (!isset($block_label)) {
            $enum = FormBlockType::getEnum(array_get($this, 'form_block_type'));
            $block_label = exmtrans("custom_form.table_".$enum->lowerKey()."_label") . $target_table->table_view_name;
        }
        
        if (isMatchString(array_get($this, 'form_block_type'), FormBlockType::DEFAULT)) {
            return [null, null, $block_label];
        }
        
        // get form columns count
        $form_block_options = array_get($this, 'options', []);
        $relation = CustomRelation::getRelationByParentChild(
            $custom_form_table ?? $this->custom_form->custom_table,
            $target_table
        );
        $relation_name = $relation ? $relation->getRelationName() : null;
        
        return [$relation, $relation_name, $block_label];
    }

    protected static function importReplaceJson(&$json, $options = [])
    {
        // get custom table
        $custom_table = $options['parent']->custom_table;
        // target block table
        if (!isset($json['form_block_target_table_name'])) {
            $json['form_block_target_table_name'] = $custom_table->table_name;
        }

        // get form_block_type
        if (!isset($json['form_block_type'])) {
            $target_table = CustomTable::getEloquent($json['form_block_target_table_name']);
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

    protected function importSetValue(&$json, $options = [])
    {
        if (!$this->exists) {
            $this->available = true;
        }
    }
    
    public function deletingChildren()
    {
        $this->custom_form_columns()->withoutGlobalScope('remove_system_column')->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
