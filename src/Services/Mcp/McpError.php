<?php

namespace Exceedone\Exment\Services\Mcp;

/**
 * A JSON-RPC error of the MCP server (the request itself is wrong).
 */
class McpError extends \RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}
