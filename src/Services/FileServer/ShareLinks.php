<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * File server, phase 3 (ISS00377): links for people outside.
 *
 * - share: a folder or file can be seen by anyone with the link (and, when it
 *   is allowed, downloaded). A folder is seen with all that is in it.
 * - request: anyone with the link can upload files into a folder (a "file
 *   request" of Box); they see nothing of what is in it.
 *
 * A link can have an end date, a password and a count of opens, and can be
 * taken back. Those who can edit the folder or file make "share" links;
 * those who can upload into the folder make "request" links. The site can
 * stop the links (exment.file_server_external_share = false) or make an end
 * date needed (exment.file_server_share_max_days).
 */
class ShareLinks
{
    public const TOKEN_LENGTH = 40;
    public const SESSION_KEY = 'exment_file_share_unlocked';

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    public static function enabled(): bool
    {
        return boolval(config('exment.file_server_external_share', true));
    }

    public static function maxDays(): int
    {
        return max(0, intval(config('exment.file_server_share_max_days', 0)));
    }

    public static function basePath(): string
    {
        $path = trim(strval(config('exment.file_server_share_path', 'share')), '/');
        return $path === '' ? 'share' : $path;
    }

    /**
     * Make a link.
     *
     * @param array<string, mixed> $options expires_at (Y-m-d or a date), password, allow_download, max_access, message
     */
    public function create(FileWorkspace $workspace, FileNode $node, string $kind, array $options = []): FileShareLink
    {
        if (!static::enabled()) {
            throw new FileServerException(exmtrans('file_server.error_share_disabled'), 403);
        }
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $access = $this->library->access();
        if ($kind === FileShareLink::KIND_REQUEST) {
            if (!$node->isFolder()) {
                throw new FileServerException(exmtrans('file_server.error_request_folder_only'));
            }
            $access->authorize($workspace, $node, Level::UPLOAD);
        } elseif ($kind === FileShareLink::KIND_SHARE) {
            $access->authorize($workspace, $node, Level::EDIT);
        } else {
            throw new FileServerException(exmtrans('file_server.error_upload'));
        }

        $expires = $this->expiresAt(array_get($options, 'expires_at'));
        $password = strval(array_get($options, 'password', ''));
        $max = array_get($options, 'max_access');
        $max = ($max === null || $max === '' || intval($max) <= 0) ? null : min(1000000, intval($max));
        $message = trim(strval(array_get($options, 'message', '')));

        $link = new FileShareLink([
            'token' => Str::random(static::TOKEN_LENGTH),
            'workspace_id' => $workspace->id,
            'node_id' => $node->id,
            'kind' => $kind,
            'password_hash' => $password !== '' ? Hash::make($password) : null,
            'expires_at' => $expires,
            'allow_download' => $kind === FileShareLink::KIND_SHARE ? boolval(array_get($options, 'allow_download', true)) : false,
            'max_access' => $max,
            'message' => $message !== '' ? mb_substr($message, 0, 1000) : null,
        ]);
        $link->save();
        return $link;
    }

    /**
     * The end of a link: the end of the day given. Needed (and not later than
     * the days of the site) when the site sets the days.
     */
    protected function expiresAt(mixed $value): ?\Carbon\Carbon
    {
        $max = static::maxDays();
        if ($value === null || $value === '') {
            if ($max > 0) {
                throw new FileServerException(exmtrans('file_server.error_share_expiry_needed', ['days' => $max]));
            }
            return null;
        }
        try {
            $date = \Carbon\Carbon::parse(strval($value))->endOfDay();
        } catch (\Throwable $e) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry'));
        }
        if ($date->isPast()) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry'));
        }
        if ($max > 0 && $date->gt(now()->addDays($max)->endOfDay())) {
            throw new FileServerException(exmtrans('file_server.error_share_expiry_needed', ['days' => $max]));
        }
        return $date;
    }

    /**
     * The links of a folder or file (those the user may see: they can edit it,
     * or upload into it for the request links).
     *
     * @return \Illuminate\Support\Collection<int, FileShareLink>
     */
    public function linksOf(FileWorkspace $workspace, FileNode $node)
    {
        $access = $this->library->access();
        $level = $access->level($workspace, $node);
        return FileShareLink::query()->where('node_id', $node->id)->orderByDesc('id')->get()
            ->filter(function (FileShareLink $link) use ($level) {
                return $link->kind === FileShareLink::KIND_REQUEST ? $level >= Level::UPLOAD : $level >= Level::EDIT;
            })->values();
    }

    /**
     * Take a link back: the user who made it, or one who manages the folder or file.
     */
    public function revoke(FileWorkspace $workspace, FileShareLink $link): void
    {
        if ($link->workspace_id !== $workspace->id) {
            throw FileServerException::notFound();
        }
        $access = $this->library->access();
        $node = FileNode::find($link->node_id);
        $mine = isset($link->created_user_id) && $link->created_user_id === $access->userId();
        if (!$mine && (!isset($node) || !$access->can($workspace, $node, Level::MANAGE))) {
            throw FileServerException::forbidden();
        }
        $link->revoked_at = now();
        $link->revoked_user_id = $access->userId();
        $link->save();
    }

    // ---------------------------------------------------------------- the side of the people outside

    /**
     * The link of a token, when it can be opened (null: not found, taken back,
     * past its end, used up, its folder or file removed, or the links stopped).
     */
    public static function resolve(string $token): ?FileShareLink
    {
        if (!static::enabled() || strlen($token) !== static::TOKEN_LENGTH || preg_match('/\A[A-Za-z0-9]+\z/', $token) !== 1) {
            return null;
        }
        $link = FileShareLink::query()->where('token', $token)->first();
        if (!isset($link) || !$link->isActive()) {
            return null;
        }
        $node = $link->node;
        $workspace = $link->workspace;
        if (!isset($node) || !isset($workspace) || $node->isTrashed()) {
            return null;
        }
        return $link;
    }

    public static function isUnlocked(FileShareLink $link): bool
    {
        if (!$link->hasPassword()) {
            return true;
        }
        return in_array($link->token, (array)session(static::SESSION_KEY, []), true);
    }

    public static function unlock(FileShareLink $link, string $password): bool
    {
        if (!$link->hasPassword()) {
            return true;
        }
        if (!Hash::check($password, strval($link->password_hash))) {
            return false;
        }
        $list = (array)session(static::SESSION_KEY, []);
        $list[] = $link->token;
        session([static::SESSION_KEY => array_values(array_unique($list))]);
        return true;
    }

    /**
     * Count an open of the link (once in a session).
     */
    public static function countAccess(FileShareLink $link): void
    {
        $key = static::SESSION_KEY . '_counted';
        $counted = (array)session($key, []);
        if (in_array($link->token, $counted, true)) {
            return;
        }
        $link->access_count = $link->access_count + 1;
        $link->last_accessed_at = now();
        $link->saving_users = false;
        $link->save();
        $counted[] = $link->token;
        session([$key => $counted]);
    }

    /**
     * A folder or file under the node of a "share" link (or the node itself):
     * what the people outside may see. Null when it is not under it.
     */
    public static function nodeInLink(FileShareLink $link, ?int $nodeId): ?FileNode
    {
        $root = $link->node;
        if (!isset($root)) {
            return null;
        }
        if ($nodeId === null || $nodeId === $root->id) {
            return $root;
        }
        if (!$root->isFolder()) {
            return null;
        }
        $node = FileNode::query()->where('workspace_id', $link->workspace_id)->whereNull('trashed_at')->find($nodeId);
        if (!isset($node)) {
            return null;
        }
        foreach ($node->ancestorsAndSelf() as $ancestor) {
            if ($ancestor->id === $root->id) {
                return $node;
            }
            if ($ancestor->isTrashed()) {
                return null;
            }
        }
        return null;
    }

    /**
     * The library that acts for the people outside: it may do all, so the
     * link decides what is allowed (see the controller of the links).
     */
    public static function systemLibrary(): Library
    {
        return new Library(new Access(null, true, []));
    }

    /**
     * Put a file from the people outside into the folder of a "request" link.
     * A file with the same name is not overwritten: the new one gets another name.
     */
    public static function receive(FileShareLink $link, string $name, string $localPath): FileNode
    {
        if ($link->kind !== FileShareLink::KIND_REQUEST) {
            throw FileServerException::forbidden();
        }
        $workspace = $link->workspace;
        $folder = $link->node;
        if (!isset($workspace) || !isset($folder) || !$folder->isFolder()) {
            throw FileServerException::notFound();
        }
        $library = static::systemLibrary();
        $max = Uploader::maxFileSize();
        if ($max > 0 && intval(filesize($localPath)) > $max) {
            throw new FileServerException(exmtrans('file_server.error_too_large'), 413);
        }
        $name = $library->freeName($workspace, $folder, $library->validName($name));
        return $library->storeFile($workspace, $folder, $name, $localPath, exmtrans('file_server.received_comment'));
    }
}
