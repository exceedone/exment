<?php

namespace Exceedone\Exment\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Exment.
 *
 */
class ExmentFacade extends Facade
{
    /**
     * @return string|null
     */
    protected static function getFacadeAccessor()
    {
        return \Exceedone\Exment\Exment::class;
    }
}
