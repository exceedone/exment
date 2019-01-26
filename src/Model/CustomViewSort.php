<?php

namespace Exceedone\Exment\Model;

class CustomViewSort extends ModelBase
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
    public static function importTemplate($view_column, $options = []){
        $custom_table = array_get($options, 'custom_table');
        $custom_view = array_get($options, 'custom_view');

        $view_column_target = static::getColumnIdOrName(
            array_get($view_column, "view_column_type"), 
            array_get($view_column, "view_column_target_name"), 
            $custom_table,
            true
        );
        // if not set filter_target id, continue
        if (!isset($view_column_target)) {
            return null;
        }

        $view_column_type = ViewColumnType::getEnumValue(array_get($view_column, "view_column_type"));
        $custom_view_sort = CustomviewSort::firstOrNew([
            'custom_view_id' => $custom_view->id,
            'view_column_type' => $view_column_type,
            'view_column_target_id' => $view_column_target,
        ]);
        
        $custom_view_sort->sort = array_get($view_column, "sort", 1);
        $custom_view_sort->priority = array_get($view_column, "priority", 0);
        $custom_view_sort->saveOrFail();

        return $custom_view_sort;
    }    
}
