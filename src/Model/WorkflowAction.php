<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

class WorkflowAction extends ModelBase
{
    protected $appends = ['work_targets'];

    protected $autority_users;
    protected $autority_organizations;

    public function setHasAutorityUsersAttribute($value)
    {
        $this->autority_users = $value;
    }

    public function setHasAutorityOrganizationsAttribute($value)
    {
        $this->autority_organizations = $value;
    }

    public function getHasAutorityUsersAttribute()
    {
        return WorkflowAuthority::where('workflow_action_id', $this->id)
            ->where('related_type', SystemTableName::USER)->get()->pluck('related_id');
    }

    public function getHasAutorityOrganizationsAttribute()
    {
        return WorkflowAuthority::where('workflow_action_id', $this->id)
            ->where('related_type', SystemTableName::ORGANIZATION)->get()->pluck('related_id');
    }

    public function getWorkTargetsAttribute()
    {
        return WorkflowAuthority::where('workflow_action_id', $this->id)
            ->where('related_type', SystemTableName::ORGANIZATION)->get()->pluck('related_id');
    }

    protected static function boot() {
        parent::boot();

        static::saved(function ($model) {
            $model->setActionAuthority();
        });
    }

    public function deletingChildren()
    {
        WorkflowAuthority::where('workflow_action_id', $this->id)->delete();
    }

    /**
     * set action authority
     */
    public function setActionAuthority()
    {
        if (!is_null($this->autority_users)) {
            $users = [];
            foreach($this->autority_users as $autority_user) {
                $users[] = [
                    'related_id' => $autority_user,
                    'related_type' => SystemTableName::USER,
                    'workflow_action_id' => $this->id,
                ];
            }
            WorkflowAuthority::where('workflow_action_id', $this->id)
                ->where('related_type', SystemTableName::USER)->delete();
            WorkflowAuthority::insert($users);
        }

        if (!is_null($this->autority_organizations)) {
            $organizations = [];
            foreach($this->autority_organizations as $autority_organization) {
                $organizations[] = [
                    'related_id' => $autority_organization,
                    'related_type' => SystemTableName::ORGANIZATION,
                    'workflow_action_id' => $this->id,
                ];
            }
            WorkflowAuthority::where('workflow_action_id', $this->id)
                ->where('related_type', SystemTableName::ORGANIZATION)->delete();
            WorkflowAuthority::insert($organizations);
        }
    }
}
