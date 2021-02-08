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
        return 'フォームがありませんでした。URLが誤っているか、有効期限でない場合があります。'; //TODO
    }
}
