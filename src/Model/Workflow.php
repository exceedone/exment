<?php

namespace Exceedone\Exment\Model;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    
    /**
     * get workflow statuses
     */
    public function workflow_statuses()
    {
        return $this->hasMany(WorkflowStatus::class, 'workflow_id');
    }

    /**
     * get workflow actions
     */
    public function workflow_actions()
    {
        return $this->hasMany(WorkflowAction::class, 'workflow_id');
    }
    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
            
            $model->workflow_statuses()->delete();
            $model->workflow_actions()->delete();
        });
    }
    
    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        foreach ($this->workflow_statuses as $item) {
            $item->deletingChildren();
        }
        foreach ($this->workflow_actions as $item) {
            $item->deletingChildren();
        }
    }
}
