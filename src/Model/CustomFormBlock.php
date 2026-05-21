<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\FormBlockType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @phpstan-consistent-constructor
 * @property mixed $id
 * @property mixed $label
 * @property mixed $options
 * @property mixed $available
 * @property mixed $custom_form_id
 * @property mixed $target_table
 * @property mixed $custom_form_columns
 * @property mixed $form_block_type
 * @property mixed $form_block_target_table_id
 * @property mixed $form_block_view_name
 */
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
     * @var string|null
     */
    protected $_request_key;


    // @phpstan-ignore-next-line
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


    // @phpstan-ignore-next-line
    public function custom_form(): BelongsTo
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }


    // @phpstan-ignore-next-line
    public function custom_form_columns(): HasMany
    {
        return $this->hasMany(CustomFormColumn::class, 'custom_form_block_id');
    }


    // @phpstan-ignore-next-line
    public function target_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'form_block_target_table_id');
    }


    // @phpstan-ignore-next-line
    public function getCustomFormCacheAttribute()
    {
        return CustomForm::getEloquent($this->custom_form_id);
    }


    // @phpstan-ignore-next-line
    public function getTargetTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->form_block_target_table_id);
    }


    // @phpstan-ignore-next-line
    public function getFormTableCacheAttribute()
    {
        $custom_form = $this->custom_form_cache;
        return $custom_form ? $custom_form->custom_table_cache : null;
    }


    // @phpstan-ignore-next-line
    public function getCustomFormColumnsCacheAttribute()
    {
        return $this->hasManyCache(CustomFormColumn::class, 'custom_form_block_id');
    }


    // @phpstan-ignore-next-line
    public function getRequestKeyAttribute()
    {
        return $this->_request_key ?? $this->id;
    }


    // @phpstan-ignore-next-line
    public function setRequestKeyAttribute($request_key)
    {
        $this->_request_key = $request_key;
        return $this;
    }



    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
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

        // get relation
        // if has args $custom_form_table, use $custom_form_table. Almost use preview
        if ($custom_form_table) {
            $relation_custom_table = $custom_form_table;
        } elseif ($this->custom_form) {
            $relation_custom_table = $this->custom_form->custom_table;
        } else {
            return [null, null, $block_label];
        }

        $relation = CustomRelation::getRelationByParentChild($relation_custom_table, $target_table);
        $relation_name = $relation ? $relation->getRelationName() : null;

        return [$relation, $relation_name, $block_label];
    }


    // @phpstan-ignore-next-line
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


    // @phpstan-ignore-next-line
    protected function importSetValue(&$json, $options = [])
    {
        if (!$this->exists) {
            $this->available = true;
        }
    }


    // @phpstan-ignore-next-line
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
