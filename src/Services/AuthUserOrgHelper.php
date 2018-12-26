<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\AuthorityType;


/**
 * Authority, user , organization helper
 */
class AuthUserOrgHelper
{
    /**
     * get organiztions who has authorities.
     * this function is called from custom value authority
     */
    // getAuthorityUserOrgQuery
    public static function getAuthorityOrganizationQuery($target_table, &$builder = null)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
    
        // get organiztion ids
        $target_ids = static::getAuthorityUserOrgId($target_table, SystemTableName::ORGANIZATION);

        // return target values
        if(!isset($builder)){
            $builder = getModelName(SystemTableName::ORGANIZATION)::query();
        }
        $builder->whereIn('id', $target_ids);
        return $builder;
    }
    
    /**
     * get users who has authorities.
     * and get users joined parent or children organizations
     * this function is called from custom value authority
     */
    // getAuthorityUserOrgQuery
    public static function getAuthorityUserQuery($target_table, &$builder = null)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
    
        // get user ids
        $user_ids = static::getAuthorityUserOrgId($target_table, SystemTableName::USER);

        // and get authoritiable organization
        $organizations = static::getAuthorityOrganizationQuery($target_table)
            ->with('users')
            ->get() ?? [];
        foreach($organizations as $organization){
            foreach($organization->all_related_organizations() as $related_organization){
                foreach($related_organization->users as $user){
                    $user_ids[] = $user->id;
                }
            }
        }

        // return target values
        if (!isset($builder)) {
            $builder = getModelName(SystemTableName::USER)::query();
        }
        $builder->whereIn('id', $user_ids);
        return $builder;
    }
    
    /**
     * get organizations as eloquent model
     * @return mixed
     */
    public static function getOrganizations($appendFunc = null)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        $query = static::getOrganizationQuery();
        $deeps = intval(config('exment.organization_deeps', 4));
        if(isset($appendFunc)){
            $appendFunc($query, $deeps);
        }

        $orgs = $query->get();
        return $orgs;
    }

    /**
     * get organization ids
     * @return mixed
     */
    public static function getOrganizationIds($appendFunc = null)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        
        $orgs = static::getOrganizations($appendFunc);
        $org_flattens = [];
        static::setFlattenOrganizations($orgs, $org_flattens);
        return collect($org_flattens)->map(function($org_flatten){
            return $org_flatten->id;
        })->toArray();
    }

    public static function getOrganizationQuery(){
        // get organization ids.
        $db_table_name_organization = getDBTableName(SystemTableName::ORGANIZATION);
        $parent_org_index_name = CustomColumn::getEloquent('parent_organization', CustomTable::getEloquent(SystemTableName::ORGANIZATION))->getIndexColumnName();
        $deeps = intval(config('exment.organization_deeps', 4));
        
        // create with
        $withs = str_repeat('children_organizations.', $deeps);

        $modelname = getModelName(SystemTableName::ORGANIZATION);
        $query = $modelname::query();
        $query->with(trim($withs, '.'));
        $query->whereNull($modelname::getParentOrgIndexName());
        return $query;
    }

    protected static function setFlattenOrganizations($orgs, &$org_flattens){
        foreach($orgs as $org){
            // not exists, append
            if(collect($org_flattens)->filter(function($org_flatten) use($org){
                return $org_flatten->id == $org->id;
            })->count() > 0){
                continue;
            }
            $org_flattens[] = $org;

            if($org->hasChildren()){
                static::setFlattenOrganizations($org->children_organizations, $org_flattens);
            }
        }
    }

    /**
     * get user or ornganization id who can access table.
     * 
     * @param CustomTable $target_table access table.
     * @param string $related_type "user" or "organization"
     */
    protected static function getAuthorityUserOrgId($target_table, $related_type){
        $target_table = CustomTable::getEloquent($target_table);
        
        // get user or organiztion ids
        $target_ids = \DB::table('authorities as a')
            ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
            ->where('related_type', $related_type)
            ->where(function ($query) use ($target_table) {
                $query->orWhere(function ($query) {
                    $query->where('morph_type', AuthorityType::SYSTEM);
                });
                $query->orWhere(function ($query) use ($target_table) {
                    $query->where('morph_type', AuthorityType::TABLE)
                    ->where('morph_id', $target_table->id);
                });
            })->get(['related_id'])->pluck('related_id') ?? [];
        return $target_ids;
    }
}
