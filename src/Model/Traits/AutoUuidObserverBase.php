<?php

namespace Exceedone\Exment\Model\Traits;

/**
 *
 * @method void setUuid($model)
 */
class AutoUuidObserverBase
{

    // @phpstan-ignore-next-line
    public function creating($model)
    {
        $this->setUuid($model);
    }

    // @phpstan-ignore-next-line
    public function updating($model)
    {
        $this->setUuid($model);
    }
}
