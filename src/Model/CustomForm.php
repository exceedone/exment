<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;

class CustomForm extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use Traits\TemplateTrait;

    public static $templateItems = [
        'excepts' => ['custom_table', 'form_name'],
        'langs' => [
            'keys' => ['suuid'],
            'values' => ['form_view_name'],
        ],

        'uniqueKeys' => [
            'suuid'
        ],

        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'custom_table_id',
                        'replacedName' => [
                            'table_name' => 'table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'children' =>[
            'custom_form_blocks' => CustomFormBlock::class
        ],
    ];

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_blocks()
    {
        return $this->hasMany(CustomFormBlock::class, 'custom_form_id');
    }

    public function custom_form_priorities()
    {
        return $this->hasMany(CustomFormPriority::class, 'custom_form_id');
    }
    
    public function custom_form_columns()
    {
        return $this->hasManyThrough(CustomFormColumn::class, CustomFormBlock::class, 'custom_form_id', 'custom_form_block_id');
    }
    
    /**
     * get default form using table
     *
     * @param mixed $tableObj table_name, object or id eic
     * @return CustomForm
     */
    public static function getDefault($tableObj)
    {
        $user = Admin::user();
        $tableObj = CustomTable::getEloquent($tableObj);

        // get default form.
        $form = $tableObj->custom_forms()->where('default_flg', true)->first();

        // if not exists, get first.
        if (!isset($form)) {
            $form = $tableObj->custom_forms()->first();
        }
        
        // if form doesn't contain for target table, create form.
        if (!isset($form)) {
            $form = new CustomForm;
            $form->custom_table_id = $tableObj->id;
            $form->form_view_name = exmtrans('custom_form.default_form_name');
            $form->saveOrFail();
            $form = $form;

            // re-get form
            $form = static::find($form->id);
        }

        // get form block
        $form_block = $form->custom_form_blocks()
            ->where('form_block_type', FormBlockType::DEFAULT)
            ->first();
        if (!isset($form_block)) {
            // Create CustomFormBlock as default
            $form_block = new CustomFormBlock;
            $form_block->form_block_type = FormBlockType::DEFAULT;
            $form_block->form_block_target_table_id = $tableObj->id;
            $form_block->available = true;
            $form->custom_form_blocks()->save($form_block);
            
            // add columns.
            $form_columns = [];

            // get target block as default.
            $form_block = $form->custom_form_blocks()
                ->where('form_block_type', FormBlockType::DEFAULT)
                ->first();
            // loop for index_enabled columns, and add form.
            foreach ($tableObj->custom_columns_cache as $index => $custom_column) {
                $form_column = new CustomFormColumn;
                $form_column->custom_form_block_id = $form_block->id;
                $form_column->form_column_type = FormColumnType::COLUMN;
                $form_column->form_column_target_id = array_get($custom_column, 'id');
                $form_column->order = $index + 1;
                $form_columns[] = $form_column;
            }
            $form_block->custom_form_columns()->saveMany($form_columns);
            
            // re-get form
            $form = static::find($form->id);
        }

        return $form;
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    public function deletingChildren()
    {
        foreach ($this->custom_form_blocks as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_priorities as $item) {
            $item->deletingChildren();
        }
        $this->custom_form_blocks()->delete();
        $this->custom_form_priorities()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            $model->setDefaultFlgInTable();
        });
        static::updating(function ($model) {
            $model->setDefaultFlgInTable();
        });
        static::saving(function ($model) {
            $model->setDefaultFlgInTable();
        });
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
