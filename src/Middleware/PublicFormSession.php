<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\PublicForm;

class PublicFormSession extends \Encore\Admin\Middleware\Session
{
    /**
     * Get session path
     *
     * @return string|null
     */
    protected function getSessionPath(Request $request): ?string
    {
        // get baseUrl
        $baseUrl = trim(request()->getBaseUrl(), '/');
        $path = '';
        if (!empty($baseUrl)) {
            $path .= '/'.$baseUrl;
        } else {
            $path = '';
        }

        $public_form = PublicForm::getPublicFormByRequest();
        if ($public_form) {
            $path .= '/' . trim($public_form->getBasePath(), '/');
        } else {
            $path .= '/' . url_join(public_form_base_path(), make_uuid());
        }

        return $path;
    }
}
