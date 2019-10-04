<?php

namespace Exceedone\Exment\Model;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

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

    /**
     * Get status options. contains start and end.
     *
     * @return Collection
     */
    public function getStatusOptions(){
        $statuses = $this->workflow_statuses->pluck('status_name', 'id');

        $statuses->prepend($this->start_status_name, 'start');
        $statuses->put('end', $this->end_status_name);

        return $statuses;
    }
}
