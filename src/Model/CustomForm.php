<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ShowGridType;
use Exceedone\Exment\DataItems\Show as ShowItem;
use Exceedone\Exment\DataItems\Form as FormItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @phpstan-consistent-constructor
 * @property mixed $default_flg
 * @property mixed $custom_table_id
 * @property mixed $form_view_name
 * @method static int count($columns = '*')
 * @method static \Illuminate\Database\Query\Builder orderBy($column, $direction = 'asc')
 */
class CustomForm extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use Traits\TemplateTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $casts = ['options' => 'json'];

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

    /**
     * Form item
     *
     * @var FormItem\FormBase|null
     */
    private $_form_item;

    /**
     * Show Item for data detail
     *
     * @var ShowItem\ShowBase|null
     */
    private $_show_item;

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_blocks(): HasMany
    {
        return $this->hasMany(CustomFormBlock::class, 'custom_form_id');
    }

    public function custom_form_priorities(): HasMany
    {
        return $this->hasMany(CustomFormPriority::class, 'custom_form_id');
    }

    public function public_forms(): HasMany
    {
        return $this->hasMany(PublicForm::class, 'custom_form_id');
    }

    public function custom_form_columns(): HasManyThrough
    {
        return $this->hasManyThrough(CustomFormColumn::class, CustomFormBlock::class, 'custom_form_id', 'custom_form_block_id');
    }

    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->custom_table_id);
    }

    public function getCustomFormBlocksCacheAttribute()
    {
        return $this->hasManyCache(CustomFormBlock::class, 'custom_form_id');
    }

    /**
     * Show Item for data detail
     *
     * @return ShowItem\ShowBase
     */
    public function getShowItemAttribute()
    {
        if (isset($this->_show_item)) {
            return $this->_show_item;
        }

        $this->_show_item = ShowItem\DefaultShow::getItem($this->custom_table, $this);

        return $this->_show_item;
    }

    /**
     * Form Item for data detail
     *
     * @return FormItem\FormBase
     */
    public function getFormItemAttribute()
    {
        if (isset($this->_form_item)) {
            return $this->_form_item;
        }

        $this->_form_item = FormItem\DefaultForm::getItem($this->custom_table, $this);

        return $this->_form_item;
    }

    public function getFormLabelTypeAttribute()
    {
        return $this->getOption('form_label_type', FormLabelType::HORIZONTAL);
    }
    public function setFormLabelTypeAttribute($form_label_type)
    {
        $this->setOption('form_label_type', $form_label_type);
        return $this;
    }
    public function getShowGridTypeAttribute()
    {
        return $this->getOption('show_grid_type', ShowGridType::GRID);
    }
    public function setShowGridTypeAttribute($form_label_type)
    {
        $this->setOption('show_grid_type', $form_label_type);
        return $this;
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
            $form = new CustomForm();
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
            $form_block = new CustomFormBlock();
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
                $form_column = new CustomFormColumn();
                $form_column->custom_form_block_id = $form_block->id;
                $form_column->form_column_type = FormColumnType::COLUMN;
                $form_column->form_column_target_id = array_get($custom_column, 'id');
                $form_column->order = $index + 1;
                $form_column->row_no = 1;
                $form_column->column_no = 1;
                $form_column->width = 2;
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
        $this->public_forms()->delete();
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
