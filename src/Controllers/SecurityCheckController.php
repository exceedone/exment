<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityCheck;
use Illuminate\Http\Request;

/**
 * The security check (ISS02833), next to Tuning: each item now / the good state /
 * how to fix it, with a button where Exment can fix it safely.
 * Only system administrators (Auth\Permission, "security_check").
 */
class SecurityCheckController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('security_check.header'), exmtrans('security_check.header'), exmtrans('security_check.description'), 'fa-shield');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->row(view('exment::security_check.index', [
            'check' => new SecurityCheck(),
            'serverItems' => SecurityCheck::serverItemsShown(),
            'fixUrl' => admin_urls('security_check', 'fix'),
        ]));
        return $content;
    }

    /**
     * Fix one item.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function fix(Request $request)
    {
        try {
            $done = (new SecurityCheck())->fix(strval($request->get('item')));
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::error($ex);
            $done = false;
        }
        if ($done) {
            admin_toastr(exmtrans('security_check.fix_done'));
        } else {
            admin_toastr(exmtrans('security_check.fix_failed'), 'error');
        }
        return redirect(admin_url('security_check'));
    }
}
