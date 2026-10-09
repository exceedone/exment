<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowValue;

class Administrator extends NotifyTargetBase
{
    // @phpstan-ignore-next-line
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        return $this->_getModel();
    }


    /**
     * Get notify target model for workflow
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    // @phpstan-ignore-next-line
    public function getModelsWorkflow(?CustomValue $custom_value, WorkflowAction $workflow_action, ?WorkflowValue $workflow_value, $statusTo): Collection
    {
        return $this->_getModel();
    }


    // @phpstan-ignore-next-line
    protected function _getModel()
    {
        $admins = System::system_admin_users();
        return collect($admins)->map(function ($admin) {
            return NotifyTarget::getModelAsUser(CustomTable::getEloquent(SystemTableName::USER)->getValueModel($admin));
        });
    }
}
