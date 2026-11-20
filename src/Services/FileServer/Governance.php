<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;

/**
 * File server, phase 5 (ISS00379): the rules of a workspace.
 *
 * - legal hold: on a workspace, or on a folder or file (and what is in it):
 *   nothing can be put in the trash, removed for good, or lose old versions
 * - retention: a file cannot be removed for the days after it was made; with
 *   "delete", it goes to the trash by itself when the days are over (the
 *   daily purge)
 * - download level: who may download (all who may see, or those who may edit)
 * - approval: the files put into a folder that needs it wait as drafts (seen
 *   by their maker and the managers) until a manager approves them
 */
class Governance
{
    public const RETENTION_KEEP = 'keep';
    public const RETENTION_DELETE = 'delete';

    /**
     * Whether a folder or file is held: the workspace, the node, a folder
     * above it, or (for a folder) something in it.
     */
    public static function isHeld(FileWorkspace $workspace, FileNode $node): bool
    {
        if ($workspace->legal_hold) {
            return true;
        }
        foreach ($node->ancestorsAndSelf() as $item) {
            if ($item->legal_hold) {
                return true;
            }
        }
        if ($node->isFolder()) {
            $ids = $node->descendantIds(true);
            if (count($ids) > 0 && FileNode::query()->whereIn('id', $ids)->where('legal_hold', true)->exists()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The end of the days a file must be kept (null: no rule, or over).
     */
    public static function retainedUntil(FileWorkspace $workspace, FileNode $node): ?\Carbon\Carbon
    {
        $days = intval($workspace->retention_days);
        if ($days <= 0 || !isset($node->created_at)) {
            return null;
        }
        $until = $node->created_at->copy()->addDays($days);
        return $until->isFuture() ? $until : null;
    }

    /**
     * Stop when the folder or file (or something in it) is held or must still be kept.
     */
    public static function assertRemovable(FileWorkspace $workspace, FileNode $node): void
    {
        if (static::isHeld($workspace, $node)) {
            throw new FileServerException(exmtrans('file_server.error_legal_hold'), 423);
        }
        $days = intval($workspace->retention_days);
        if ($days <= 0) {
            return;
        }
        $limit = now()->subDays($days);
        if ($node->isFile()) {
            $until = static::retainedUntil($workspace, $node);
            if (isset($until)) {
                throw new FileServerException(exmtrans('file_server.error_retention', ['date' => $until->format('Y/m/d')]), 423);
            }
            return;
        }
        $ids = $node->descendantIds(true);
        if (count($ids) > 0 && FileNode::query()->whereIn('id', $ids)->where('node_type', FileNode::TYPE_FILE)->where('created_at', '>', $limit)->exists()) {
            throw new FileServerException(exmtrans('file_server.error_retention_inner', ['days' => $days]), 423);
        }
    }

    /**
     * Whether the user may download from the workspace (not only see the preview).
     */
    public static function canDownload(Access $access, FileWorkspace $workspace, ?FileNode $node): bool
    {
        $need = max(Level::VIEW, intval($workspace->download_level ?: Level::VIEW));
        return $access->can($workspace, $node, $need);
    }

    public static function assertDownload(Access $access, FileWorkspace $workspace, ?FileNode $node): void
    {
        if (!static::canDownload($access, $workspace, $node)) {
            throw new FileServerException(exmtrans('file_server.error_download_not_allowed'), 403);
        }
    }

    /**
     * Whether a new file in the folder needs an approval: the folder or one above it says so.
     */
    public static function needsApproval(?FileNode $folder): bool
    {
        if (!isset($folder)) {
            return false;
        }
        foreach ($folder->ancestorsAndSelf() as $item) {
            if ($item->requires_approval) {
                return true;
            }
        }
        return false;
    }

    /**
     * Put the files past the days of their workspaces into the trash (the
     * workspaces with "delete"). Not those that are held. Run every day.
     */
    public static function applyRetention(): int
    {
        $count = 0;
        $library = ShareLinks::systemLibrary();
        $workspaces = FileWorkspace::query()->where('retention_action', static::RETENTION_DELETE)
            ->where('legal_hold', false)->whereNotNull('retention_days')->where('retention_days', '>', 0)->get();
        foreach ($workspaces as $workspace) {
            $limit = now()->subDays(intval($workspace->retention_days));
            $nodes = FileNode::query()->where('workspace_id', $workspace->id)->where('node_type', FileNode::TYPE_FILE)
                ->whereNull('trashed_at')->where('created_at', '<', $limit)->orderBy('id')->limit(1000)->get();
            foreach ($nodes as $node) {
                try {
                    if (isset($node->checked_out_user_id) || static::isHeld($workspace, $node)) {
                        continue;
                    }
                    $library->trash($workspace, $node);
                    Audit::log(Audit::RETENTION_DELETE, $workspace, $node, null, ['days' => intval($workspace->retention_days)]);
                    $count++;
                } catch (\Throwable $e) {
                    \Log::warning('[FileServer] retention of node ' . $node->id . ': ' . $e->getMessage());
                }
            }
        }
        return $count;
    }
}
