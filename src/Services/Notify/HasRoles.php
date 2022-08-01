<?php

namespace Exceedone\Exment\Services\Notify;

use Illuminate\Support\Collection;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

class HasRoles extends NotifyTargetBase
{
    public function getModels(?CustomValue $custom_value, ?CustomTable $custom_table): Collection
    {
        return NotifyTarget::getModelsAsRole($custom_value, $custom_table);
    }
}
