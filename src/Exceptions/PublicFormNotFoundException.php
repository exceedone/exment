<?php

namespace Exceedone\Exment\Exceptions;

class PublicFormNotFoundException extends \Exception
{
    /**
     * PublicFormNotFoundException
     *
     * @param $request
     * @return array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Translation\Translator|mixed|string|null
     */
    public function render($request)
    {
        return exmtrans('error.public_form_not_found');
    }
}
