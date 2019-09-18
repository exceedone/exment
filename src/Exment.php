<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Facades\Auth;
use Encore\Admin\Admin;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        return (new Menu())->toTree();
    }

    public static function error($request, $exception, $callback)
    {
        if (isApiEndpoint()) {
            return $callback($request, $exception);
        }
        if (!$request->pjax() && $request->ajax()) {
            // if memory error, throw ajax response
            if (strpos($exception->getMessage(), 'Allowed memory size of') === 0) {
                $manualUrl = getManualUrl('quickstart_more');
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.memory_leak', ['url' => $manualUrl]) ]],
                ]);
            }

            return $callback($request, $exception);
        }
        
        try {
            // whether has User
            $user = \Exment::user();
            if (!isset($user)) {
                return $callback($request, $exception);
            }

            $errorController = app(\Exceedone\Exment\Controllers\ErrorController::class);
            return $errorController->error($request, $exception);
        } catch (\Exception $ex) {
            return $callback($request, $exception);
        } catch (Throwable $e) {
            return $callback($request, $exception);
        }
    }

    /**
     * get user. multi supported admin and adminapi
     */
    public function user($guards = null)
    {
        if (is_null($guards)) {
            $guards = ['adminapi', 'admin'];
        }
        if (is_string($guards)) {
            $guards = [$guards];
        }
        
        foreach ($guards as $guard) {
            # code...
            $user = Auth::guard($guard)->user();
            if (isset($user)) {
                return $user;
            }
        }
        return null;
    }

    /**
     * get exment version
     */
    public function version($getFromComposer = true)
    {
        list($latest, $current) = getExmentVersion($getFromComposer);
        return $current;
    }
}
