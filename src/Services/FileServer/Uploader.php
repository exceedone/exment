<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileUploadSession;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * File server: the upload of a large file in parts (ISS00293, phase 1).
 *
 * The browser asks for an upload (init), sends the parts in order (chunk),
 * and asks to finish (complete). When the line breaks, it asks how many bytes
 * came (status) and goes on from there. The parts are put together in a
 * local folder, then the file goes to the disk of the site as one version.
 */
class Uploader
{
    // the size of a part the browser sends
    public const CHUNK_SIZE = 5 * 1024 * 1024;
    // a part may not be larger than this
    public const MAX_CHUNK_SIZE = 20 * 1024 * 1024;
    // an upload not finished in this many hours is removed
    public const EXPIRE_HOURS = 24;
    // an upload no part came to in this many minutes: no longer counted (the
    // uploads, the space), and removed when its user starts another one
    public const IDLE_MINUTES = 60;

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    public static function maxFileSize(): int
    {
        // 0: no limit but the space of the workspace (2 GB unless the site says)
        return max(0, intval(config('exment.file_server_max_file_size', 2147483648)));
    }

    /**
     * The uploads a user may have not finished at the same time (each takes
     * the space it says until it is finished or removed).
     */
    public static function maxOpenSessions(): int
    {
        return max(1, intval(config('exment.file_server_max_uploads', 20)));
    }

    /**
     * The bytes the uploads not finished of a user may say they will send,
     * all together (0: no limit but the space of the workspace).
     */
    public static function maxOpenBytes(): int
    {
        return max(0, intval(config('exment.file_server_max_upload_bytes', 10737418240)));
    }

    public function init(FileWorkspace $workspace, ?FileNode $parent, string $name, int $size, ?string $relativeDir = null, ?string $comment = null): FileUploadSession
    {
        $access = $this->library->access();
        if (isset($parent) && ($parent->workspace_id !== $workspace->id || !$parent->isFolder() || $parent->isTrashed())) {
            throw FileServerException::notFound();
        }
        $access->authorize($workspace, $parent, Level::UPLOAD);
        $name = $this->library->validName($name);
        if ($size < 0 || (static::maxFileSize() > 0 && $size > static::maxFileSize())) {
            throw new FileServerException(exmtrans('file_server.error_too_large'), 413);
        }
        // the uploads of the user nothing came to for a while: removed
        $idleSince = now()->subMinutes(static::IDLE_MINUTES);
        foreach (FileUploadSession::query()->where('user_id', $access->userId())->where('updated_at', '<', $idleSince)->get() as $idle) {
            static::remove($idle);
        }

        return DB::transaction(function () use ($workspace, $parent, $name, $size, $relativeDir, $comment, $access, $idleSince) {
            // one start at a time in a workspace: the counts and the space are checked together
            FileWorkspace::query()->where('id', $workspace->id)->lockForUpdate()->first();
            $mine = FileUploadSession::query()->where('user_id', $access->userId())->where('updated_at', '>=', $idleSince);
            if ((clone $mine)->count() >= static::maxOpenSessions()) {
                throw new FileServerException(exmtrans('file_server.error_upload_sessions_many'), 429);
            }
            if (static::maxOpenBytes() > 0 && intval((clone $mine)->sum('total_size')) + $size > static::maxOpenBytes()) {
                throw new FileServerException(exmtrans('file_server.error_upload_sessions_many'), 429);
            }
            // the uploads not finished take their space too (the ones going on)
            $pending = intval(FileUploadSession::query()->where('workspace_id', $workspace->id)->where('updated_at', '>=', $idleSince)->sum('total_size'));
            $this->library->assertQuota($workspace, $size + $pending);
            return $this->start($workspace, $parent, $name, $size, $relativeDir, $comment, $access->userId());
        });
    }

    protected function start(FileWorkspace $workspace, ?FileNode $parent, string $name, int $size, ?string $relativeDir, ?string $comment, int $userId): FileUploadSession
    {
        $session = new FileUploadSession([
            'id' => Str::uuid()->toString(),
            'workspace_id' => $workspace->id,
            'parent_id' => isset($parent) ? $parent->id : null,
            'user_id' => $userId,
            'name' => $name,
            'relative_path' => isset($relativeDir) && trim($relativeDir) !== '' ? mb_substr($relativeDir, 0, 1024) : null,
            'total_size' => $size,
            'received_size' => 0,
            'comment' => isset($comment) ? mb_substr($comment, 0, 1000) : null,
        ]);
        $session->save();
        @mkdir(static::dir($session->id), 0777, true);
        touch(static::dataPath($session->id));
        return $session;
    }

    /**
     * The upload of the login user (in the workspace).
     */
    public function find(FileWorkspace $workspace, string $id): FileUploadSession
    {
        $session = FileUploadSession::find($id);
        if (!isset($session) || $session->workspace_id !== $workspace->id || $session->user_id !== $this->library->access()->userId()) {
            throw FileServerException::notFound();
        }
        return $session;
    }

    /**
     * Add a part. The offset must be the bytes that came so far (so a part
     * sent twice is not added twice). Returns the bytes that came.
     */
    public function append(FileUploadSession $session, int $offset, string $chunkPath): int
    {
        $size = intval(filesize($chunkPath));
        if ($size > static::MAX_CHUNK_SIZE) {
            throw new FileServerException(exmtrans('file_server.error_too_large'), 413);
        }
        // one part at a time for a session: the row is locked, and read again under the lock
        return DB::transaction(function () use ($session, $offset, $size, $chunkPath) {
            $locked = FileUploadSession::query()->where('id', $session->id)->lockForUpdate()->first();
            if (!isset($locked)) {
                throw FileServerException::notFound();
            }
            if ($offset !== intval($locked->received_size)) {
                // the browser goes on from the bytes that came
                throw new FileServerException(exmtrans('file_server.error_upload_offset'), 409);
            }
            if (intval($locked->received_size) + $size > intval($locked->total_size)) {
                throw new FileServerException(exmtrans('file_server.error_upload'), 422);
            }
            $out = fopen(static::dataPath($locked->id), 'ab');
            $in = fopen($chunkPath, 'rb');
            if ($out === false || $in === false) {
                throw new FileServerException(exmtrans('file_server.error_upload'));
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $locked->received_size = intval($locked->received_size) + $size;
            $locked->save();
            $session->received_size = $locked->received_size;
            return intval($locked->received_size);
        });
    }

    public function complete(FileWorkspace $workspace, FileUploadSession $session): FileNode
    {
        if ($session->received_size !== $session->total_size) {
            throw new FileServerException(exmtrans('file_server.error_upload_incomplete'), 409);
        }
        $parent = isset($session->parent_id) ? FileNode::find($session->parent_id) : null;
        try {
            $node = $this->library->storeFile($workspace, $parent, $session->name, static::dataPath($session->id), $session->comment, $session->relative_path);
        } finally {
            static::remove($session);
        }
        return $node;
    }

    public function cancel(FileUploadSession $session): void
    {
        static::remove($session);
    }

    /**
     * The uploads not finished of a workspace that is removed.
     */
    public static function removeForWorkspace(int $workspaceId): void
    {
        foreach (FileUploadSession::query()->where('workspace_id', $workspaceId)->get() as $session) {
            static::remove($session);
        }
    }

    protected static function remove(FileUploadSession $session): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory(static::dir($session->id));
        $session->delete();
    }

    /**
     * Remove the uploads not finished in time. Returns the count.
     */
    public static function removeExpired(): int
    {
        $count = 0;
        foreach (FileUploadSession::query()->where('updated_at', '<', now()->subHours(static::EXPIRE_HOURS))->get() as $session) {
            static::remove($session);
            $count++;
        }
        return $count;
    }

    protected static function dir(string $id): string
    {
        return storage_path('app/file_server_chunks/' . preg_replace('/[^a-zA-Z0-9-]/', '', $id));
    }

    protected static function dataPath(string $id): string
    {
        return static::dir($id) . DIRECTORY_SEPARATOR . 'data';
    }
}
