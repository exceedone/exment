<?php

namespace Exceedone\Exment\Model;

class RoleGroup extends ModelBase
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;

    public static $templateItems = [
        'excepts' => [],
        'uniqueKeys' => ['role_group_name'],
        'langs' => [
            'keys' => ['role_group_name'],
            'values' => ['role_group_view_name', 'description'],
        ],
        'children' =>[
            'role_group_permissions' => RoleGroupPermission::class,
        ],
    ];

    public function role_group_permissions()
    {
        return $this->hasMany(RoleGroupPermission::class, 'role_group_id');
    }

    public function role_group_user_organizations()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id');
    }
    
    public function role_group_users()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'user');
    }

    public function role_group_organizations()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'organization');
    }

    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            $model->role_group_permissions()->delete();
            $model->role_group_user_organizations()->delete();
        });
        
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
        System::resetCache();
    }
}
