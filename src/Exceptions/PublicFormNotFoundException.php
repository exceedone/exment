<?php

namespace Exceedone\Exment\Exceptions;

class PublicFormNotFoundException extends \Exception
{
    /**
     * PublicFormNotFoundException
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function render($request)
    {
        return exmtrans('error.public_form_not_found');
    }
}
