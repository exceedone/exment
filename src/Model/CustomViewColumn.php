<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ViewColumnType;

class CustomViewColumn extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];
    protected $with = ['custom_column'];
    
    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        if ($this->view_column_type == ViewColumnType::SYSTEM) {
            return null;
        }
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * import template
     */
    public static function importTemplate($view_column, $options = [])
    {
        $custom_table = array_get($options, 'custom_table');
        $custom_view = array_get($options, 'custom_view');

        $view_column_type = array_get($view_column, "view_column_type");
        $view_column_target_id = static::getColumnIdOrName(
            $view_column_type,
            array_get($view_column, "view_column_target_name"),
            $custom_table,
            true
        );
        // if not set column id, continue
        if ($view_column_type != ViewColumnType::PARENT_ID && !isset($view_column_target_id)) {
            return null;
        }

        $view_column_type = ViewColumnType::getEnumValue($view_column_type);
        $custom_view_column = CustomViewColumn::firstOrNew([
            'custom_view_id' => $custom_view->id,
            'view_column_type' => $view_column_type,
            'view_column_target_id' => $view_column_target_id,
        ]);
        $custom_view_column->order = array_get($view_column, "order");
        $custom_view_column->saveOrFail();

        return $custom_view_column;
    }
}
