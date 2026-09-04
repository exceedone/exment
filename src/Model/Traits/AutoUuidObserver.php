<?php

namespace Exceedone\Exment\Model\Traits;

class AutoUuidObserver extends AutoUuidObserverBase
{

    // @phpstan-ignore-next-line
    protected function setUuid($model)
    {
        if (is_nullorempty($model->uuid)) {
            $model->uuid = make_uuid();
        }
    }
}
