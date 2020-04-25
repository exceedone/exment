<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Middleware\LogOperation as BaseLogOperation;
use Encore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Illuminate\Http\Request;

class LogOperation extends BaseLogOperation
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($this->shouldLogOperation($request)) {
            $user = \Exment::user();
            $log = [
                'user_id' => ($user ? $user->getUserId() : 0),
                'path'    => substr($request->path(), 0, 255),
                'method'  => $request->method(),
                'ip'      => $request->getClientIp(),
                'input'   => json_encode($request->input()),
            ];

            try {
                OperationLogModel::create($log);
            } catch (\Exception $exception) {
                // pass
            }
        }

        return $next($request);
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {
        return canConnection()
            && hasTable(SystemTableName::LOGIN_USER)
            && !$this->inExceptArray($request)
            && $this->inAllowedMethods($request->method());
    }
    
    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        if ($request->is(ltrim(admin_base_path('webapi/notifyPage'), '/'))) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}
