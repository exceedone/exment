<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomViewSummary;

class DashboardBox extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['suuid'],
        'langs' => [
            'keys' => ['row_no', 'column_no'],
            'values' => ['dashboard_box_view_name'],
        ],
        'parent' => 'dashboard_id',
        'uniqueKeys' => ['dashboard_id', 'row_no', 'column_no'],
        'enums' => [
            'dashboard_box_type' => DashboardBoxType::class,
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_table_id',
                        'replacedName' => [
                            'table_name' => 'options.target_table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_view_id',
                        'replacedName' => [
                            'suuid' => 'options.target_view_suuid',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomView::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_system_id',
                        'replacedName' => [
                            'name' => 'options.target_system_name',
                        ]
                    ]
                ],
                'uniqueKeySystemEnum' => DashboardBoxSystemPage::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.chart_axisx_table_name',
                            'column_name' => 'options.chart_axisx_column_name',
                            'view_kind_type' => 'options.chart_axisx_type',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.chart_axisx'],
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'options.chart_axisy_table_name',
                            'column_name' => 'options.chart_axisy_column_name',
                            'view_kind_type' => 'options.chart_axisy_type',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.chart_axisy'],
            ],
        ],
    ];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    public function getDashboardBoxItemAttribute()
    {
        $enum_class = DashboardBoxType::getEnum($this->dashboard_box_type)->getDashboardBoxItemClass();
        return $enum_class::getItem($this) ?? null;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    protected function getUniqueKeyValues($key)
    {
        if (is_array($key) && count($key) > 0) {
            $key = $key[0];
        }

        // get dashboard value
        $view_column = CustomViewSummary::getSummaryViewColumn(array_get($this, $key));
        if(!isset($view_column)){
            return [
                'table_name' => null,
                'column_name' => null,
                'view_kind_type' => null,
            ];
        }

        $items = $view_column->getUniqueKeyValues();
        return [
            'table_name' => array_get($items, 'table_name'),
            'column_name' => array_get($items, 'column_name'),
            'view_kind_type' => $view_column instanceof CustomViewSummary ? ViewKindType::AGGREGATE : ViewKindType::DEFAULT,
        ];
    }

    protected static function importReplaceJson(&$json, $options = [])
    {
        // switch dashboard_box_type
        $dashboard_box_type = DashboardBoxType::getEnumValue(array_get($json, 'dashboard_box_type'));
        switch ($dashboard_box_type) {
            // system box
            case DashboardBoxType::SYSTEM:
                $id = collect(DashboardBoxSystemPage::options())->first(function ($value) use ($json) {
                    return array_get($value, 'name') == array_get($json, 'options.target_system_name');
                })['id'] ?? null;
                array_set($json, 'options.target_system_id', $id);
                break;
            
            // list
            case DashboardBoxType::LIST:
            case DashboardBoxType::CALENDAR:
            case DashboardBoxType::CHART:
                // get target table
                array_set($json, 'options.target_table_id', CustomTable::getEloquent(array_get($json, 'options.target_table_name'))->id ?? null);
                // get target view using suuid
                array_set($json, 'options.target_view_id', CustomView::findBySuuid(array_get($json, 'options.target_view_suuid'))->id ?? null);
                break;
        }
        
        // replace chartx and y
        static::importReplaceJsonTableColumn($json, 'chartx');
        static::importReplaceJsonTableColumn($json, 'charty');
    }

    protected static function importReplaceJsonCustomColumn(&$json, $replace_custom_column_key)
    {
        $custom_column = CustomColumn::getEloquent(array_get($json, "options.{$replace_custom_column_key}_column_name"), array_get($json, "options.{$replace_custom_column_key}_table_name"));

        // if exists, set as params
        if (isset($custom_column)) {
            $key = 
            array_set($json, $replace_custom_column_key, $custom_column->id);
        }

        array_forget($json, $custom_column_key);
        array_forget($json, $custom_table_key);
    }
}
