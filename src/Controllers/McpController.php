<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\ApiCost;
use Exceedone\Exment\Services\LogManagement\McpLogs;
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
        // an error of Exment anywhere here (as the name of the limit, from the settings): a
        // JSON-RPC error without its details (they go to the log)
        try {
            return $this->answer($request);
        } catch (\Throwable $e) {
            \Log::error('[Exment] MCP: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(McpServer::error(null, -32603, 'Internal error.'), 500);
        }
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function answer(Request $request)
    {
        $startedAt = microtime(true);
        $user = \Exment::user();
        if (!isset($user)) {
            // not kept: anyone could fill the log
            return response()->json(McpServer::error(null, -32001, 'Not signed in.'), 401);
        }
        $userId = isset($user->base_user_id) ? intval($user->base_user_id) : null;
        // the log of the MCP calls (ISS02915): a refusal, with its reason
        $refuse = function ($message, array $error, int $status, string $reason) use ($userId, $startedAt, $request) {
            McpLogs::record($userId, $message, $error, $startedAt, $request->ip(), $reason);
            return response()->json($error, $status);
        };

        // the version the client speaks, after initialize: one this server knows
        $version = $request->header('MCP-Protocol-Version');
        if (isset($version) && !in_array($version, McpServer::PROTOCOL_VERSIONS, true)) {
            return $refuse(null, McpServer::error(null, -32600, 'Unsupported MCP-Protocol-Version.'), 400, 'unsupported_version');
        }

        // calls per minute of a user; in points when exment.api_rate_cost is on (ISS02885 #5)
        $perMinute = max(1, intval(config('exment.mcp_calls_per_minute', 60)));
        $limiterKey = static::limiterKey(intval($user->id));
        $message = json_decode((string)$request->getContent(), true);
        $parsed = json_last_error() === JSON_ERROR_NONE;
        $mode = ApiCost::mode();
        $points = $mode === ApiCost::MODE_ON && $parsed ? ApiCost::ofMcpMessage($message) : 1;
        if (RateLimiter::attempts($limiterKey) + $points > $perMinute) {
            return $refuse($parsed ? $message : null, McpServer::error(null, -32000, 'Too many calls: try again later.'), 429, 'rate_limited')
                ->header('Retry-After', strval(RateLimiter::availableIn($limiterKey)));
        }
        RateLimiter::increment($limiterKey, 60, $points);
        if ($mode === ApiCost::MODE_LOG && $parsed) {
            ApiCost::logOverLimit($limiterKey, ApiCost::ofMcpMessage($message), $perMinute, 60, 'An MCP client (user ' . intval($user->id) . ')');
        }

        if (!$parsed) {
            return $refuse(null, McpServer::error(null, -32700, 'Parse error.'), 400, 'parse_error');
        }

        $answer = (new McpServer($user, $request->ip()))->handle($message);
        if (!isset($answer)) {
            // a notification
            return response('', 202);
        }
        $response = response()->json($answer, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        McpLogs::record($userId, $message, $answer, $startedAt, $request->ip(), null, strlen(strval($response->getContent())));
        return $response;
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
