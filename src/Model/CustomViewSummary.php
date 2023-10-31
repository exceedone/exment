<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\ConditionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-consistent-constructor
 * @property mixed $view_column_target_id
 * @property mixed $view_column_table_id
 * @property mixed $suuid
 * @property mixed $custom_view_id
 * @property mixed $custom_view
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomViewSummary extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\AutoSUuidTrait;
    use Traits\CustomViewColumnOptionTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;
    use Traits\ConditionTypeTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'sort_order', 'sort_type'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['custom_table', 'view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column'],
        'uniqueKeys' => ['custom_view_id', 'view_column_type', 'view_column_target_id', 'view_column_table_id'],
        'parent' => 'custom_view_id',
        'langs' => [
            'keys' => ['view_column_table_name', 'view_column_target_name'],
            'values' => ['view_column_name'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'view_column_table_name',
                            'column_name' => 'view_column_target_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'view_pivot_table_name',
                            'column_name' => 'view_pivot_column_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getPivotUniqueKeyValues',
            ],
        ],
        'enums' => [
            'view_column_type' => ConditionType::class,
        ],
    ];

    public function custom_view(): BelongsTo
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }

    public function custom_column(): ?BelongsTo
    {
        if ($this->view_column_type != ConditionType::COLUMN) {
            return null;
        }
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'view_column_table_id');
    }

    public function getViewPivotColumnIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_column_id');
    }
    public function setViewPivotColumnIdAttribute($view_pivot_column_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_column_id', $view_pivot_column_id);
    }

    public function getViewPivotTableIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_table_id');
    }
    public function setViewPivotTableIdAttribute($view_pivot_table_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_table_id', $view_pivot_table_id);
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
}
