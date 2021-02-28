<?php
namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

class Administrator extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table) : Collection
    {
        $admins = System::system_admin_users();
        return collect($admins)->map(function($admin){
            return NotifyTarget::getModelAsUser(CustomTable::getEloquent(SystemTableName::USER)->getValueModel($admin));
        });
    }
}
