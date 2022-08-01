<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;

class WorkUser extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        // work user not use getModels
        return collect();
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        $workflow = $workflow_action->workflow_cache;
        $users = collect();

        // if this workflow is completed
        if (!isset($workflow_value) || !$workflow_value->isCompleted()) {
            WorkflowStatus::getActionsByFrom($statusTo, $workflow, true)
                ->each(function ($workflow_action) use (&$users, $custom_value) {
                    $users = \Exment::uniqueCustomValues(
                        $users,
                        $workflow_action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NOTIFY)
                    );
                });
        }

        return $users->map(function ($user) {
            return NotifyTarget::getModelAsUser($user);
        });
    }
}
