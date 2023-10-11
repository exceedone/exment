<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $workflow_id
 * @property mixed $status_name
 * @property mixed $status_type
 * @property mixed $order
 * @property mixed $ignore_work
 * @property mixed $datalock_flg
 * @property mixed $completed_flg
 * @property mixed $created_user_id
 * @property mixed $updated_user_id
 * @property mixed $created_at
 * @property mixed $updated_at
 * @phpstan-consistent-constructor
 */
class WorkflowStatus extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

    public function deletingChildren()
    {
    }

    protected static function boot()
    {
        parent::boot();

        // add default order
        static::addGlobalScope(new OrderScope('order'));
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
     * Get workflow start status as object
     *
     * @param Workflow $workflow
     * @return WorkflowStatus
     */
    public static function getWorkflowStartStatus(Workflow $workflow): WorkflowStatus
    {
        $workflow_status = new WorkflowStatus();
        $workflow_status->id = null;
        $workflow_status->workflow_id = strval($workflow->id);
        $workflow_status->status_type = "0";
        $workflow_status->order = "-1";
        $workflow_status->status_name = $workflow->start_status_name;
        $workflow_status->datalock_flg = "0";
        $workflow_status->completed_flg = "0";
        $workflow_status->created_at = $workflow->created_at;
        $workflow_status->updated_at = $workflow->updated_at;
        $workflow_status->created_user_id = $workflow->created_user_id;
        $workflow_status->updated_user_id = $workflow->updated_user_id;

        return $workflow_status;
    }

    /**
     * Get workflow status name
     *
     * @param string|null $workflow_status
     * @param Workflow $workflow
     * @return string|null
     */
    public static function getWorkflowStatusName($workflow_status = null, $workflow = null)
    {
        if (!is_nullorempty($workflow_status) && $workflow_status != Define::WORKFLOW_START_KEYNAME) {
            $rec = WorkflowStatus::getEloquent($workflow_status);
            return isset($rec) ? $rec->status_name : null;
        }

        // get workflow
        if (isset($workflow)) {
            return $workflow->start_status_name;
        }

        return null;
    }

    /**
     * Get workflow status is completed
     *
     * @param string|null $workflow_status
     * @return bool
     */
    public static function getWorkflowStatusCompleted($workflow_status = null): bool
    {
        if (!isset($workflow_status) || $workflow_status == Define::WORKFLOW_START_KEYNAME) {
            return false;
        }

        $model = static::getEloquentDefault($workflow_status);
        return isset($model) && boolval($model->completed_flg);
    }

    /**
     * Get workflow actions from status
     *
     * @param string $workflow_status
     * @param Workflow $workflow
     * @param bool $ignoreReject
     * @return \Illuminate\Support\Collection
     */
    public static function getActionsByFrom($workflow_status = null, $workflow = null, $ignoreReject = false)
    {
        if (!isset($workflow_status)) {
            $workflow_status = Define::WORKFLOW_START_KEYNAME;
        }

        $query = WorkflowAction::where('workflow_id', $workflow->id);
        WorkflowAction::appendStatusFromQuery($query, $workflow_status);
        return $query->get()
            ->filter(function ($action) use ($ignoreReject) {
                if (!$ignoreReject) {
                    return true;
                }

                return !boolval($action->ignore_work);
            });
    }
}
