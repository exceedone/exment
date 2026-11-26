<?php

namespace Exceedone\Exment\Middleware;

use Closure;
use Exceedone\Exment\Enums\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Content-Type of the API checked strictly (ISS02915 #5): a POST, PUT or PATCH to api/* (and
 * api/mcp) is application/json, or multipart/form-data on the routes that take a file
 * (exment.api_strict_content_type_multipart). Anything else is refused with 415, so a body is never
 * read as another type than the client meant (a form posted from another site, for example).
 *
 * Off by default (EXMENT_API_STRICT_CONTENT_TYPE). A request without a body is let through.
 */
class ApiContentType
{
    public const METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!static::enabled() || static::allowed($request)) {
            return $next($request);
        }

        // an MCP client reads a JSON-RPC error
        if (static::isMcp($request)) {
            return response()->json(\Exceedone\Exment\Services\Mcp\McpServer::error(null, -32600, 'Unsupported Content-Type: application/json is expected.'), 415);
        }
        return abortJson(415, ErrorCode::UNSUPPORTED_CONTENT_TYPE());
    }

    public static function enabled(): bool
    {
        return boolval(config('exment.api_strict_content_type', false));
    }

    public static function allowed(Request $request): bool
    {
        if (!in_array(strtoupper($request->method()), static::METHODS, true)) {
            return true;
        }
        // nothing to read (a multipart or form body is parsed by PHP and has no raw content: its
        // fields and files count)
        if (strval($request->getContent()) === '' && count($request->request->all()) === 0 && count($request->allFiles()) === 0) {
            return true;
        }

        $type = strtolower(trim(explode(';', strval($request->header('Content-Type')))[0]));
        if ($type === 'application/json') {
            return true;
        }
        if ($type === 'multipart/form-data' && !static::isMcp($request)) {
            $path = static::apiPath($request);
            foreach ((array)config('exment.api_strict_content_type_multipart', ['document/*']) as $pattern) {
                if (is_string($pattern) && $pattern !== '' && Str::is($pattern, $path)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The path below the prefix of the API ("data/xxx", "mcp").
     */
    public static function apiPath(Request $request): string
    {
        $prefix = trim(url_join(config('admin.route.prefix'), 'api'), '/');
        $path = trim($request->path(), '/');
        return $prefix !== '' && str_starts_with($path, $prefix . '/') ? substr($path, strlen($prefix) + 1) : $path;
    }

    public static function isMcp(Request $request): bool
    {
        return static::apiPath($request) === 'mcp';
    }
}
