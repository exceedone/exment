<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
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
                'uniqueKeyClassName' => CustomTable::class,
            ]
        ]
    ];
    
    public function role_group()
    {
        return $this->belongsTo(RoleGroup::class, 'role_group_id');
    }

}
