<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\WorkflowType;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\UseRequestSessionTrait;

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
        
    public function notifies()
    {
        return $this->hasMany(Notify::class, 'workflow_id');
    }

    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
    }
    
    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        $keys = ['workflow_statuses', 'workflow_tables', 'notifies'];
        $this->load($keys);
        foreach ($keys as $key) {
            foreach ($this->{$key} as $item) {
                if (!method_exists($item, 'deletingChildren')) {
                    continue;
                }
                $item->deletingChildren();
            }

            $this->{$key}()->delete();
        }
        
        foreach ($this->workflow_actions()->withTrashed()->get() as $item) {
            $item->deletingChildren();
        }

        $this->workflow_actions()->forceDelete();
    }

    /**
     * Get status string
     *
     * @return Collection
     */
    public function getStatusesString()
    {
        return $this->getStatusOptions()->implode(exmtrans('common.separate_word'));
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
        if (!$onlyStart) {
            $statuses = $this->workflow_statuses->pluck('status_name', 'id');
        }

        $statuses->prepend($this->start_status_name, Define::WORKFLOW_START_KEYNAME);

        return $statuses;
    }

    /**
     * Get workflow filtering active using custom table
     *
     * @param [type] $custom_table
     * @return void
     */
    public static function getWorkflowByTable($custom_table)
    {
        $custom_table = CustomTable::getEloquent($custom_table);

        $key = sprintf(Define::SYSTEM_KEY_SESSION_WORKFLOW_SELECT_TABLE, $custom_table->id);
        return System::requestSession($key, function () use ($custom_table) {
            $workflowTable = WorkflowTable::where('custom_table_id', $custom_table->id)
                ->active()
                ->first();

            if (!isset($workflowTable)) {
                return null;
            }

            return $workflowTable->workflow->load(['workflow_statuses', 'workflow_actions', 'workflow_actions.workflow_authorities']);
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
        return System::requestSession($key, function () {
            if ($this->workflow_type == WorkflowType::COMMON) {
                return null;
            }

            $workflowTables = $this->workflow_tables;
            if (is_nullorempty($workflowTables)) {
                return null;
            }

            return $workflowTables->first()->custom_table;
        });
    }

    /**
     * Check can change activate this wokflow
     *
     * @return boolean
     */
    public function canActivate()
    {
        if (boolval($this->setting_completed_flg)) {
            return false;
        }

        // check statuses
        if (count($this->workflow_statuses) == 0) {
            return false;
        }

        // check actions
        if (count($this->workflow_actions) == 0) {
            return false;
        }

        return true;
    }

    public static function hasSettingCompleted()
    {
        return static::allRecords(function ($workflow) {
            return boolval($workflow->setting_completed_flg);
        })->count() > 0;
    }
    
    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->setting_completed_flg);
    }
}
