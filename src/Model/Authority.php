<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\SystemTableName;

class Authority extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['permissions' => 'json'];

    protected $guarded = ['id'];
    
    /**
     * Get atuhority name.
     * @return string
     */
    public function getAuthorityName($related_type)
    {
        return "authority_{$this->suuid}_{$related_type}";
    }

    /**
     * get authority loop function and execute callback
     * @param $related_type string "user" or "organization" string.
     */
    public static function authorityLoop($related_type, $callback)
    {
        if (!Schema::hasTable(System::getTableName()) || !Schema::hasTable(static::getTableName())) {
            return;
        }
        if (!System::authority_available()) {
            return;
        }
        
        // get Authority setting
        $authorities = Authority::where('authority_type', $related_type)->get();
        foreach ($authorities as $authority) {
            $related_types = [SystemTableName::USER];
            // if use organization, add
            if (System::organization_available()) {
                $related_types[] = SystemTableName::ORGANIZATION;
            }
            foreach ($related_types as $related_type) {
                $callback($authority, $related_type);
            }
        }
    }
    
}
