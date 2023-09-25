<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

/**
 * @phpstan-consistent-constructor
 * @property mixed $suuid
 * @property mixed $view_column_target_id
 * @property mixed $view_column_table_id
 * @property mixed $custom_view_id
 * @property mixed $view_filter_condition_value_text
 * @property mixed $view_filter_condition
 * @property mixed $view_group_condition
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomViewFilter extends ModelBase
{
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    use Traits\AutoSUuidTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ConditionTypeTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target', 'view_filter_condition_value'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => [
            'import' => ['custom_table', 'view_column_table_id', 'view_column_target', 'custom_column'],
            'export' => ['custom_table', 'view_column_table_id', 'view_column_target_id', 'custom_view_id', 'view_column_target', 'custom_column', 'view_filter_condition_value_table_id', 'view_filter_condition_value_id'],
        ],
        'uniqueKeys' => [
            'custom_view_id', 'view_column_type', 'view_column_target_id', 'view_column_table_id', 'view_filter_condition'
        ],
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
            'view_filter_condition' => FilterOption::class,
        ],
    ];

    /**
     * get edited view_filter_condition_value_text.
     */
    public function getViewFilterConditionValueAttribute()
    {
        if (is_string($this->view_filter_condition_value_text)) {
            $array = json_decode_ex($this->view_filter_condition_value_text);
            if (is_array($array)) {
                return array_filter($array, function ($val) {
                    return !is_null($val);
                });
            }
        }
        return $this->view_filter_condition_value_text;
    }

    /**
     * set view_filter_condition_value_text.
     * * we have to convert int if view_filter_condition_value is array*
     */
    public function setViewFilterConditionValueAttribute($view_filter_condition_value)
    {
        if (is_array($view_filter_condition_value)) {
            $array = array_filter($view_filter_condition_value, function ($val) {
                return !is_null($val);
            });
            $this->view_filter_condition_value_text = json_encode($array);
        } else {
            $this->view_filter_condition_value_text = $view_filter_condition_value;
        }
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
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
     * set value filter
     */
    public function setValueFilter($query, $or_option = false)
    {
        // get filter target column
        $condition_value_text = $this->view_filter_condition_value_text;
        $view_filter_condition = $this->view_filter_condition;

        $viewFilterItem = ViewFilterBase::make($this->view_filter_condition, $this->column_item, [
            'or_option' => $or_option,
        ]);

        $viewFilterItem->setFilter($query, $condition_value_text);
        return $query;
    }
}
