<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\WorkflowType;

class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

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
     * get workflow statuses using cache
     */
    public function getWorkflowStatusesCacheAttribute()
    {
        return $this->hasManyCache(WorkflowStatus::class, 'workflow_id');
    }

    /**
     * get workflow actions
     */
    public function getWorkflowActionsCacheAttribute()
    {
        return $this->hasManyCache(WorkflowAction::class, 'workflow_id');
    }

    /**
     * get eloquent using Cache.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentCache($id, $withs);
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
        $statuses = collect();
        if (!$onlyStart) {
            $statuses = $this->workflow_statuses_cache->pluck('status_name', 'id');
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
        $today = \Carbon\Carbon::today();

        $workflowTable = WorkflowTable::allRecordsCache(function ($record) use ($custom_table, $today) {
            if ($custom_table->id != $record->custom_table_id) {
                return false;
            }

            if (!boolval($record->active_flg)) {
                return false;
            }

            if (isset($record->active_start_date) && $today->lt(new \Carbon\Carbon($record->active_start_date))) {
                return false;
            }

            if (isset($record->active_end_date) && $today->gt(new \Carbon\Carbon($record->active_end_date))) {
                return false;
            }
            
            return true;
        }, false)->first();

        if (!isset($workflowTable)) {
            return null;
        }

        return Workflow::getEloquent($workflowTable->workflow_id);
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

    /**
     * append workflow status
     *
     * @return Workflow
     */
    public function appendStartStatus() : Workflow
    {
        $this->workflow_statuses->prepend(WorkflowStatus::getWorkflowStartStatus($this));

        return $this;
    }
}
