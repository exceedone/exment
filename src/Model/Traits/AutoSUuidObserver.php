<?php

namespace Exceedone\Exment\Model\Traits;

class AutoSUuidObserver extends AutoUuidObserverBase
{

    // @phpstan-ignore-next-line
    protected function setUuid($model)
    {
        if (is_nullorempty($model->suuid)) {
            $model->suuid = short_uuid();
        }
    }
}
