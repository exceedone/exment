<?php

namespace Exceedone\Exment\Model\Traits;

/**
 *
 * @method void setUuid($model)
 */
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
