<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-consistent-constructor
 * @property mixed $view_pivot_column_id
 * @property mixed $view_pivot_table_id
 * @property mixed $view_column_type
 * @property mixed $view_column_target_id
 * @property mixed $view_column_table_id
 * @property mixed $to_column_type
 * @property mixed $to_column_target_id
 * @property mixed $to_column_table_id
 * @property mixed $suuid
 * @property mixed $from_column_type
 * @property mixed $from_column_target_id
 * @property mixed $from_column_table_id
 * @property mixed $custom_view_id
 * @property mixed $copy_column_type
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomCopyColumn extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\CustomViewColumnTrait;
    use Traits\TemplateTrait;
    protected $appends = ['from_column_target', 'to_column_target'];

    public static $templateItems = [
        //'excepts' => ['custom_copy_id', 'from_custom_column', 'to_custom_column', 'from_column_target', 'to_column_target', 'from_column_target_id', 'to_column_target_id', 'from_column_table_id', 'to_column_table_id'],
        'excepts' => [
            'export' => ['from_custom_column', 'to_custom_column', 'from_column_target', 'to_column_target', 'from_column_target_id', 'to_column_target_id', 'from_column_table_id', 'to_column_table_id'],
            'import' => ['from_custom_column', 'to_custom_column', 'from_column_target', 'to_column_target', 'from_column_target_name', 'to_column_target_name', 'from_column_table_name', 'to_column_table_name'],
        ],
        'keys' => ['from_column_type', 'from_column_target_id', 'to_column_type', 'to_column_target_id', 'copy_column_type'],
        'langs' => [],
        'parent' => 'custom_copy_id',
        'uniqueKeys' => [
            'export' => ['from_column_type', 'from_column_target_name', 'from_column_table_name', 'to_column_type', 'to_column_target_name', 'to_column_table_name'],
            'import' => ['custom_copy_id', 'from_column_type', 'from_column_table_id', 'from_column_target_id', 'to_column_type', 'to_column_table_id', 'to_column_target_id'],
        ] ,
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

    public function custom_copy(): BelongsTo
    {
        return $this->belongsTo(CustomCopy::class, 'custom_copy_id');
    }

    public function from_custom_column(): BelongsTo
    {
        return $this->belongsTo(CustomColumn::class, 'from_column_target_id');
    }

    public function to_custom_column(): BelongsTo
    {
        return $this->belongsTo(CustomColumn::class, 'to_column_target_id');
    }

    public function from_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'from_column_table_id');
    }

    public function to_custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'to_column_table_id');
    }

    public function getFromCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->from_column_table_id);
    }

    public function getToCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->to_column_table_id);
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
     * getConditionTypeFromItemAttribute
     */
    public function getFromConditionItemAttribute()
    {
        return ConditionItemBase::getItem($this->from_custom_table_cache, $this->from_column_type, $this->from_column_target_id);
    }

    /**
     * getConditionTypeFromItemAttribute
     */
    public function getToConditionItemAttribute()
    {
        return ConditionItemBase::getItem($this->to_custom_table_cache, $this->to_column_type, $this->to_column_target_id);
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
            case ConditionType::COLUMN:
                return [
                    'table_name' => $table_name,
                    'column_name' => $this->{$column_column_key}->column_name ?? null,
                ];
            case ConditionType::SYSTEM:
            case ConditionType::WORKFLOW:
                return [
                    'table_name' => $table_name,
                    'column_name' => SystemColumn::getOption(['id' => $this->{$column_target_id_key}])['name'],
                ];

            case ConditionType::PARENT_ID:
                return [
                    'table_name' => $table_name,
                    'column_name' => Define::CUSTOM_COLUMN_TYPE_PARENT_ID,
                ];
        }
        return [];
    }

    public static function importReplaceJson(&$json, $options = [])
    {
        $custom_copy = array_get($options, 'parent');

        // get from and to column
        list($from_column_target_id, $from_column_table_id) = static::getColumnAndTableId(
            array_get($json, "from_column_type"),
            array_get($json, "from_column_target_name"),
            $custom_copy->from_custom_table
        );
        list($to_column_target_id, $to_column_table_id) = static::getColumnAndTableId(
            array_get($json, "to_column_type"),
            array_get($json, "to_column_target_name"),
            $custom_copy->to_custom_table
        );

        $json['custom_copy_id'] = $custom_copy->id;
        $json['from_column_target_id'] = $from_column_target_id;
        $json['from_column_table_id'] = $from_column_table_id;
        $json['to_column_target_id'] = $to_column_target_id;
        $json['to_column_table_id'] = $to_column_table_id;

        array_forget($json, 'from_column_table_name');
        array_forget($json, 'from_column_target_name');
        array_forget($json, 'to_column_table_name');
        array_forget($json, 'to_column_target_name');
    }
}
