<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Enums\SystemTableName;

class FixedUser extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        return $this->getFixedUser();
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->getFixedUser();
    }


    protected function getFixedUser(): Collection
    {
        $users = array_get($this->action_setting, 'target_users');

        if (is_array($users)) {
            $users = arrayToString($users);
        }

        /** @var Collection $collection */
        $collection =  collect(stringToArray($users))->map(function ($user) {
            $user = getModelName(SystemTableName::USER)::find($user);
            return NotifyTarget::getModelAsUser($user);
        });
        return $collection;
    }
}
