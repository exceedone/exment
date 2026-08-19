<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;

/**
 * @phpstan-consistent-constructor
 * @property mixed $suuid
 * @property mixed $row_no
 * @property mixed $dashboard_box_view_name
 * @property mixed $dashboard_box_type
 * @property mixed $column_no
 * @property mixed $options
 * @method static \Illuminate\Database\Query\Builder whereNotNull($columns, $boolean = 'and')
 * @method static int count($columns = '*')
 * @method static \Illuminate\Database\Query\Builder orderBy($column, $direction = 'asc')
 */
class DashboardBox extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];

    /**
     * Set by the chart box form's saving hook (ChartItem::saving) for the ONE save that
     * follows: re-merge stored option keys the form did not submit (see boot()). A plain
     * class property, not an Eloquent attribute — it never reaches the database. Off by
     * default so seeds / feature code that deliberately REMOVE an option key keep working.
     *
     * @var bool
     */
    public $mergeStoredOptions = false;

    // @phpstan-ignore-next-line
    protected static function boot()
    {
        parent::boot();

        // Real-path guard for the admin-form "embeds wipe": laravel-admin runs the field-
        // level prepare (EmbeddedForm::prepare — strips every option key the form does not
        // declare) AFTER the form's saving callbacks, so a merge done in ChartItem::saving
        // cannot survive prepareUpdate. Here, at Eloquent level, the ORIGINAL attribute
        // still holds the stored JSON — restore every key ABSENT from the new array.
        // Safe against deliberate clears: the embedded form always submits every key it
        // declares (empty included), so an absent key can only be one the form never knew
        // about (chart_level_views, chart_value_mean, chart_benchmark, ...).
        static::saving(function ($model) {
            if (!$model->mergeStoredOptions || !$model->exists || !$model->isDirty('options')) {
                return;
            }
            $model->mergeStoredOptions = false; // one shot
            $new = $model->options;
            $old = json_decode((string) $model->getRawOriginal('options'), true);
            if (!is_array($new) || !is_array($old)) {
                return;
            }
            $changed = false;
            foreach ($old as $key => $value) {
                if (!array_key_exists($key, $new)) {
                    $new[$key] = $value;
                    $changed = true;
                }
            }
            if ($changed) {
                $model->options = $new;
            }
        });
    }


    // @phpstan-ignore-next-line
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
                            'view_column_type' => 'options.chart_axisx_view_column_type',
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
                            'view_column_type' => 'options.chart_axisy_view_column_type',
                            'view_kind_type' => 'options.chart_axisy_type',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
                'uniqueKeyFunctionArgs' => ['options.chart_axisy'],
            ],
        ],
    ];


    // @phpstan-ignore-next-line
    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }


    // @phpstan-ignore-next-line
    public function getDashboardBoxItemAttribute()
    {
        // An unknown / legacy box type (a feature that was removed, or a plugin box whose
        // plugin is gone) has no enum. Return null so ONE stray box degrades gracefully
        // instead of a null-method-call taking down the entire dashboard.
        $enum = DashboardBoxType::getEnum($this->dashboard_box_type);
        if (is_null($enum)) {
            return null;
        }

        $enum_class = $enum->getDashboardBoxItemClass();
        return $enum_class::getItem($this) ?? null;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */

    // @phpstan-ignore-next-line
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * Get box html arrtibute. For
     *
     * @return array
     */

    // @phpstan-ignore-next-line
    public function getBoxHtmlAttr(): array
    {
        $attributes = [
            'row_no' => $this->row_no,
            'column_no' => $this->column_no,
            'dashboard_box_view_name' => $this->dashboard_box_view_name,
            'dashboard_box_type' => $this->dashboard_box_type,
        ];
        $item = $this->dashboard_box_item;
        $attributes = array_merge($item ? $item->attributes() : [], $attributes);

        return collect($attributes)->mapWithKeys(function ($attr, $key) {
            return ["data-$key" => $attr];
        })->toArray();
    }


    // @phpstan-ignore-next-line
    protected function getUniqueKeyValues($key)
    {
        if (is_array($key) && count($key) > 0) {
            $key = $key[0];
        }

        // get dashboard value
        $view_column = CustomViewSummary::getSummaryViewColumn(array_get($this, $key));
        if (is_nullorempty($view_column)) {
            return [
                'table_name' => null,
                'column_name' => null,
                'view_column_type' => null,
                'view_kind_type' => null,
            ];
        } elseif ($view_column == Define::CHARTITEM_LABEL) {
            // get table name and view name
            $custom_table = CustomTable::getEloquent($this->getOption('target_table_id'));
            $custom_view = CustomView::getEloquent($this->getOption('target_view_id'));
            return [
                'table_name' => array_get($custom_table, 'table_name'),
                'column_name' => $view_column,
                'view_column_type' => $view_column,
                'view_kind_type' => array_get($custom_view, 'view_kind_type'),
            ];
        }

        $items = $view_column->getUniqueKeyValues();
        return [
            'table_name' => array_get($items, 'table_name'),
            'column_name' => array_get($items, 'column_name'),
            'view_column_type' => array_get($items, 'column_type'),
            'view_kind_type' => $view_column instanceof CustomViewSummary ? ViewKindType::AGGREGATE : ViewKindType::DEFAULT,
        ];
    }


    // @phpstan-ignore-next-line
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
        static::importReplaceJsonCustomColumn('chart_axisx', $json);
        static::importReplaceJsonCustomColumn('chart_axisy', $json);
    }


    // @phpstan-ignore-next-line
    protected static function importReplaceJsonCustomColumn($key, &$json)
    {
        $custom_column_key = "options.{$key}_column_name";
        $custom_table_key = "options.{$key}_table_name";
        $view_column_type_key = "options.{$key}_view_column_type";
        $table_type_key = "options.{$key}_type";

        $table_name = array_get($json, $custom_table_key);
        $column_name = array_get($json, $custom_column_key);
        $view_column_type = array_get($json, $view_column_type_key);

        switch ($view_column_type) {
            case Define::CHARTITEM_LABEL:
                $id = $view_column_type;
                break;
            case ConditionType::COLUMN:
                $custom_column = CustomColumn::getEloquent($column_name, $table_name);
                $id = array_get($custom_column, 'id');
                break;
            case ConditionType::SYSTEM:
                $custom_column = SystemColumn::getOption(['name' => $column_name]);
                $id = array_get($custom_column, 'id');
                break;
            case ConditionType::PARENT_ID:
                $id = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
                break;
        }

        /** @var mixed $id */
        if (isset($id) && \is_numeric($id)) {
            $table_type = array_get($json, $table_type_key);
            if ($table_type == ViewKindType::AGGREGATE) {
                /** @var CustomViewColumn|null $view_column */
                $view_column = CustomViewSummary::where('custom_view_id', array_get($json, 'options.target_view_id'))
                    ->where('view_column_type', $view_column_type)
                    ->where('view_column_target_id', $id)->first();
            } else {
                /** @var CustomViewColumn|null $view_column */
                $view_column = CustomViewColumn::where('custom_view_id', array_get($json, 'options.target_view_id'))
                    ->where('view_column_type', $view_column_type)
                    ->where('view_column_target_id', $id)->first();
            }
            if (isset($view_column)) {
                array_set($json, "options.{$key}", $table_type.'_'.$view_column->id);
            }
        }
        // Define::CHARTITEM_LABEL
        elseif (isset($id) && is_string($id)) {
            array_set($json, "options.{$key}", $id);
        }

        array_forget($json, $custom_column_key);
        array_forget($json, $custom_table_key);
        array_forget($json, $view_column_type_key);
        array_forget($json, $table_type_key);
    }
}
