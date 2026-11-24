<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileAuditLog;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;

/**
 * File server, phase 5 (ISS00379): the audit log. Who saw, downloaded,
 * shared, changed or removed which folder or file, and when. The managers of
 * a workspace search it and take it out as CSV.
 *
 * A failure to write the log never stops what the user does (it is written
 * to the log of the site). exment.file_server_audit = false stops it;
 * exment.file_server_audit_days > 0 removes the older lines every day.
 */
class Audit
{
    public const VIEW = 'view';
    public const DOWNLOAD = 'download';
    public const ZIP = 'zip';
    public const UPLOAD = 'upload';
    public const VERSION = 'version';
    public const RENAME = 'rename';
    public const MOVE = 'move';
    public const COPY = 'copy';
    public const TRASH = 'trash';
    public const RESTORE = 'restore';
    public const PURGE = 'purge';
    public const PERMISSIONS = 'permissions';
    public const CHECKOUT = 'checkout';
    public const CHECKIN = 'checkin';
    public const SHARE_CREATE = 'share_create';
    public const SHARE_REVOKE = 'share_revoke';
    public const LINK_OPEN = 'link_open';
    public const LINK_DOWNLOAD = 'link_download';
    public const LINK_UPLOAD = 'link_upload';
    public const METADATA = 'metadata';
    public const COMMENT = 'comment';
    public const APPROVE = 'approve';
    public const REJECT = 'reject';
    public const HOLD = 'hold';
    public const SETTINGS = 'settings';
    public const RETENTION_DELETE = 'retention_delete';

    public const ACTIONS = [
        self::VIEW, self::DOWNLOAD, self::ZIP, self::UPLOAD, self::VERSION, self::RENAME, self::MOVE, self::COPY,
        self::TRASH, self::RESTORE, self::PURGE, self::PERMISSIONS, self::CHECKOUT, self::CHECKIN,
        self::SHARE_CREATE, self::SHARE_REVOKE, self::LINK_OPEN, self::LINK_DOWNLOAD, self::LINK_UPLOAD,
        self::METADATA, self::COMMENT, self::APPROVE, self::REJECT, self::HOLD, self::SETTINGS, self::RETENTION_DELETE,
    ];

    public static function enabled(): bool
    {
        return boolval(config('exment.file_server_audit', true));
    }

    /**
     * Write a line.
     *
     * @param array<string, mixed> $detail
     */
    public static function log(string $action, ?FileWorkspace $workspace, ?FileNode $node, ?int $userId, array $detail = [], ?int $linkId = null): void
    {
        if (!static::enabled()) {
            return;
        }
        try {
            $request = app()->runningInConsole() ? null : request();
            FileAuditLog::query()->insert([
                'workspace_id' => isset($workspace) ? $workspace->id : (isset($node) ? $node->workspace_id : null),
                'node_id' => isset($node) ? $node->id : null,
                'node_name' => isset($node) ? mb_substr($node->name, 0, 255) : null,
                'user_id' => $userId,
                'link_id' => $linkId,
                'action' => $action,
                'detail' => count($detail) > 0 ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'ip' => isset($request) ? mb_substr(strval($request->ip()), 0, 45) : null,
                'user_agent' => isset($request) ? mb_substr(strval($request->userAgent()), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[FileServer] could not write the audit log: ' . $e->getMessage());
        }
    }

    /**
     * The lines of a workspace, the newest first.
     *
     * @param array<string, mixed> $filters action, user_id, node (name), from (Y-m-d), to (Y-m-d)
     * @return \Illuminate\Database\Eloquent\Builder<FileAuditLog>
     */
    public static function query(FileWorkspace $workspace, array $filters = [])
    {
        $query = FileAuditLog::query()->where('workspace_id', $workspace->id);
        $action = strval(array_get($filters, 'action', ''));
        if ($action !== '' && in_array($action, static::ACTIONS, true)) {
            $query->where('action', $action);
        }
        $user = array_get($filters, 'user_id');
        if ($user === 'link') {
            $query->whereNotNull('link_id');
        } elseif ($user !== null && $user !== '' && is_numeric($user)) {
            $query->where('user_id', intval($user));
        }
        $name = trim(strval(array_get($filters, 'node', '')));
        if ($name !== '') {
            $query->where('node_name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%');
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $value = strval(array_get($filters, $key, ''));
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
                $date = \Carbon\Carbon::parse($value);
                $query->where('created_at', $op, $key === 'from' ? $date->startOfDay() : $date->endOfDay());
            }
        }
        return $query->orderByDesc('id');
    }

    public static function label(string $action): string
    {
        return exmtrans('file_server.audit_' . $action);
    }

    /**
     * Remove the lines older than exment.file_server_audit_days (0: keep all).
     */
    public static function removeOld(): int
    {
        $days = intval(config('exment.file_server_audit_days', 0));
        if ($days <= 0) {
            return 0;
        }
        return FileAuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
