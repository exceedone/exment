<?php

namespace Exceedone\Exment\Services\Mcp;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;

/**
 * What the pages say about the MCP server (ISS02879): the personal API keys (a key "for MCP",
 * the settings of Claude Code and Claude Desktop) and the API settings of the administrators
 * (on or off, the address, the tools and their scopes, who used it last). It only reads: the
 * MCP server itself is McpServer / McpTools.
 */
class McpGuide
{
    /** The scope each tool asks of the key or token (McpTools::call checks the same). */
    public const TOOL_SCOPES = [
        'list_tables' => ApiScope::TABLE_READ,
        'get_table' => ApiScope::TABLE_READ,
        'search_data' => ApiScope::VALUE_READ,
        'get_data' => ApiScope::VALUE_READ,
        'list_attachments' => ApiScope::VALUE_READ,
        'get_workflow_status' => ApiScope::WORKFLOW_READ,
        // the members (ISS00335): also the feature api_members and the permission of the role groups
        'list_organization_users' => ApiScope::USER_ORG_READ,
        'list_user_organizations' => ApiScope::USER_ORG_READ,
        'list_role_groups' => ApiScope::ROLE_GROUP_READ,
        'get_role_group' => ApiScope::ROLE_GROUP_READ,
    ];

    /**
     * The tools of the members: for the administrators of the role groups only, so a key "for
     * MCP" (scopes()) does not get their scopes; such a user adds them to a key.
     */
    public const MEMBER_TOOLS = ['list_organization_users', 'list_user_organizations', 'list_role_groups', 'get_role_group'];

    /** The name the settings give the server in Claude. */
    public const SERVER_NAME = 'exment';

    /**
     * The scopes of a key "for MCP": what every tool needs, and nothing more.
     *
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return array_values(array_unique(array_values(array_diff_key(static::TOOL_SCOPES, array_flip(static::MEMBER_TOOLS)))));
    }

    /** The address of the MCP server of this site. */
    public static function url(): string
    {
        return admin_urls('api', 'mcp');
    }

    /** Whether the MCP server is used on this site (Feature management, and the API). */
    public static function enabled(): bool
    {
        return Features::enabled(Features::MCP) && boolval(System::api_available());
    }

    /** Whether the login user may use it. */
    public static function allowed(): bool
    {
        return boolval(System::api_available()) && Features::allowed(Features::MCP);
    }

    /**
     * The settings to paste, with the key (only shown when the key is made) or a placeholder.
     *
     * @return array{claude_code: string, claude_desktop: string}
     */
    public static function configs(?string $key = null): array
    {
        $key = $key ?? 'exk_...';
        $url = static::url();
        $desktop = [
            'mcpServers' => [
                static::SERVER_NAME => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-remote', $url, '--header', 'Authorization:${EXMENT_AUTH}'],
                    'env' => ['EXMENT_AUTH' => 'Bearer ' . $key],
                ],
            ],
        ];
        return [
            'claude_code' => 'claude mcp add --transport http ' . static::SERVER_NAME . ' ' . $url . ' --header "Authorization: Bearer ' . $key . '"',
            'claude_desktop' => strval(json_encode($desktop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Whether a key with these scopes can run every tool.
     *
     * @param array<int, string> $scopes
     */
    public static function covers(array $scopes): bool
    {
        return count(array_diff(static::scopes(), $scopes)) === 0;
    }

    /**
     * The tools for the page: name, description, scope.
     *
     * @return array<int, array{name: string, description: string, scope: string}>
     */
    public static function tools(): array
    {
        $out = [];
        foreach (McpTools::definitions() as $tool) {
            $name = strval($tool['name']);
            $out[] = [
                'name' => $name,
                'description' => strval($tool['description']),
                'scope' => strval(static::TOOL_SCOPES[$name] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Who used the MCP server last (the operation log of its calls): the user, when, how many
     * calls in the last 30 days.
     *
     * @return array<int, array{user: string, last_at: string, calls: int}>
     */
    public static function recentUsers(int $limit = 10): array
    {
        try {
            $rows = OperationLog::query()
                ->where('method', 'MCP')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('user_id')
                ->selectRaw('user_id, max(created_at) as last_at, count(*) as calls')
                ->orderByDesc('last_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $login = LoginUser::query()->where('id', intval($row->user_id))->first();
            $user = $login ? $login->base_user : null;
            $out[] = [
                'user' => $user ? strval($user->getLabel()) : ('#' . $row->user_id),
                // in the time zone of the user, as the other lists (ISS02894)
                'last_at' => strval(\Exceedone\Exment\Services\UserTimezone::display($row->last_at) ?? ''),
                'calls' => intval($row->calls),
            ];
        }
        return $out;
    }
}
