<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\NotifyTrigger;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow_type
 * @property mixed $workflow_tables
 * @property mixed $workflow_statuses
 * @property mixed $workflow_view_name
 * @property mixed $workflow_actions
 * @property mixed $start_status_name
 * @property mixed $setting_completed_flg
 * @property mixed $notifies
 * @property mixed $options
 * @property mixed $created_user_id
 * @property mixed $updated_user_id
 * @property mixed $created_at
 * @property mixed $updated_at
 */
class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $appends = ['workflow_edit_flg'];
    protected $casts = ['options' => 'json'];

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
        return $this->hasMany(Notify::class, 'target_id')
            ->where('notify_trigger', NotifyTrigger::WORKFLOW)
            ->where('active_flg', 1);
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

    public function getWorkflowEditFlgAttribute()
    {
        return $this->getOption('workflow_edit_flg');
    }
    public function setWorkflowEditFlgAttribute($workflow_edit_flg)
    {
        $this->setOption('workflow_edit_flg', $workflow_edit_flg);
        return $this;
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
     * @return string
     */
    public function getStatusesString()
    {
        return $this->getStatusOptions()->implode(exmtrans('common.separate_word'));
    }

    /**
     * Get status options. contains start and end.
     *
     * @return \Illuminate\Support\Collection|null
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
     * Get action options.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getActionOptions()
    {
        $actions = $this->workflow_actions_cache->pluck('action_name', 'id');

        return $actions;
    }

    /**
     * Get workflow filtering active using custom table
     *
     * @param CustomTable $custom_table
     * @return Workflow|null
     */
    public static function getWorkflowByTable($custom_table)
    {
        // if not has workflow, return false
        $hasWorkflow = System::cache(Define::SYSTEM_KEY_SESSION_HAS_WORLFLOW, function () {
            return WorkflowTable::count() > 0;
        });
        if (!$hasWorkflow) {
            return null;
        }

        $custom_table = CustomTable::getEloquent($custom_table);
        if (!$custom_table) {
            return null;
        }

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

            $workflow = Workflow::getEloquent($record->workflow_id);
            if (!$workflow || !boolval($workflow->setting_completed_flg)) {
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
     * @return CustomTable|null
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

        // check action use status is exists
        $status_list = $this->workflow_statuses->pluck('id');
        $status_list->push(Define::WORKFLOW_START_KEYNAME);

        foreach ($this->workflow_actions as $workflow_action) {
            if (!$status_list->contains($workflow_action->status_from)) {
                return false;
            }
            foreach ($workflow_action->workflow_condition_headers as $workflow_condition_header) {
                if (!$status_list->contains($workflow_condition_header->status_to)) {
                    return false;
                }
            }
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
     * Target Custom Table
     *
     * @return boolean|null
     */
    public function getTargetTableAttribute()
    {
        if ($this->workflow_type == WorkflowType::TABLE) {
            $workflow_tables = $this->workflow_tables;
            if (!is_nullorempty($workflow_tables)) {
                return $workflow_tables->first()->custom_table;
            }
        }
        return null;
    }

    /**
     * append workflow status
     *
     * @return Workflow
     */
    public function appendStartStatus(): Workflow
    {
        $this->workflow_statuses->prepend(WorkflowStatus::getWorkflowStartStatus($this));

        return $this;
    }
}
