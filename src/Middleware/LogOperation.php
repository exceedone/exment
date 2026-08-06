<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Enums\SystemTableName;
use ExmentAdminCore\Admin\Middleware\LogOperation as BaseLogOperation;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
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
                'input'   => json_encode(static::maskInputArray((array)$request->input(), $request->path())),
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
     * Keys masked on every URI, from config "exment.operation_log.mask_columns".
     * Keys that are credentials only on specific screens/APIs belong to
     * "mask_columns_by_uri" instead, otherwise unrelated business data with the
     * same column name (e.g. a "client_id" column on a user-defined table) would
     * be masked too.
     *
     * requiredHideColumns() is unioned in and cannot be removed by config.
     *
     * @return array<int, string>
     */
    public static function getHideColumns(): array
    {
        return array_values(array_unique(array_merge(
            static::requiredHideColumns(),
            static::normalizeColumnList(config('exment.operation_log.mask_columns'))
        )));
    }

    /**
     * Keys masked whatever the config says: the credentials that authenticate a
     * user or a session, where logging one in plain text cannot be undone.
     * Only names that are unambiguous belong here - a generic name such as
     * "token" is scoped to the screen that posts it (config mask_columns_by_uri)
     * so a user-defined column of the same name is not masked everywhere.
     *
     * This is the safety net for an install whose config/exment.php is out of
     * date, hand-edited or emptied - that file is published once per install and
     * "exment:update" never refreshes it (see InstallUpdateTrait::
     * publishStaticFiles(), which force-publishes assets and lang but not
     * config), while mergeConfigFrom() merges top-level keys only, so a
     * published "operation_log" block wins as a whole.
     *
     * @return array<int, string>
     */
    protected static function requiredHideColumns(): array
    {
        return [
            'password',
            'password_confirmation',
            'current_password',
            '_token',
            'verify_code',
            'access_token',
            'refresh_token',
        ];
    }

    /**
     * URI-scoped mask rules: URI pattern (below the admin prefix, Str::is wildcard)
     * => keys masked only when the request path matches. Use this for key names
     * that are credentials on specific screens/APIs but plain business data
     * elsewhere.
     *
     * Driven entirely by config "exment.operation_log.mask_columns_by_uri":
     * none of these keys is a session credential, so freezing this list on an
     * install cannot leak a login. A key that must never be configurable away
     * belongs to requiredHideColumns() instead.
     *
     * @return array<string, array<int, string>>
     */
    public static function getHideColumnsByUri(): array
    {
        $config = config('exment.operation_log.mask_columns_by_uri');
        if (!is_array($config)) {
            return [];
        }

        $uriColumns = [];
        foreach ($config as $pattern => $columns) {
            $columns = static::normalizeColumnList($columns);
            if (!is_string($pattern) || $pattern === '' || empty($columns)) {
                continue;
            }
            $uriColumns[$pattern] = array_values(array_unique($columns));
        }

        return $uriColumns;
    }

    /**
     * Read a config value as a list of key names. Anything that is not a
     * non-empty string is dropped, so a malformed config cannot break logging.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    protected static function normalizeColumnList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $columns = [];
        foreach ($value as $column) {
            if (is_string($column) && $column !== '') {
                $columns[] = $column;
            }
        }
        return $columns;
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
     * Driven entirely by config "exment.operation_log.mask_path_prefixes" - there
     * is no hard-coded fallback, so an install that drops an entry really does
     * stop masking that url. "auth/reset" in particular carries a raw
     * password-reset token, so that entry must stay in the config block.
     *
     * @return array<string, bool>
     */
    protected static function getHidePathPrefixes(): array
    {
        $config = config('exment.operation_log.mask_path_prefixes');
        if (!is_array($config)) {
            return [];
        }

        $prefixes = [];
        foreach ($config as $prefix => $keepIdPrefix) {
            if (!is_string($prefix) || $prefix === '') {
                continue;
            }
            $prefixes[$prefix] = boolval($keepIdPrefix);
        }

        return $prefixes;
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

        // Match the path without its leading slash: a relative url
        // ("admin/api_setting/{id}") has no slash to anchor on, and an absolute
        // one contains the path either way. Every occurrence is replaced, so a
        // copy of the path inside the query string is masked too.
        return str_replace($path, $maskedPath, $url);
    }
}
