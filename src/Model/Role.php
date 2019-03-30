<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Enums\SystemTableName;

class Role extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['permissions' => 'json'];

    protected $guarded = ['id'];
    
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
        if (!Schema::hasTable(System::getTableName()) || !Schema::hasTable(static::getTableName())) {
            return;
        }
        if (!System::permission_available()) {
            return;
        }
        
        // get Role setting
        $roles = Role::where('role_type', $related_type)->get();
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
}
