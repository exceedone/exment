<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class Role extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    protected $casts = ['permissions' => 'json'];
    protected $guarded = ['id'];
    
    public static $templateItems = [
        'excepts' => [],
        'uniqueKeys' => ['role_name'],
        'langs' => [
            'keys' => ['role_name'],
            'values' => ['role_view_name', 'description'],
        ],
        'children' =>[
            'dashboard_boxes',
        ],
        'enums' => [
            'role_type' => RoleType::class,
        ],
        'defaults' => [
            'default_flg' => false,
        ]
    ];

    protected const EXPORT_TEMPLATE_ITEMS = ['role_type', 'role_name', 'role_view_name', 'description', 'permissions'];
    protected const EXPORT_LANG_ITEMS = ['role_name', 'role_view_name', 'description'];

    /**
     * Get atuhority name.
     * @return string
     */
    public function getRoleName($related_type)
    {
        return "role_{$this->suuid}_{$related_type}";
    }

    /**
     * get role loop function and execute callback
     * @param $related_type string "user" or "organization" string.
     */
    public static function roleLoop($related_type, $callback)
    {
        if (!hasTable(System::getTableName()) || !hasTable(static::getTableName())) {
            return;
        }
        if (!System::permission_available()) {
            return;
        }
        
        // get Role setting
        $roles = static::allRecords(function ($record) use ($related_type) {
            if (is_null($related_type) || is_int($related_type)) {
                return $record->role_type == $related_type;
            } else {
                return $record->role_type == $related_type->getValue();
            }
        });
        foreach ($roles as $role) {
            $related_types = [SystemTableName::USER];
            // if use organization, add
            if (System::organization_available()) {
                $related_types[] = SystemTableName::ORGANIZATION;
            }
            foreach ($related_types as $related_type) {
                $callback($role, $related_type);
            }
        }
    }
    
    public static function importReplaceJson(&$json, $options = [])
    {
        // Create role detail.
        if (array_key_exists('permissions', $json)) {
            $permissions = [];
            foreach (array_get($json, 'permissions', []) as $key => $permission) {
                if (is_numeric($key)) {
                    $permissions[$permission] = "1";
                } else {
                    $permissions[$key] = "1";
                }
            }
            $json['permissions'] = $permissions;
        } else {
            $json['permissions'] = [];
        }
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
}
