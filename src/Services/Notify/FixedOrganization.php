<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Enums\SystemTableName;

class FixedOrganization extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        return $this->getFixedOrganization();
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->getFixedOrganization();
    }


    protected function getFixedOrganization(): Collection
    {
        $orgs = array_get($this->action_setting, 'target_organizations');

        if (is_array($orgs)) {
            $orgs = arrayToString($orgs);
        }

        $values = collect([]);
        foreach (stringToArray($orgs) as $org) {
            $org = getModelName(SystemTableName::ORGANIZATION)::find($org);
            $values_inner = NotifyTarget::getModelsAsOrganization($org);
            foreach ($values_inner as $u) {
                $values->push($u);
            }
        }
        return $values;
    }
}
