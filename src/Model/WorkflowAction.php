<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

class WorkflowAction extends ModelBase
{
    // user workflow_authoritable. it's all role data. only filter workflow_action_id
    public function action_authoritable_users()
    {
        return $this->morphToMany(getModelName(SystemTableName::USER), 'workflow', 'workflow_authoritable', 'workflow_action_id', 'related_id')
            ->withPivot('related_id', 'related_type', 'role_id')
            ->wherePivot('related_type', SystemTableName::USER)
            ;
    }

    // user workflow_authoritable. it's all role data. only filter workflow_action_id
    public function action_authoritable_organizations()
    {
        return $this->morphToMany(getModelName(SystemTableName::ORGANIZATION), 'workflow', 'workflow_authoritable', 'workflow_action_id', 'related_id')
            ->withPivot('related_id', 'related_type', 'role_id')
            ->wherePivot('related_type', SystemTableName::ORGANIZATION)
            ;
    }
    
    /**
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable()
    {
        $count = $this
            ->action_authoritable_users()
            ->where('related_id', \Exment::user()->base_user_id)->count();

        if ($count == 0) {
            $count = $this
                ->action_authoritable_organizations()
                ->whereIn('related_id', \Exment::user()->getOrganizationIds())->count();
        }

        return $count > 0;
    }
}
