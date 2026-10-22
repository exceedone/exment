<?php

namespace Exceedone\Exment\Tests\Feature\Support;

use Illuminate\Http\Request;

/**
 * Not a widget form, but with handle(): _handle_form_ must not instantiate it.
 * Records whether it was constructed.
 */
class HandleFormNotAForm
{
    /**
     * @var bool
     */
    public static $constructed = false;

    public function __construct()
    {
        static::$constructed = true;
    }

    /**
     * @param Request $request
     * @return string
     */
    public function handle(Request $request)
    {
        return 'handled';
    }
}
