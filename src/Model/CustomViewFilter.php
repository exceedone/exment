<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ViewColumnType;

class CustomViewFilter extends ModelBase
{
    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;
    use Traits\UseRequestSessionTrait;

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'view_column_target');
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = []){
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * import template
     */
    public static function importTemplate($view_filter, $options = []){
        $custom_table = array_get($options, 'custom_table');
        $custom_view = array_get($options, 'custom_view');

        // if not set filter_target id, continue
        $view_column_target = static::getColumnIdOrName(
            array_get($view_filter, "view_column_type"), 
            array_get($view_filter, "view_column_target_name"), 
            $custom_table,
            true
        );

        if (!isset($view_column_target)) {
            return null;
        }

        $view_column_type = ViewColumnType::getEnumValue(array_get($view_filter, "view_column_type"));
        $custom_view_filter = CustomViewFilter::firstOrNew([
            'custom_view_id' => $custom_view->id,
            'view_column_type' => $view_column_type,
            'view_column_target_id' => $view_column_target,
            'view_filter_condition' => array_get($view_filter, "view_filter_condition"),
        ]);
        $custom_view_filter->view_filter_condition_value_text = array_get($view_filter, "view_filter_condition_value_text");
        $custom_view_filter->saveOrFail();

        return $custom_view_filter;
    }    
}
