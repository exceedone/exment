<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ViewColumnType;

class CustomViewSummary extends ModelBase
{
    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected static $templateItems = [
        'excepts' => ['view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column'],
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
            'view_column_type' => ViewColumnType::class,
        ],
    ];

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        if ($this->view_column_type != ViewColumnType::COLUMN) {
            return null;
        }
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'view_column_table_id');
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
