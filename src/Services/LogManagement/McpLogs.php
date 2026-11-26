<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Facades\Cache;

/**
 * The log of the MCP calls (ISS02915): each JSON-RPC request of a signed-in client of api/mcp
 * (McpController), with its tool, the user, how long it took, how big the answer was, and why it
 * was refused or failed. The arguments as the operation log keeps them (secrets masked; only the
 * keys while the input is not kept, LogPrivacy). The operation log keeps its row of a tool call
 * as before; this one is for reading the MCP calls alone.
 *
 * Off until it is turned on (Features: mcp_log). The page: the system administrators and the role
 * groups with "Audit" (McpLogController).
 */
class McpLogs
{
    public const FEATURE = 'mcp_log';

    public const DEFAULT_DAYS = 90;

    /** The most bytes of the arguments kept. */
    public const MAX_ARGS = 1000;

    /** The columns in the archive (LogCatalog). */
    public const COLUMNS = ['id', 'created_at', 'base_user_id', 'method', 'tool', 'status', 'reason', 'rpc_code', 'duration_ms', 'result_bytes', 'args', 'ip'];

    /** A refusal for too many calls is kept once a minute a user (not one row each refused call). */
    public const REFUSED_ONCE_SECONDS = 60;

    public static function active(): bool
    {
        return Features::enabled(static::FEATURE) && McpLog::tableExists();
    }

    /**
     * Keep a call.
     *
     * @param mixed $message the decoded JSON-RPC request (null when it was not JSON)
     * @param array<string, mixed>|null $answer the JSON-RPC answer (null when refused before it)
     */
    public static function record(?int $userId, $message, ?array $answer, float $startedAt, ?string $ip, ?string $refused = null, ?int $answerBytes = null): void
    {
        if (!static::active()) {
            return;
        }
        try {
            $method = is_array($message) && is_string($message['method'] ?? null) ? mb_substr($message['method'], 0, 50) : null;
            $params = is_array($message) && is_array($message['params'] ?? null) ? $message['params'] : [];
            $tool = $method === 'tools/call' && is_string($params['name'] ?? null) ? mb_substr($params['name'], 0, 100) : null;

            if (isset($refused)) {
                // a flood of refusals is one row a minute
                if ($refused === 'rate_limited' && !Cache::add('exment_mcp_log_refused:' . intval($userId), 1, static::REFUSED_ONCE_SECONDS)) {
                    return;
                }
                [$status, $reason, $code] = [McpLog::STATUS_REFUSED, $refused, static::code($answer)];
            } elseif (isset($answer['error'])) {
                [$status, $reason, $code] = [McpLog::STATUS_ERROR, strval($answer['error']['message'] ?? ''), static::code($answer)];
            } elseif (boolval($answer['result']['isError'] ?? false)) {
                $text = $answer['result']['content'][0]['text'] ?? '';
                [$status, $reason, $code] = [McpLog::STATUS_TOOL_ERROR, is_string($text) ? $text : '', null];
            } else {
                [$status, $reason, $code] = [McpLog::STATUS_OK, null, null];
            }

            $args = null;
            if ($method === 'tools/call' && is_array($params['arguments'] ?? null)) {
                $args = json_encode(\Exceedone\Exment\Middleware\LogOperation::maskInputArray($params['arguments'], 'mcp'), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
                if (isset($args) && strlen($args) > static::MAX_ARGS) {
                    $args = mb_strcut($args, 0, static::MAX_ARGS - 3) . '...';
                }
            }

            LogWriter::insert(McpLog::class, [
                'base_user_id' => $userId,
                'method' => $method,
                'tool' => $tool,
                'status' => $status,
                'reason' => is_nullorempty($reason) ? null : mb_substr(LogPrivacy::maskText(strval($reason)), 0, 255),
                'rpc_code' => $code,
                'duration_ms' => max(0, intval(round((microtime(true) - $startedAt) * 1000))),
                'result_bytes' => $answerBytes,
                'args' => $args,
                'ip' => LogPrivacy::ip($ip),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // as the operation log: never stopping the call
            \Log::warning('[Exment] the MCP log was not written: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed>|null $answer
     */
    protected static function code(?array $answer): ?int
    {
        $code = $answer['error']['code'] ?? null;
        return is_int($code) ? $code : null;
    }
}
