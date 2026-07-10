<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Middleware\LogOperation as BaseLogOperation;
use Encore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
                'path'    => substr(static::hidePathParams($request->path()), 0, 255),
                'method'  => $request->method(),
                'ip'      => $request->getClientIp(),
                'input'   => json_encode(static::maskInputArray($request->input(), $request->path())),
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
     * Mask a request input array for logging: mask keys resolved for $path
     * (global + URI-scoped), recursively, and mask sensitive path segments
     * inside the "_previous_" url posted by laravel-admin forms.
     *
     * @param array<mixed> $data decoded request input
     * @param string $path request path (Request::path() style, without leading slash)
     * @return array<mixed>
     */
    public static function maskInputArray(array $data, string $path): array
    {
        $data = static::maskArrayRecursive($data, static::getHideColumnsByPath($path));

        // laravel-admin posts the previous page url as "_previous_"; it can contain
        // sensitive path segments (e.g. api_setting/{client_id}/edit). The log view
        // strips "_previous_" only on display - the stored/exported value keeps it.
        if (isset($data['_previous_']) && is_string($data['_previous_'])) {
            $data['_previous_'] = static::hideUrlPathParams($data['_previous_']);
        }

        return $data;
    }

    /**
     * Recursively replace every value whose key is in $columns with '***'.
     * A matched key is masked whole (scalar, object or array).
     *
     * @param array<mixed> $data
     * @param array<int, string> $columns
     * @return array<mixed>
     */
    public static function maskArrayRecursive(array $data, array $columns): array
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
     * Keys masked on every URI. Only key names that are sensitive wherever they
     * appear belong here; keys that are credentials only on specific screens/APIs
     * must go to getHideColumnsByUri(), otherwise unrelated business data with
     * the same column name (e.g. a "client_id" column on a user-defined table)
     * would be masked too.
     *
     * @return array<int, string>
     */
    public static function getHideColumns(): array
    {
        return [
            // "password" family is masked EVERYWHERE (global) and is intentionally
            // NOT un-masked on data/* screens: Exment user management is a system
            // table at "data/user" that posts a REAL login password there, so
            // un-masking password on data/* would leak it. A user-defined "password"
            // business column is therefore over-masked - the safe trade-off, since
            // over-masking is far better than leaking a real password.
            'password',
            'password_confirmation',
            'current_password',
            '_token',
            'verify_code',
            'access_token',
            'refresh_token',
            // Password reset token (posted as hidden field on auth/reset form).
            // The token also appears in the URL path (auth/reset/{token});
            // that segment is masked separately - see getHidePathPrefixes().
            'token',
            // SSO (OAuth / SAML) secrets
            'oauth_client_id',
            'oauth_client_secret',
            'saml_sp_privatekey',
            // System config secrets (admin/system): reCAPTCHA secret, SMTP password
            'recaptcha_secret_key',
            'system_mail_password',
            // Plugin DB connection password
            'custom_password',
            // Plugin CRUD page auth (key / id+password)
            'crud_auth_key',
            'crud_auth_password',
        ];
    }

    /**
     * URI-scoped mask rules: URI pattern (below the admin prefix, Str::is wildcard)
     * => keys masked only when the request path matches. Use this for key names
     * that are credentials on specific screens/APIs but plain business data
     * elsewhere.
     *
     * @return array<string, array<int, string>>
     */
    public static function getHideColumnsByUri(): array
    {
        return [
            // API client setting screens (admin/api_setting*). "id" IS the oauth
            // client_id and "secret" the client secret (posted by the edit form /
            // grid filter). "client_api_key" is the real api key, posted nested as
            // client_api_key[key] only by this form's save. None may be masked
            // globally - a business table can legitimately own id/secret columns.
            'api_setting*' => ['id', 'secret', 'client_api_key'],
            // OAuth token endpoints (admin/oauth/*): client_id / client_secret /
            // api_key are posted as credentials here (grant_type: api_key /
            // client_credentials / password). They are plain business data on
            // user-defined tables, so they are masked only on this URI.
            'oauth/*' => ['client_id', 'api_key', 'client_secret'],
        ];
    }

    /**
     * Get mask target keys for a request path: global keys + URI-scoped keys.
     *
     * @param string $path request path (Request::path() style, without leading slash)
     * @return array<int, string>
     */
    public static function getHideColumnsByPath(string $path): array
    {
        $columns = static::getHideColumns();
        foreach (static::getHideColumnsByUri() as $pattern => $keys) {
            if (Str::is(ltrim(admin_base_path($pattern), '/'), trim($path, '/'))) {
                $columns = array_merge($columns, (array)$keys);
            }
        }
        return $columns;
    }

    /**
     * URI prefixes (below the admin prefix) whose next path segment is sensitive,
     * e.g. api_setting/{client_id}. The segment is masked in the logged path.
     * Value = whether to keep the first characters of a long segment: true for
     * record ids (so the record can still be traced), false for bearer
     * credentials (a partial token has no traceability value - mask it whole).
     *
     * @return array<string, bool>
     */
    protected static function getHidePathPrefixes(): array
    {
        return [
            'api_setting' => true,
            // Passport client management API: oauth/clients/{client_id}
            'oauth/clients' => true,
            // Password reset url: auth/reset/{token}. Both GET (mail link) and
            // POST (reset form action) carry the raw token in the path, and the
            // route group includes admin.log, so the path must be masked here.
            'auth/reset' => false,
        ];
    }

    /**
     * Partially mask sensitive path segments (e.g. the client_id in
     * api_setting/{client_id}/edit) before logging.
     *
     * @param string $path request path (without leading slash)
     * @return string
     */
    public static function hidePathParams(string $path): string
    {
        foreach (static::getHidePathPrefixes() as $prefix => $keepIdPrefix) {
            $base = ltrim(admin_base_path($prefix), '/');
            $path = preg_replace_callback(
                '#^(' . preg_quote($base, '#') . '/)([^/]+)#',
                function ($m) use ($keepIdPrefix) {
                    // fixed route words are not record ids; already-masked segments stay as-is
                    if (in_array($m[2], ['create'], true) || Str::endsWith($m[2], '***')) {
                        return $m[0];
                    }
                    // keep a short prefix for traceability - but only for record ids
                    // long enough (uuid etc.) that the prefix does not give them away
                    if ($keepIdPrefix && strlen($m[2]) > 12) {
                        return $m[1] . substr($m[2], 0, 8) . '***';
                    }
                    return $m[1] . '***';
                },
                $path
            );
        }
        return $path;
    }

    /**
     * Apply hidePathParams() to the path part of a full url string.
     *
     * @param string $url
     * @return string
     */
    public static function hideUrlPathParams(string $url): string
    {
        $path = ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/');
        if ($path === '') {
            return $url;
        }

        $maskedPath = static::hidePathParams($path);
        if ($maskedPath === $path) {
            return $url;
        }
        return str_replace('/' . $path, '/' . $maskedPath, $url);
    }
}
