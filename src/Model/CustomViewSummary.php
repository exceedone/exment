<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionType;

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
        ],
        'enums' => [
            'view_column_type' => ConditionType::class,
        ],
    ];

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        if ($this->view_column_type != ConditionType::COLUMN) {
            return null;
        }
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }
    
    public function custom_table()
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
