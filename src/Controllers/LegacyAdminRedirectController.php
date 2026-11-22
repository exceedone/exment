<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\LegacyAdminRedirect;
use Illuminate\Http\Request;

/**
 * GET /admin/... on a site without the admin prefix: to the same page
 * without "/admin" (302, so a browser does not keep it if the site goes back
 * to a prefix).
 */
class LegacyAdminRedirectController
{
    /**
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function __invoke(Request $request)
    {
        $target = LegacyAdminRedirect::enabled() ? LegacyAdminRedirect::target($request->path(), $request->getQueryString()) : null;
        if (is_null($target)) {
            abort(404);
        }
        return redirect()->to(url($target), 302);
    }
}
