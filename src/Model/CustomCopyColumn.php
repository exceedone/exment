<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\CopyColumnType;

class CustomCopyColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    protected $appends = ['view_column_target'];
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;

    public function custom_copy()
    {
        return $this->belongsTo(CustomValueCopy::class, 'custom_copy_id');
    }
    
    public function from_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'from_column_target_id');
    }
    
    public function to_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'to_column_target_id');
    }
    
    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getFromViewColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('from_column_type', 'from_column_target_id');
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setFromViewColumnTargetAttribute($view_column_target)
    {
        $this->setViewColumnTarget($view_column_target, 'from_column_type', 'from_column_target_id');
    }

    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getToViewColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('to_column_type', 'to_column_target_id');
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setToViewColumnTargetAttribute($view_column_target)
    {
        $this->setViewColumnTarget($view_column_target, 'to_column_type', 'to_column_target_id');
    }

    /**
     * import template
     */
    public static function importTemplate($copy_column, $options = [])
    {
        // create copy columns --------------------------------------------------
        $custom_copy = array_get($options, "custom_copy");
        $from_table = array_get($options, "from_table");
        $to_table = array_get($options, "to_table");

        $from_column_type = array_get($copy_column, "from_column_type");
        $to_column_type = array_get($copy_column, "to_column_type");

        // get from and to column
        $from_column_target = static::getColumnIdOrName(
            $from_column_type,
            array_get($copy_column, "from_column_name"),
            $from_table,
            true
        );
        $to_column_target = static::getColumnIdOrName(
            $to_column_type,
            array_get($copy_column, "to_column_name"),
            $to_table,
            true
        );

        if (is_null($to_column_target)) {
            return null;
        }

        $from_column_type = $from_column_type ?: CopyColumnType::getEnumValue($from_column_type);
        $to_column_type = CopyColumnType::getEnumValue($to_column_type);
        $obj_copy_column = CustomCopyColumn::firstOrNew([
            'custom_copy_id' => $custom_copy->id,
            'from_column_type' => $from_column_type,
            'from_column_target_id' => $from_column_target ?? null,
            'to_column_type' => $to_column_type,
            'to_column_target_id' => $to_column_target ?? null,
            'copy_column_type' => array_get($copy_column, "copy_column_type"),
        ]);
        $obj_copy_column->saveOrFail();

        return $obj_copy_column;
    }
}
