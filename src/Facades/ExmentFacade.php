<?php

namespace Exceedone\Exment\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Exment.
 *
 */
class ExmentFacade extends Facade
{

    protected static function getFacadeAccessor(): string
    {
        return (string)\Exceedone\Exment\Exment::class;
    }
}
