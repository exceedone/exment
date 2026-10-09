<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemColumn;

/**
 * @phpstan-consistent-constructor
 * @property mixed $view_column_target_id
 * @property mixed $view_column_table_id
 * @property mixed $suuid
 * @property mixed $order
 * @property mixed $options
 * @property mixed $custom_view_id
 * @property mixed $custom_view
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomViewColumn extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\CustomViewColumnTrait{
        Traits\CustomViewColumnTrait::exportReplaceJson as exportReplaceJsonTrait;
    }
    use Traits\CustomViewColumnOptionTrait;
    use Traits\ConditionTypeTrait;
    use Traits\TemplateTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_column_end_date', 'view_group_condition', 'view_column_color', 'view_column_font_color', 'sort_order', 'sort_type'];
    //protected $with = ['custom_column'];
    protected $casts = ['options' => 'json'];


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => [
            'import' => ['custom_table', 'view_column_target', 'custom_column', 'target_view_name', 'view_group_condition', 'view_pivot_column_name', 'view_pivot_table_name'],
            'export' => ['custom_table', 'custom_view_id', 'view_column_target', 'custom_column', 'target_view_name', 'view_column_table_id', 'view_column_target_id', 'view_pivot_column_id', 'view_pivot_table_id', 'view_group_condition', 'view_column_end_date'],
        ],
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

    /**
     * get eloquent using request settion.
     * now only support only id.
     */

    // @phpstan-ignore-next-line
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        // add default order
        static::addGlobalScope(new OrderScope('order'));
    }


    // @phpstan-ignore-next-line
    public function getViewColumnColorAttribute()
    {
        return $this->getOption('color');
    }

    // @phpstan-ignore-next-line
    public function setViewColumnColorAttribute($view_column_color)
    {
        $this->setOption('color', $view_column_color);

        return $this;
    }


    // @phpstan-ignore-next-line
    public function getViewColumnFontColorAttribute()
    {
        return $this->getOption('font_color');
    }

    // @phpstan-ignore-next-line
    public function setViewColumnFontColorAttribute($view_column_color)
    {
        $this->setOption('font_color', $view_column_color);

        return $this;
    }


    // @phpstan-ignore-next-line
    public function getViewColumnEndDateAttribute()
    {
        return $this->getViewColumnTarget('view_column_table_id', 'options.end_date_type', 'options.end_date_target');
    }

    // @phpstan-ignore-next-line
    public function setViewColumnEndDateAttribute($end_date)
    {
        if (!isset($end_date)) {
            $this->setOption('end_date_type', null);
            $this->setOption('end_date_target', null);
            return $this;
        }

        list($column_type, $column_table_id, $column_type_target, $view_pivot_column, $view_pivot_table) = $this->getViewColumnTargetItems($end_date);

        $this->setOption('end_date_type', $column_type);
        $this->setOption('end_date_target', $column_type_target);

        return $this;
    }


    // @phpstan-ignore-next-line
    public function getViewPivotColumnIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_column_id');
    }

    // @phpstan-ignore-next-line
    public function setViewPivotColumnIdAttribute($view_pivot_column_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_column_id', $view_pivot_column_id);
    }


    // @phpstan-ignore-next-line
    public function getViewPivotTableIdAttribute()
    {
        return $this->getViewPivotIdTrait('view_pivot_table_id');
    }

    // @phpstan-ignore-next-line
    public function setViewPivotTableIdAttribute($view_pivot_table_id)
    {
        return $this->setViewPivotIdTrait('view_pivot_table_id', $view_pivot_table_id);
    }



    // @phpstan-ignore-next-line
    public function getViewGroupConditionAttribute()
    {
        return $this->getOption('view_group_condition');
    }

    // @phpstan-ignore-next-line
    public function setViewGroupConditionAttribute($view_group_condition)
    {
        return $this->setOption('view_group_condition', $view_group_condition);
    }


    // @phpstan-ignore-next-line
    public function getViewColumnEndDateTypeAttribute()
    {
        return $this->getOption('end_date_type');
    }

    /**
     * Export template replace json
     *
     * @param array $json
     * @return void
     */

    // @phpstan-ignore-next-line
    protected function exportReplaceJson(&$json)
    {
        self:: exportReplaceJsonTrait($json);

        $end_date_type = array_get($json, 'options.end_date_type');
        $end_date_target = array_get($json, 'options.end_date_target');

        if ($end_date_target) {
            if ($end_date_type == ConditionType::COLUMN) {
                $custom_column = CustomColumn::find($end_date_target);

                // @phpstan-ignore-next-line
                $json['end_date_target_name'] = $custom_column? $custom_column->column_name: null;
            } elseif ($end_date_type == ConditionType::SYSTEM) {
                $json['end_date_target_name'] =  SystemColumn::getOption(['id' => $end_date_target])['name'];
            }
        }
    }
}
