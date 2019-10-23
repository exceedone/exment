<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ConditionType;

class CustomViewSort extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];

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

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
    
    protected static function boot()
    {
        parent::boot();

        // add default order
        static::addGlobalScope(new OrderScope('priority'));
    }
}
