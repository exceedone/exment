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
    public static function isHeld(FileWorkspace $workspace, FileNode $node, bool $withWorkspace = true): bool
    {
        if ($withWorkspace && $workspace->legal_hold) {
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
     * Approve a file that waits (those who manage it). Its followers are told
     * of it now, and its maker.
     */
    public static function approve(Library $library, FileWorkspace $workspace, FileNode $node, ?string $comment = null): FileNode
    {
        return static::decide($library, $workspace, $node, FileNode::APPROVAL_APPROVED, $comment);
    }

    /**
     * Send a file back to its maker (those who manage it). The maker sees it
     * still, and may send a new version: it waits again.
     */
    public static function reject(Library $library, FileWorkspace $workspace, FileNode $node, ?string $comment = null): FileNode
    {
        return static::decide($library, $workspace, $node, FileNode::APPROVAL_REJECTED, $comment);
    }

    protected static function decide(Library $library, FileWorkspace $workspace, FileNode $node, string $status, ?string $comment): FileNode
    {
        $access = $library->access();
        if ($node->workspace_id !== $workspace->id || $node->isTrashed() || $node->approval_status !== FileNode::APPROVAL_DRAFT) {
            throw new FileServerException(exmtrans('file_server.error_not_waiting'));
        }
        $access->authorize($workspace, $node, Level::MANAGE);
        $comment = trim(strval($comment));
        $node->approval_status = $status;
        $node->approval_user_id = $access->userId();
        $node->approval_at = now();
        $node->approval_comment = $comment !== '' ? mb_substr($comment, 0, 1000) : null;
        $node->save();

        $approved = $status === FileNode::APPROVAL_APPROVED;
        Audit::log($approved ? Audit::APPROVE : Audit::REJECT, $workspace, $node, $access->userId(), $comment !== '' ? ['comment' => mb_substr($comment, 0, 200)] : []);
        $extra = $comment !== '' ? ['comment' => mb_substr($comment, 0, 200)] : [];
        if (isset($node->created_user_id)) {
            Collaboration::notify($node, $approved ? Collaboration::EVENT_APPROVED : Collaboration::EVENT_REJECTED, $access->userId(), $extra, [intval($node->created_user_id)]);
        }
        if ($approved) {
            Collaboration::notify($node, Collaboration::EVENT_ADDED, $node->created_user_id);
        }
        return $node;
    }

    /**
     * The files that wait for an approval the user may give.
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public static function waiting(Library $library, FileWorkspace $workspace)
    {
        $access = $library->access();
        return FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at')
            ->where('approval_status', FileNode::APPROVAL_DRAFT)->orderBy('created_at')->limit(500)->get()
            ->filter(function (FileNode $node) use ($access, $workspace) {
                return $access->can($workspace, $node, Level::MANAGE);
            })->values();
    }

    /**
     * The rules of a folder or file (those who manage it): the legal hold, and
     * (a folder) whether the files put in it need an approval.
     *
     * @param array<string, mixed> $input legal_hold, requires_approval
     */
    public static function setRules(Library $library, FileWorkspace $workspace, FileNode $node, array $input): FileNode
    {
        $access = $library->access();
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $access->authorize($workspace, $node, Level::MANAGE);
        if (array_key_exists('legal_hold', $input)) {
            $hold = boolval($input['legal_hold']);
            if ($hold !== $node->legal_hold) {
                $node->legal_hold = $hold;
                Audit::log(Audit::HOLD, $workspace, $node, $access->userId(), ['hold' => $hold]);
            }
        }
        if (array_key_exists('requires_approval', $input) && $node->isFolder()) {
            $node->requires_approval = boolval($input['requires_approval']);
        }
        $node->save();
        return $node;
    }

    /**
     * The rules of a workspace (those who manage it).
     *
     * @param array<string, mixed> $input retention_days, retention_action, legal_hold, watermark, download_level
     */
    public static function setWorkspaceRules(Library $library, FileWorkspace $workspace, array $input): FileWorkspace
    {
        $access = $library->access();
        $access->authorize($workspace, null, Level::MANAGE);
        $days = array_get($input, 'retention_days');
        $workspace->retention_days = ($days === null || $days === '' || intval($days) <= 0) ? null : min(36500, intval($days));
        $workspace->retention_action = array_get($input, 'retention_action') === static::RETENTION_DELETE ? static::RETENTION_DELETE : static::RETENTION_KEEP;
        $hold = boolval(array_get($input, 'legal_hold', false));
        if ($hold !== $workspace->legal_hold) {
            Audit::log(Audit::HOLD, $workspace, null, $access->userId(), ['hold' => $hold]);
        }
        $workspace->legal_hold = $hold;
        $workspace->watermark = boolval(array_get($input, 'watermark', false));
        $level = intval(array_get($input, 'download_level', Level::VIEW));
        $workspace->download_level = in_array($level, [Level::VIEW, Level::UPLOAD, Level::EDIT, Level::MANAGE], true) ? $level : Level::VIEW;
        $workspace->save();
        return $workspace;
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
