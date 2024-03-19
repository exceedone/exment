<?php

namespace Exceedone\Exment\Exceptions;

use Laravel\Passport\Exceptions\OAuthServerException;
use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param Throwable $exception
     * @return \Illuminate\Http\Response
     * @throws Throwable
     */
    public function render($request, Throwable $exception)
    {
        return \Exment::error($request, $exception, function ($request, $exception) {
            if ($exception instanceof OAuthServerException) {
                return response([
                    'message' => $exception->getMessage()], 401);
            }
            if ($request->expectsJson()) {
                if ($exception instanceof TokenMismatchException) {
                    return response()->json([
                        'message' => exmtrans('common.message.csrf_error')], 419);
                }
            }
            return parent::render($request, $exception);
        });
    }
}
