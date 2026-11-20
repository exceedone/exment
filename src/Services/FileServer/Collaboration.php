<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\FileServerNotifyJob;
use Exceedone\Exment\Model\FileComment;
use Exceedone\Exment\Model\FileFollow;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\NotifyNavbar;

/**
 * File server, phase 5 (ISS00379): follows, comments with @, and the news of
 * what changed.
 *
 * A user follows a workspace, a folder or a file. When a file is added or
 * updated in it, or a comment is written on it, the followers who may see
 * it are told: an alert of Exment (the bell) and, when the site sends mails
 * (exment.file_server_notify_mail), a mail. Those called with @ in a comment,
 * the maker of a file that was approved or rejected, and the managers of a
 * file that waits for its approval are told too.
 */
class Collaboration
{
    public const EVENT_ADDED = 'added';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_COMMENT = 'comment';
    public const EVENT_MENTION = 'mention';
    public const EVENT_APPROVAL_REQUEST = 'approval_request';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    // ---------------------------------------------------------------- follows

    public function isFollowing(FileWorkspace $workspace, ?FileNode $node): bool
    {
        $userId = $this->library->access()->userId();
        if (!isset($userId)) {
            return false;
        }
        return FileFollow::query()->where('workspace_id', $workspace->id)->where('user_id', $userId)
            ->where('node_id', isset($node) ? $node->id : null)->exists();
    }

    /**
     * Follow, or stop following. Returns whether the user follows now.
     */
    public function toggleFollow(FileWorkspace $workspace, ?FileNode $node): bool
    {
        $access = $this->library->access();
        $access->authorize($workspace, $node, Level::VIEW);
        $userId = $access->userId();
        if (!isset($userId)) {
            throw FileServerException::forbidden();
        }
        $query = FileFollow::query()->where('workspace_id', $workspace->id)->where('user_id', $userId)
            ->where('node_id', isset($node) ? $node->id : null);
        if ($query->exists()) {
            $query->delete();
            return false;
        }
        FileFollow::query()->create(['workspace_id' => $workspace->id, 'node_id' => isset($node) ? $node->id : null, 'user_id' => $userId]);
        return true;
    }

    // ---------------------------------------------------------------- comments

    /**
     * @return \Illuminate\Support\Collection<int, FileComment>
     */
    public function comments(FileWorkspace $workspace, FileNode $node)
    {
        $this->library->access()->authorize($workspace, $node, Level::VIEW);
        return FileComment::query()->where('node_id', $node->id)->orderBy('id')->get();
    }

    /**
     * Write a comment (those who may see the folder or file). The users called
     * with @ and their user code are told of it.
     */
    public function addComment(FileWorkspace $workspace, FileNode $node, string $body): FileComment
    {
        $access = $this->library->access();
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $access->authorize($workspace, $node, Level::VIEW);
        $body = trim($body);
        if ($body === '') {
            throw new FileServerException(exmtrans('file_server.error_comment_empty'));
        }
        $body = mb_substr($body, 0, 5000);
        $mentions = static::mentionedUserIds($body);
        $comment = new FileComment([
            'workspace_id' => $workspace->id,
            'node_id' => $node->id,
            'body' => $body,
            'mentions' => count($mentions) > 0 ? $mentions : null,
        ]);
        $comment->save();
        Audit::log(Audit::COMMENT, $workspace, $node, $access->userId());
        static::notify($node, static::EVENT_COMMENT, $access->userId(), ['comment' => mb_substr($body, 0, 200)]);
        if (count($mentions) > 0) {
            static::notify($node, static::EVENT_MENTION, $access->userId(), ['comment' => mb_substr($body, 0, 200)], $mentions);
        }
        return $comment;
    }

    public function deleteComment(FileWorkspace $workspace, FileNode $node, FileComment $comment): void
    {
        $access = $this->library->access();
        if ($comment->node_id !== $node->id || $node->workspace_id !== $workspace->id) {
            throw FileServerException::notFound();
        }
        $mine = isset($comment->created_user_id) && $comment->created_user_id === $access->userId();
        if (!$mine && !$access->can($workspace, $node, Level::MANAGE)) {
            throw FileServerException::forbidden();
        }
        $comment->delete();
    }

    /**
     * The users of the "@code" in a text (the user code of Exment).
     *
     * @return int[]
     */
    public static function mentionedUserIds(string $body): array
    {
        if (preg_match_all('/(?<![\w@])@([A-Za-z0-9_.\-]{1,64})/u', $body, $m) === 0) {
            return [];
        }
        $codes = array_values(array_unique($m[1]));
        $ids = [];
        foreach (array_slice($codes, 0, 20) as $code) {
            try {
                $user = getModelName(SystemTableName::USER)::query()->where('value->user_code', $code)->first();
            } catch (\Throwable $e) {
                $user = null;
            }
            if (isset($user)) {
                $ids[] = intval($user->id);
            }
        }
        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------- the news

    /**
     * Tell of a change (in the queue: the upload does not wait for the mails).
     *
     * @param array<string, mixed> $extra
     * @param int[]|null $targets the users to tell (null: the followers)
     */
    public static function notify(FileNode $node, string $event, ?int $actorId, array $extra = [], ?array $targets = null): void
    {
        if (!boolval(config('exment.file_server_notify', true))) {
            return;
        }
        try {
            FileServerNotifyJob::dispatch($node->id, $event, $actorId, $extra, $targets);
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not queue the news of node ' . $node->id . ': ' . $e->getMessage());
        }
    }

    /**
     * The users who follow the node, a folder above it, or its workspace.
     *
     * @return int[]
     */
    public static function followers(FileNode $node): array
    {
        $ids = array_map(function (FileNode $item) {
            return $item->id;
        }, $node->ancestorsAndSelf());
        return FileFollow::query()->where('workspace_id', $node->workspace_id)
            ->where(function ($query) use ($ids) {
                $query->whereNull('node_id')->orWhereIn('node_id', $ids);
            })->distinct()->pluck('user_id')->map('intval')->all();
    }

    /**
     * The users who manage the node (who approve its files): given the
     * "manage" level at the node whose permissions count, or at the top.
     *
     * @return int[]
     */
    public static function managers(FileWorkspace $workspace, FileNode $node): array
    {
        $source = $node;
        $guard = 0;
        while (isset($source) && !$source->has_unique_permissions && $guard++ < 1000) {
            $source = isset($source->parent_id) ? FileNode::find($source->parent_id) : null;
        }
        $query = FilePermission::query()->where('workspace_id', $workspace->id)->where('level', '>=', Level::MANAGE);
        isset($source) ? $query->where('node_id', $source->id) : $query->whereNull('node_id');
        $ids = [];
        foreach ($query->get() as $permission) {
            if ($permission->target_type === FilePermission::TARGET_USER) {
                $ids[] = intval($permission->target_id);
            } elseif ($permission->target_type === FilePermission::TARGET_ORGANIZATION) {
                try {
                    $org = getModelName(SystemTableName::ORGANIZATION)::find($permission->target_id);
                    if (isset($org)) {
                        foreach ($org->users()->pluck('id') as $id) {
                            $ids[] = intval($id);
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Send the news (the job).
     *
     * @param array<string, mixed> $extra
     * @param int[]|null $targets
     * @return int the users told
     */
    public static function send(int $nodeId, string $event, ?int $actorId, array $extra = [], ?array $targets = null): int
    {
        $node = FileNode::find($nodeId);
        $workspace = isset($node) ? $node->workspace : null;
        if (!isset($node) || !isset($workspace) || $node->isTrashed()) {
            return 0;
        }
        $users = $targets ?? static::followers($node);
        $users = array_values(array_unique(array_filter(array_map('intval', $users), function ($id) use ($actorId) {
            return $id > 0 && $id !== $actorId;
        })));
        if (count($users) === 0) {
            return 0;
        }
        $url = admin_urls('file_server', $workspace->id, $node->isFolder() ? 'folder' : 'nodes', $node->id);
        $actor = isset($actorId) ? getModelName(SystemTableName::USER)::find($actorId) : null;
        $prms = [
            'name' => $node->name,
            'workspace' => $workspace->name,
            'user' => isset($actor) ? strval($actor->getLabel()) : exmtrans('file_server.someone'),
        ];
        $subject = exmtrans('file_server.notify_subject_' . $event, $prms);
        $body = exmtrans('file_server.notify_body_' . $event, $prms);
        if (isset($extra['comment'])) {
            $body .= "\n\n" . strval($extra['comment']);
        }
        $body .= "\n\n" . $url;

        $count = 0;
        foreach ($users as $userId) {
            $user = getModelName(SystemTableName::USER)::find($userId);
            if (!isset($user)) {
                continue;
            }
            // only those who may see it now
            if (!(new Access($userId, false))->can($workspace, $node, Level::VIEW)) {
                continue;
            }
            try {
                $navbar = new NotifyNavbar();
                $navbar->fill([
                    'notify_id' => 0,
                    'target_user_id' => $userId,
                    'notify_subject' => mb_substr($subject, 0, 200),
                    'notify_body' => $body,
                    'trigger_user_id' => $actorId,
                ]);
                $navbar->save();
                $count++;
            } catch (\Throwable $e) {
                \Log::warning('[FileServer] could not make the alert: ' . $e->getMessage());
            }
            if (boolval(config('exment.file_server_notify_mail', true))) {
                try {
                    if (!is_null(\Exceedone\Exment\Services\NotifyService::getAddress($user))) {
                        \Exceedone\Exment\Services\NotifyService::notifyMail([
                            'to' => $user,
                            'subject' => $subject,
                            'body' => $body,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('[FileServer] could not send the mail to user ' . $userId . ': ' . $e->getMessage());
                }
            }
        }
        return $count;
    }
}
