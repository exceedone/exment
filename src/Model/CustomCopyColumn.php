<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\ViewColumnType;

class CustomCopyColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    protected $appends = ['from_column_target', 'to_column_target'];
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;

    protected static $templateItems = [
        'excepts' => ['id', 'custom_copy_id', 'from_custom_column', 'to_custom_column', 'from_column_target', 'to_column_target', 'from_column_target_id', 'to_column_target_id', 'from_column_table_id', 'to_column_table_id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['from_column_type', 'from_column_target_id', 'to_column_type', 'to_column_target_id', 'copy_column_type'],
        'langs' => [],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'from_column_table_name',
                            'column_name' => 'from_column_target_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getFromUniqueKeyValues',
            ],
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'table_name' => 'to_column_table_name',
                            'column_name' => 'to_column_target_name',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getToUniqueKeyValues',
            ],
        ]
    ];

    public function custom_copy()
    {
        return $this->belongsTo(CustomCopy::class, 'custom_copy_id');
    }
    
    public function from_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'from_column_target_id');
    }
    
    public function to_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'to_column_target_id');
    }
    
    public function from_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'from_column_table_id');
    }
    
    public function to_custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'to_column_table_id');
    }
    
    /**
     * get CopyColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getFromColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('from_column_table_id', 'from_column_type', 'from_column_target_id');
    }
    
    /**
     * set CopyColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setFromColumnTargetAttribute($copy_column_target)
    {
        $this->setViewColumnTarget($copy_column_target, 'custom_copy', 'from_column_table_id', 'from_column_type', 'from_column_target_id');
    }

    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getToColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('to_column_table_id', 'to_column_type', 'to_column_target_id');
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setToColumnTargetAttribute($copy_column_target)
    {
        $this->setViewColumnTarget($copy_column_target, 'custom_copy', 'to_column_table_id', 'to_column_type', 'to_column_target_id');
    }
    
    /**
     * get Table And Column Name
     */
    protected function getFromUniqueKeyValues()
    {
        return $this->getCopyColumnUniqueKeyValues('from_custom_table', 'from_custom_column', 'from_column_type', 'from_column_target_id');
    }

    /**
     * get Table And Column Name
     */
    protected function getToUniqueKeyValues()
    {
        return $this->getCopyColumnUniqueKeyValues('to_custom_table', 'to_custom_column', 'to_column_type', 'to_column_target_id');
    }

    /**
     * get Table And Column Name for custom copy column
     */
    protected function getCopyColumnUniqueKeyValues($column_table_key, $column_column_key, $column_type_key, $column_target_id_key)
    {
        // get custom table.
        $table_name = $this->{$column_table_key}->table_name ?? null;
        // if(!isset($custom_table)){
        //     $table_name = null;
        // }else{
        //     $table_name = $custom_table->table_name;
        // }

        switch ($this->{$column_type_key}) {
            case ViewColumnType::COLUMN:
                return [
                    'table_name' => $table_name,
                    'column_name' => $this->{$column_column_key}->column_name ?? null,
                ];
            case ViewColumnType::SYSTEM:
                return [
                    'table_name' => $table_name,
                    'column_name' => SystemColumn::getOption(['id' => $this->{$column_target_id_key}])['name'],
                ];
            
            case ViewColumnType::PARENT_ID:
                return [
                    'table_name' => $table_name,
                    'column_name' => Define::CUSTOM_COLUMN_TYPE_PARENT_ID,
                ];
        }
        return [];
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
        $from_column_target = static::getColumnAndTableId(
            $from_column_type,
            array_get($copy_column, "from_column_name"),
            $from_table,
            true
        );
        $to_column_target = static::getColumnAndTableId(
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
