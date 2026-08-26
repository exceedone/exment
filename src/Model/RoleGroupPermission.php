<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;

/**
 * @phpstan-consistent-constructor
 * @property mixed $role_group_id
 * @property mixed $role_group_target_id
 * @property mixed $role_group_permission_type
 * @property mixed $permissions
 */
class RoleGroupPermission extends ModelBase
{
    use Traits\TemplateTrait;
    use Traits\ClearCacheTrait;

    protected $casts = ['permissions' => 'json'];


    // @phpstan-ignore-next-line
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


    // @phpstan-ignore-next-line
    public function role_group()
    {
        return $this->belongsTo(RoleGroup::class, 'role_group_id');
    }

    /**
     * get Table Name or system name
     */

    // @phpstan-ignore-next-line
    protected function getUniqueKeyValues()
    {
        switch ($this->role_group_permission_type) {
            case RoleType::SYSTEM:
                // Convert stored SystemRoleType value ('0'/'1') back to a readable key
                // ('system' / 'role_group') for portable templates.
                $enum = SystemRoleType::getEnum($this->role_group_target_id);
                $targetName = $enum ? $enum->lowerKey() : ($this->role_group_target_id ?? null);
                return [
                    'table_name' => $targetName,
                ];
            case RoleType::TABLE:
                return [
                    'table_name' => CustomTable::getEloquent($this->role_group_target_id)->table_name ?? null,
                ];
        }
        return [];
    }


    // @phpstan-ignore-next-line
    protected static function importReplaceJson(&$json, $options = [])
    {
        $role_group_target_name = array_get($json, 'role_group_target_name');
        $role_group_target_id = null;

        // Normalize permission_type so both raw values ('0','1') and enum keys ('system','table')
        // resolve to the same case. Templates typically ship the human-readable keys.
        $type = array_get($json, 'role_group_permission_type');
        $normalizedType = RoleType::getEnumValue($type, $type);

        switch ($normalizedType) {
            case RoleType::SYSTEM:
                // For SYSTEM permissions the target column stores a SystemRoleType value
                // ('0' for SYSTEM, '1' for ROLE_GROUP). Accept both the raw values and the
                // enum keys (e.g. "system", "role_group") in the template JSON.
                $role_group_target_id = SystemRoleType::getEnumValue(
                    $role_group_target_name,
                    $role_group_target_name
                );
                break;
            case RoleType::TABLE:
                $customTable = CustomTable::getEloquent($role_group_target_name);
                $role_group_target_id = $customTable ? $customTable->id : null;
                break;
        }
        array_set($json, 'role_group_target_id', $role_group_target_id);
        array_forget($json, 'role_group_target_name');
    }
}
