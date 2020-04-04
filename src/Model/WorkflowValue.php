<?php

namespace Exceedone\Exment\Model;

class WorkflowValue extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * Get "Executed" workflow action
     *
     * @return void
     */
    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }

    public function workflow_status()
    {
        return $this->belongsTo(WorkflowStatus::class, 'workflow_status_to_id');
    }

    public function workflow_status_to()
    {
        return $this->workflow_status();
    }

    public function workflow_status_from()
    {
        return $this->belongsTo(WorkflowStatus::class, 'workflow_status_from_id');
    }

    public function workflow_value_authorities()
    {
        return $this->hasMany(WorkflowValueAuthority::class, 'workflow_value_id');
    }

    public function getWorkflowCacheAttribute()
    {
        return Workflow::getEloquent($this->workflow_id);
    }

    public function getWorkflowStatusCacheAttribute()
    {
        return WorkflowStatus::getEloquent($this->workflow_status_to_id);
    }

    public function getWorkflowActionCacheAttribute()
    {
        return WorkflowAction::getEloquent($this->workflow_action_id);
    }

    public function getWorkflowStatusNameAttribute()
    {
        return WorkflowStatus::getWorkflowStatusName($this->workflow_status_to_id, $this->workflow);
    }

    public function getWorkflowEditableAttribute()
    {
        $status = $this->getWorkflowStatusAttribute();

        return isset($status)? ($status->editable_flg == 1): true;
    }

    /**
     * Get Workflow Value Authorities.
     * Check from worklfow value header, and check has workflow value authorities. If has, return
     *
     * @return void
     */
    public function getWorkflowValueAutorities()
    {
        $authorities = $this->workflow_value_authorities;

        if (!isset($authorities) || count($authorities) == 0) {
            $workflow_values = WorkflowValue::where('morph_type', $this->morph_type)
                ->where('morph_id', $this->morph_id)
                ->where('workflow_id', $this->workflow_id)
                ->where('id', '<>', $this->id)
                ->orderBy('id', 'desc')
                ->get();
            
            foreach ($workflow_values as $workflow_value) {
                if ($workflow_value->workflow_status_to_id == $this->workflow_status_to_id) {
                    $authorities = $workflow_value->workflow_value_authorities;
                    if (!isset($authorities) || count($authorities) == 0) {
                        continue;
                    }
                }
                break;
            }
        }
        return $authorities;
    }
    
    /**
     * Whether already executed workflow
     *
     * @return void
     */
    public static function isAlreadyExecuted($action_id, $custom_value, $targetUser)
    {
        return static::where('morph_type', $custom_value->custom_table->table_name)
            ->where('morph_id', $custom_value->id)
            ->where('action_executed_flg', true)
            ->where('created_user_id', $targetUser->id)
            ->where('workflow_action_id', $action_id)
            ->count() > 0;
    }
    
    public function deletingChildren()
    {
        $this->workflow_value_authorities()->delete();
    }

    protected static function boot()
    {
        parent::boot();
        
        static::deleting(function ($model) {
            $model->deletingChildren();
        });
    }
}
