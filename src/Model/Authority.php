<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Schema;
use Exceedone\Exment\Enums\AuthorityType;

class Authority extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['permissions' => 'json'];

    protected $guarded = ['id'];

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
            $related_types = [Define::SYSTEM_TABLE_NAME_USER];
            // if use organization, add
            if (System::organization_available()) {
                $related_types[] = Define::SYSTEM_TABLE_NAME_ORGANIZATION;
            }
            foreach ($related_types as $related_type) {
                $callback($authority, $related_type);
            }
        }
    }
    
    /**
     * get users or organiztions who has authorities.
     */
    protected static function getAuthorityUserOrgQuery($target_table, $related_type)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
    
        // get user or organiztion ids
        $target_ids = \DB::table('authorities as a')
                ->join(Define::SYSTEM_TABLE_NAME_SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
                ->where('related_type', $related_type)
                ->where(function ($query) use ($target_table) {
                    $query->orWhere(function ($query) {
                        $query->where('morph_type', AuthorityType::SYSTEM);
                    });
                    $query->orWhere(function ($query) use ($target_table) {
                        $query->where('morph_type', AuthorityType::TABLE)
                        ->where('morph_id', $target_table->id);
                    });
                })->get(['related_id'])->pluck('related_id');
            
        // return target values
        $query = getModelName($related_type)::query();
        $query =  $query->whereIn('id', $target_ids);
        return $query;
    }    
}
