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
            $login_user = \Exment::user();

            // this "user_id" is login_user_id OK. because OperationLogModel relations to LoginUser modal.
            $log = [
                'user_id' => ($login_user ? $login_user->id : 0),
                'path'    => substr($request->path(), 0, 255),
                'method'  => $request->method(),
                'ip'      => $request->getClientIp(),
                // @phpstan-ignore-next-line
                'input'   => $this->hidePasswords(json_encode($request->input())),
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

    /**
     * Replace passwords with stars in operation log
     * @see https://github.com/z-song/laravel-admin/issues/625
     *
     * @param string $stringToLog
     * @return string
     */
    protected function hidePasswords($stringToLog)
    {
        $columns = static::getHideColumns();

        // Prefer structured masking: it also hides secrets nested inside objects/arrays
        // (e.g. "client_api_key":{"key":"..."}) and non-string values, which a flat regex
        // on the JSON string cannot reach.
        $decoded = json_decode($stringToLog, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode(static::maskArrayRecursive($decoded, $columns));
        }

        // Fallback for non-JSON strings: mask "key":"value" pairs directly.
        $pattern = implode("|", array_map(function ($c) {
            return preg_quote($c, '#');
        }, $columns));
        return preg_replace('#("(' . $pattern . ')"\s*:\s*")([^"]*)"#', '\1***"', $stringToLog);
    }

    /**
     * Recursively replace every value whose key is in $columns with '***'.
     * A matched key is masked whole (scalar, object or array).
     *
     * @param array<mixed> $data
     * @param array<int, string> $columns
     * @return array<mixed>
     */
    protected static function maskArrayRecursive(array $data, array $columns): array
    {
        foreach ($data as $key => &$value) {
            if (in_array($key, $columns, true)) {
                $value = '***';
            } elseif (is_array($value)) {
                $value = static::maskArrayRecursive($value, $columns);
            }
        }
        return $data;
    }

    /**
     * @return array<int, string>
     */
    public static function getHideColumns(): array
    {
        return [
            'password',
            'password_confirmation',
            'current_password',
            '_token',
            'verify_code',
            'access_token',
            'refresh_token',
            // Password reset token (posted as hidden field on auth/reset form).
            // Note: the token also appears in the URL path (auth/reset/{token});
            // hiding the input alone is not enough - the route should also be
            // added to config admin.operation_log.except.
            'token',
            // SSO (OAuth / SAML) secrets
            'oauth_client_id',
            'oauth_client_secret',
            'client_secret',
            'saml_sp_privatekey',
            // API token request credentials (grant_type: api_key / client_credentials / password)
            'client_id',
            'api_key',
            // API client secret
            'secret',
            // System config secrets (admin/system): reCAPTCHA secret, SMTP password
            'recaptcha_secret_key',
            'system_mail_password',
            // Plugin DB connection password
            'custom_password',
            // Plugin CRUD page auth (key / id+password)
            'crud_auth_key',
            'crud_auth_password',
            // API-key client edit form: real key posted as nested client_api_key[key]
            'client_api_key',
        ];
    }
}
