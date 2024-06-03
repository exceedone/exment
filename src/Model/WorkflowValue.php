<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $workflow_id
 * @property mixed $workflow_status_to_id
 * @property mixed $workflow_action_id
 * @property mixed $workflow_action
 * @property mixed $workflow
 * @property mixed $workflow_value_authorities
 * @property mixed $workflow_status_from_id
 * @property mixed $latest_flg
 * @property mixed $action_executed_flg
 * @property mixed $morph_type
 * @property mixed $morph_id
 * @property mixed $comment
 * @property mixed $created_user_id
 * @method static \Illuminate\Database\Query\Builder orderBy($column, $direction = 'asc')
 * @phpstan-consistent-constructor
 */
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
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
        $status = $this->workflow_status_cache;

        return isset($status) ? ($status->editable_flg == 1) : true;
    }

    /**
     * Get Workflow Value Authorities.
     * Check from workflow value header, and check has workflow value authorities. If has, return
     *
     * @return mixed
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
     * this workflow is completed
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        $statusTo = $this->workflow_status_cache;
        return WorkflowStatus::getWorkflowStatusCompleted($statusTo);
    }

    /**
     * Whether already executed workflow
     *
     * @return bool
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


    /**
     * Get first executed workflow value.
     * *Filtered workflow_status_from_id is NULL or first status name.
     * *Sorted id desc. (First action... but last executed.)
     *
     * @return WorkflowValue
     */
    public static function getFirstExecutedWorkflowValue($custom_value)
    {
        // get first status name
        return static::where('morph_type', $custom_value->custom_table_name)
            ->where('morph_id', $custom_value->id)
            ->whereNull('workflow_status_from_id')
            ->where('action_executed_flg', 0) //Ignore multiple approve
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Get last executed workflow value.
     * *Filtered action_executed_flg
     * *Sorted id desc. (First action... but last executed.)
     *
     * @return WorkflowValue|null
     */
    public static function getLastExecutedWorkflowValue($custom_value)
    {
        return static::where('morph_type', $custom_value->custom_table_name)
            ->where('morph_id', $custom_value->id)
            ->where('action_executed_flg', 0)
            ->orderBy('id', 'desc')
            ->first();
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
