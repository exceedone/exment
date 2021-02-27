<?php

namespace Exceedone\Exment\Model\Traits;

class AutoUuidObserverBase
{
    public function creating($model)
    {
        $this->setUuid($model);
    }
    public function updating($model)
    {
        $this->setUuid($model);
    }
}
