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

    // /**
    //  * import template
    //  */
    // public static function importTemplate($view_column, $options = [])
    // {
    //     $custom_table = array_get($options, 'custom_table');
    //     $custom_view = array_get($options, 'custom_view');

    //     $view_column_type = array_get($view_column, "view_column_type");
    //     list($view_column_target_id, $view_column_table_id) = static::getColumnAndTableId(
    //         $view_column_type,
    //         array_get($view_column, "view_column_target_name"),
    //         $custom_table
    //     );
    //     // if not set column id, continue
    //     if ($view_column_type != ViewColumnType::PARENT_ID && !isset($view_column_target_id)) {
    //         return null;
    //     }

    //     $view_column_type = ViewColumnType::getEnumValue($view_column_type);
    //     $custom_view_summary = CustomViewSummary::firstOrNew([
    //         'custom_view_id' => $custom_view->id,
    //         'view_column_type' => $view_column_type,
    //         'view_column_target_id' => $view_column_target_id,
    //         'view_column_table_id' => $view_column_table_id,
    //     ]);
    //     $custom_view_column->view_summary_condition = array_get($view_column, "view_summary_condition");
    //     $custom_view_column->view_column_name = array_get($view_column, "view_column_name");
    //     $custom_view_summary->saveOrFail();

    //     return $custom_view_summary;
    // }
}
