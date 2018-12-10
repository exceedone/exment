<?php

namespace Exceedone\Exment\Model\Traits;

class AutoSUuidObserver
{
    public function creating($model)
    {
        $this->setSuuid($model);
    }
    public function updating($model)
    {
        $this->setSuuid($model);
    }

    protected function setSuuid($model){
        if (is_nullorempty($model->suuid)) {
            $model->suuid = short_uuid();
        }
    }
}
