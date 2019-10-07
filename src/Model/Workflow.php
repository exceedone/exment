<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\WorkflowType;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public function workflow_tables()
    {
        return $this->hasMany(WorkflowTable::class, 'workflow_id');
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
    public function getStatusOptions($onlyStart = false)
    {
        //TODO:workflow performance
        $statuses = collect();
        if(!$onlyStart){
            $statuses = $this->workflow_statuses->pluck('status_name', 'id');
        }

        $statuses->prepend($this->start_status_name, Define::WORKFLOW_START_KEYNAME);

        return $statuses;
    }

    public static function getWorkflowByTable($custom_table){
        $custom_table = CustomTable::getEloquent($custom_table);

        $key = sprintf(Define::SYSTEM_KEY_SESSION_WORKFLOW_SELECT_TABLE, $custom_table->id);
        return System::requestSession($key, function() use($custom_table){
            $workflowTable = WorkflowTable::where('custom_table_id', $custom_table->id)
            ->first();

            if(!isset($workflowTable)){
                return null;
            }

            return $workflowTable->workflow->load(['workflow_statuses', 'workflow_actions']);
        });
    }

    /**
     * Get custom table. Only workflow type is table
     * If workflow is common, return null
     *
     * @param [type] $custom_table
     * @return void
     */
    public function getDesignatedTable()
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_WORKFLOW_DESIGNATED_TABLE, $this->id);
        return System::requestSession($key, function(){
            if($this->workflow_type == WorkflowType::COMMON){
                return null;
            }

            $workflowTables = $this->workflow_tables;
            if(is_nullorempty($workflowTables)){
                return null;
            }

            return $workflowTables->first()->custom_table;
        });
    }
}
