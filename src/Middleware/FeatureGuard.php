<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Features;
use Illuminate\Http\Request;

/**
 * The pages and the API of a feature that is not used (404), or that the
 * user may not use (403), even when the URL is opened directly (Admin
 * setting → Feature management). See Exceedone\Exment\Services\Features.
 */
class FeatureGuard
{
    /**
     * @param \Closure(Request): mixed $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        // a page of a plugin: make its class first, so that the features it
        // registers (in its constructor) are known, also on the routes that
        // skip admin.bootstrap (as its API)
        if (str_contains('/' . trim($request->path(), '/') . '/', '/plugins/')) {
            try {
                Plugin::getPluginPageModel();
            } catch (\Throwable $e) {
                // the plugin answers for itself
            }
        }
        $feature = Features::forRequest($request);
        if ($feature === null || Features::allowed($feature)) {
            return $next($request);
        }

        $used = Features::enabled($feature);
        $status = $used ? 403 : 404;
        $message = strval(exmtrans($used ? 'feature.message_not_allowed' : 'feature.message_not_used'));
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['result' => false, 'message' => $message, 'toastr' => $message], $status);
        }

        // a system administrator may turn it on
        $user = \Exment::user();
        $link = !$used && isset($user) && $user->hasPermission(Permission::SYSTEM)
            ? '<p><a href="' . e(admin_url('feature')) . '">' . e(exmtrans('feature.header')) . '</a></p>' : '';
        $html = '<!doctype html><html lang="' . e(app()->getLocale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($message) . '</title><style>body{font-family:sans-serif;margin:48px auto;max-width:640px;padding:0 16px;color:#333}a{color:#3c8dbc}</style></head><body>'
            . '<h1 style="font-size:1.3rem">' . e($message) . '</h1>' . $link
            . '<p><a href="' . e(admin_url('')) . '">' . e(exmtrans('feature.back_home')) . '</a></p></body></html>';
        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
