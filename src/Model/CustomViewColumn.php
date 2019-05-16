<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ViewColumnType;

class CustomViewColumn extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\DatabaseJsonTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_column_color', 'view_column_font_color'];
    protected $with = ['custom_column'];
    protected $casts = ['options' => 'json'];

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

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }

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
        return $this->getOption('color');
    }
    public function setViewColumnColorAttribute($view_column_color)
    {
        $this->setOption('color', $view_column_color);

        return $this;
    }
    
    public function getViewColumnFontColorAttribute()
    {
        return $this->getOption('font_color');
    }
    public function setViewColumnFontColorAttribute($view_column_color)
    {
        $this->setOption('font_color', $view_column_color);

        return $this;
    }
}
