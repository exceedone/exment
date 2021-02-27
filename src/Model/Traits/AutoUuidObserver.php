<?php

namespace Exceedone\Exment\Model\Traits;

class AutoUuidObserver extends AutoUuidObserverBase
{
    protected function setUuid($model)
    {
        if (is_nullorempty($model->uuid)) {
            $model->uuid = make_uuid();
        }
    }
}
