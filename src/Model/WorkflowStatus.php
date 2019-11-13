<?php

namespace Exceedone\Exment\Model;

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
     * Get workflow status name
     *
     * @param [type] $workflow_status
     * @param [type] $workflow
     * @return void
     */
    public static function getWorkflowStatusName($workflow_status = null, $workflow = null)
    {
        if (!is_nullorempty($workflow_status) && $workflow_status != Define::WORKFLOW_START_KEYNAME) {
            return WorkflowStatus::getEloquent($workflow_status)->status_name;
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
     * @param [type] $workflow_status
     * @param [type] $workflow
     * @return void
     */
    public static function getWorkflowStatusCompleted($workflow_status = null)
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
     * @param [type] $workflow_status
     * @param [type] $workflow
     * @return void
     */
    public static function getActionsByFrom($workflow_status = null, $workflow = null, $ignoreReject = false)
    {
        if (!isset($workflow_status)) {
            $workflow_status = Define::WORKFLOW_START_KEYNAME;
        }

        return WorkflowAction::where('workflow_id', $workflow->id)
            ->where('status_from', $workflow_status)
            ->get()
            ->filter(function ($action) use ($ignoreReject) {
                if (!$ignoreReject) {
                    return true;
                }

                return !boolval($action->ignore_work);
            });
    }
}
