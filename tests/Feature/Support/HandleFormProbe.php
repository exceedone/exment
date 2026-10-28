<?php

namespace Exceedone\Exment\Tests\Feature\Support;

use ExmentAdminCore\Admin\Widgets\Form;
use Illuminate\Http\Request;

/**
 * A widget form with handle(), as "php artisan admin:form" makes one: it is posted to
 * _handle_form_. Records whether its handle() ran.
 */
class HandleFormProbe extends Form
{
    /**
     * @var bool
     */
    public static $handled = false;

    /**
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request)
    {
        static::$handled = true;

        return response('handled');
    }
}
