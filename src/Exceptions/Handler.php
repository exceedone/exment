<?php

namespace Exceedone\Exment\Exceptions;

use Laravel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
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
        // an OAuth request the client got wrong (invalid_scope, invalid_client, ...): a warning, without the stack trace
        $oauthError = static::oauthClientError($exception);
        if ($oauthError !== null) {
            if ($this->shouldReport($exception)) {
                $this->newLogger()->warning('OAuthServerException: ' . $oauthError->getMessage(), array_filter([
                    'error' => $oauthError->getErrorType(),
                    'status' => $oauthError->getHttpStatusCode(),
                    'hint' => $oauthError->getHint(),
                ]));
            }
            return;
        }

        parent::report($exception);
    }

    /**
     * The OAuth error of the exception when the client caused it (an HTTP status 4xx), or null.
     *
     * @param Throwable $exception
     * @return LeagueOAuthServerException|null
     */
    public static function oauthClientError(Throwable $exception): ?LeagueOAuthServerException
    {
        // Passport wraps the exception of league/oauth2-server
        if ($exception instanceof OAuthServerException) {
            $exception = $exception->getPrevious();
        }
        if (!($exception instanceof LeagueOAuthServerException)) {
            return null;
        }
        $status = $exception->getHttpStatusCode();
        return ($status >= 400 && $status < 500) ? $exception : null;
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
        /** @phpstan-ignore-next-line error() expects Exception, Throwable given */
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
