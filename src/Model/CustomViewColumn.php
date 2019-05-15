<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ViewColumnType;

class CustomViewColumn extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_column_color'];
    protected $with = ['custom_column'];

    public static $templateItems = [
        'excepts' => [
            'import' => ['view_column_target', 'custom_column', 'target_view_name', 'view_column_name'],
            'export' => ['custom_view_id', 'view_column_target', 'custom_column', 'target_view_name', 'view_column_name', 'view_column_table_id', 'view_column_target_id'],
        ],
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
        static::addGlobalScope(new OrderScope('order'));
    }
    public function getViewColumnColorAttribute()
    {
        $options = is_null($this->options)? array(): json_decode($this->options, true);
        return array_get($options, 'color');
    }
    public function setViewColumnColorAttribute($view_column_color)
    {
        $options = is_null($this->options)? array(): json_decode($this->options, true);

        if (isset($view_column_color)) {
            $options['color'] = $view_column_color;
        } else {
            unset($options['color']);
        }
        $this->options = json_encode($options);
    }
}
