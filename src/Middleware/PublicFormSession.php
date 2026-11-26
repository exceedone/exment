<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\PublicForm;

class PublicFormSession extends \ExmentAdminCore\Admin\Middleware\Session
{
    /**
     * A public form may be in a frame of another site (embed.js, ISS02942): its session cookie
     * (only the path of the form) is SameSite=None there, else the browser does not send it and the
     * form cannot be sent. Only over https (None needs Secure); over http it stays as it is.
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($request->isSecure()) {
            config(['session.same_site' => 'none', 'session.secure' => true]);
        }
        return parent::handle($request, $next);
    }

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
