<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\RoleType;

class RoleGroupPermission extends ModelBase
{
    use Traits\TemplateTrait;

    protected $casts = ['permissions' => 'json'];
    
    public static $templateItems = [
        'excepts' => ['role_group'],
        'uniqueKeys' => [
            'export' => [
                'role_group.role_group_name', 'role_group_permission_type', 'role_group_target_name'
            ],
            'import' => [
                'role_group_id', 'role_group_permission_type', 'role_group_target_id'
            ],
        ],
        'parent' => 'role_group_id',
        'enums' => [
            'role_group_permission_type' => RoleType::class,
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'role_group_target_id',
                        'replacedName' => [
                            'table_name' => 'role_group_target_name',
                        ],
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
            ]
        ]
    ];
    
    public function role_group()
    {
        return $this->belongsTo(RoleGroup::class, 'role_group_id');
    }
    
    /**
     * get Table Name or system name
     */
    protected function getUniqueKeyValues()
    {
        switch ($this->role_group_permission_type) {
            case RoleType::SYSTEM:
                return [
                    'role_group_target_name' => $this->role_group_target_id ?? null,
                ];
            case RoleType::TABLE:
                return [
                    'role_group_target_name' => CustomTable::getEloquent($this->role_group_target_id)->table_name ?? null,
                ];
        }
        return [];
    }
    
    protected static function importReplaceJson(&$json, $options = [])
    {
        $role_group_target_name = array_get($json, 'role_group_target_name');
        $role_group_target_id = null;

        switch (array_get($json, 'role_group_permission_type')) {
            case RoleType::SYSTEM:
                $role_group_target_id = $role_group_target_name;
                break;
            case RoleType::TABLE:
                $role_group_target_id = CustomTable::getEloquent($role_group_target_name)->id ?? null;
                break;
        }
        array_set($json, 'role_group_target_id', $role_group_target_id);
        array_forget($json, 'role_group_target_name');
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::saved(function ($model) {
            $model->clearCache();
        });
    }
    
    /**
     * Clear cache
     *
     * @return void
     */
    public function clearCache()
    {
        RoleGroup::resetAllRecordsCache();
    }
    
}
