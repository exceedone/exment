<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\Mcp\McpServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The MCP endpoint of Exment (api/mcp, ISS00439): the Streamable HTTP transport of MCP.
 * It is in the API group: the API IP filter, the login of the API (an OAuth token or an
 * API key of the user) and the feature "mcp" (Feature management) come before it.
 */
class McpController extends AdminControllerBase
{
    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request)
    {
        $user = \Exment::user();
        if (!isset($user)) {
            return response()->json(McpServer::error(null, -32001, 'Not signed in.'), 401);
        }

        // the version the client speaks, after initialize: one this server knows
        $version = $request->header('MCP-Protocol-Version');
        if (isset($version) && !in_array($version, McpServer::PROTOCOL_VERSIONS, true)) {
            return response()->json(McpServer::error(null, -32600, 'Unsupported MCP-Protocol-Version.'), 400);
        }

        // calls per minute of a user
        $perMinute = max(1, intval(config('exment.mcp_calls_per_minute', 60)));
        $limiterKey = static::limiterKey(intval($user->id));
        if (RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
            return response()->json(McpServer::error(null, -32000, 'Too many calls: try again later.'), 429)
                ->header('Retry-After', strval(RateLimiter::availableIn($limiterKey)));
        }
        RateLimiter::hit($limiterKey, 60);

        $message = json_decode((string)$request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(McpServer::error(null, -32700, 'Parse error.'), 400);
        }

        $answer = (new McpServer($user, $request->ip()))->handle($message);
        if (!isset($answer)) {
            // a notification
            return response('', 202);
        }
        return response()->json($answer, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The key of the calls of a user: of this site (the sites of a SaaS may share one cache, and
     * their login users have the same ids).
     */
    public static function limiterKey(int $loginUserId): string
    {
        $site = \Exceedone\Exment\Services\PluginDistribution\SiteId::get()
            ?? strval(config('database.connections.' . config('database.default') . '.database'));
        return 'exment-mcp:' . substr(hash('sha256', $site), 0, 16) . ':' . $loginUserId;
    }

    /**
     * No event stream from the server (GET), and no session to end (DELETE).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function notAllowed()
    {
        return response('', 405)->header('Allow', 'POST');
    }
}
