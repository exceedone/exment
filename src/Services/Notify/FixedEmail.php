<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\NotifyTarget;

class FixedEmail extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        return $this->getFixedEmail();
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->getFixedEmail();
    }


    protected function getFixedEmail(): Collection
    {
        $emails = array_get($this->action_setting, 'target_emails');

        $emails = breakCommaToArray($emails);

        /** @var Collection $collection */
        $collection =  collect($emails)->filter(function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        })->map(function ($email) {
            return NotifyTarget::getModelAsEmail($email);
        });
        return $collection;
    }
}
