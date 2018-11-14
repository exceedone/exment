<?php

namespace Exceedone\Exment\Model\Traits;

class AutoSUuidObserver
{
    public function creating($model)
    {
        if (is_nullorempty($model->suuid)) {
            $model->suuid = short_uuid();
        }
    }
}
