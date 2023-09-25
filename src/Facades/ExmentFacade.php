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
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Exceedone\Exment\Exment::class;
    }
}
