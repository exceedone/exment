<?php
namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Model\NotifyTarget;

class CreatedUser extends NotifyTargetBase
{
    public function getModels(CustomValue $custom_value) : Collection
    {
        $result = collect();
        $item = NotifyTarget::getModelAsUser(CustomTable::getEloquent(SystemTableName::USER)->getValueModel($custom_value->created_user_id));
        if(isset($item)){
            $result->push($item);
        }
        return $result;
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public function getModelsWorkflow(CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo) : Collection
    {
        $created_user = $custom_value->created_user_value;
        return collect([$created_user]);
    }
}
