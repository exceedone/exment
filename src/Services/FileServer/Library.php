<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileDataLink;
use Exceedone\Exment\Model\FileFavorite;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeText;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File server: the operations on the workspaces, folders and files
 * (ISS00293, phases 1 and 2). Every operation checks the permissions of the
 * user first (Access) and throws FileServerException when it is not allowed.
 *
 * The bytes are kept on the "admin" disk of the site (local, S3, Azure...),
 * one object for each version: file_server/{workspace}/{node}/{version}_{random}.
 */
class Library
{
    public const MAX_NAME_LENGTH = 255;
    public const STORAGE_DIR = 'file_server';

    protected Access $access;

    /** Acting for a link of people outside (ISS00410): see forLink(). */
    protected bool $forLink = false;

    public function __construct(?Access $access = null)
    {
        $this->access = $access ?? Access::current();
    }

    /**
     * Act for a share or request link: the folders and files with their own
     * permissions are left out of a zip, and the previews of the files it
     * takes in wait until someone inside opens them (ISS00410).
     */
    public function forLink(): self
    {
        $this->forLink = true;
        return $this;
    }

    public static function zipMaxBytes(): int
    {
        return max(0, intval(config('exment.file_server_zip_max_bytes', 2147483648)));
    }

    public function access(): Access
    {
        return $this->access;
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public static function disk()
    {
        return Storage::disk(Define::DISKNAME_ADMIN);
    }

    // ---------------------------------------------------------------- workspaces

    /**
     * Make a workspace. The user who makes it manages it.
     *
     * @param array<string, mixed> $attributes name, description, quota_bytes, trash_days, version_limit
     */
    public function createWorkspace(array $attributes): FileWorkspace
    {
        if (!$this->access->canCreateWorkspace()) {
            throw FileServerException::forbidden();
        }
        $attributes = $this->workspaceAttributes($attributes);

        return DB::transaction(function () use ($attributes) {
            $workspace = new FileWorkspace($attributes);
            $workspace->save();
            FilePermission::create([
                'workspace_id' => $workspace->id,
                'node_id' => null,
                'target_type' => FilePermission::TARGET_USER,
                'target_id' => $this->access->userId(),
                'level' => Level::MANAGE,
            ]);
            $this->access->flush();
            return $workspace;
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateWorkspace(FileWorkspace $workspace, array $attributes): FileWorkspace
    {
        $this->access->authorize($workspace, null, Level::MANAGE);
        $workspace->fill($this->workspaceAttributes($attributes));
        $workspace->save();
        return $workspace;
    }

    public function deleteWorkspace(FileWorkspace $workspace): void
    {
        $this->access->authorize($workspace, null, Level::MANAGE);
        $workspace->delete();
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function workspaceAttributes(array $attributes): array
    {
        $name = trim(strval(array_get($attributes, 'name', '')));
        if ($name === '' || mb_strlen($name) > 256) {
            throw new FileServerException(exmtrans('file_server.error_workspace_name'));
        }
        $quota = array_get($attributes, 'quota_bytes');
        $quota = ($quota === null || $quota === '') ? null : max(0, intval($quota));
        return [
            'name' => $name,
            'description' => strval(array_get($attributes, 'description', '')) ?: null,
            'quota_bytes' => $quota,
            'trash_days' => max(0, min(3650, intval(array_get($attributes, 'trash_days', 30)))),
            'version_limit' => max(1, min(1000, intval(array_get($attributes, 'version_limit', 50)))),
        ];
    }

    /**
     * Set the members of the workspace, or the own permissions of a folder or file.
     * At least one entry must manage, so that nobody is locked out.
     *
     * @param array<int, array{target_type: string, target_id: int|string, level: int|string}> $entries
     */
    public function setPermissions(FileWorkspace $workspace, ?FileNode $node, array $entries): void
    {
        $this->access->authorize($workspace, $node, Level::MANAGE);
        $rows = $this->normalizePermissions($entries);
        if (!isset($node) && !collect($rows)->contains('level', Level::MANAGE)) {
            throw new FileServerException(exmtrans('file_server.error_need_manager'));
        }

        DB::transaction(function () use ($workspace, $node, $rows) {
            $query = FilePermission::query()->where('workspace_id', $workspace->id);
            isset($node) ? $query->where('node_id', $node->id) : $query->whereNull('node_id');
            $query->delete();
            foreach ($rows as $row) {
                FilePermission::create($row + ['workspace_id' => $workspace->id, 'node_id' => isset($node) ? $node->id : null]);
            }
            if (isset($node) && !$node->has_unique_permissions) {
                $node->has_unique_permissions = true;
                $node->save();
            }
        });
        $this->access->flush();
    }

    /**
     * Give a folder or file its own permissions, starting from those it takes now
     * (as "stop inheriting permissions" of SharePoint).
     */
    public function breakInheritance(FileWorkspace $workspace, FileNode $node): void
    {
        $this->access->authorize($workspace, $node, Level::MANAGE);
        if ($node->has_unique_permissions) {
            return;
        }
        $entries = $this->effectivePermissions($workspace, $node)->map(function (FilePermission $p) {
            return ['target_type' => $p->target_type, 'target_id' => $p->target_id, 'level' => $p->level];
        })->all();

        DB::transaction(function () use ($workspace, $node, $entries) {
            foreach ($this->normalizePermissions($entries) as $row) {
                FilePermission::create($row + ['workspace_id' => $workspace->id, 'node_id' => $node->id]);
            }
            $node->has_unique_permissions = true;
            $node->save();
        });
        // the user who did it keeps managing it
        if (!$this->access->can($workspace, $node, Level::MANAGE) && $this->access->userId() !== null) {
            FilePermission::create([
                'workspace_id' => $workspace->id, 'node_id' => $node->id,
                'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->access->userId(), 'level' => Level::MANAGE,
            ]);
        }
        $this->access->flush();
    }

    /**
     * Take the permissions of the parent again (as "delete unique permissions").
     */
    public function restoreInheritance(FileWorkspace $workspace, FileNode $node): void
    {
        $this->access->authorize($workspace, $node, Level::MANAGE);
        DB::transaction(function () use ($workspace, $node) {
            FilePermission::query()->where('workspace_id', $workspace->id)->where('node_id', $node->id)->delete();
            $node->has_unique_permissions = false;
            $node->save();
        });
        $this->access->flush();
    }

    /**
     * The permissions that count for a folder or file (its own or those it takes).
     *
     * @return \Illuminate\Support\Collection<int, FilePermission>
     */
    public function effectivePermissions(FileWorkspace $workspace, ?FileNode $node)
    {
        $source = $node;
        while (isset($source) && !$source->has_unique_permissions) {
            $source = isset($source->parent_id) ? FileNode::find($source->parent_id) : null;
        }
        $query = FilePermission::query()->where('workspace_id', $workspace->id);
        isset($source) ? $query->where('node_id', $source->id) : $query->whereNull('node_id');
        return $query->orderByDesc('level')->get();
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array{target_type: string, target_id: int, level: int}>
     */
    protected function normalizePermissions(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $type = strval(array_get($entry, 'target_type'));
            $id = intval(array_get($entry, 'target_id'));
            $level = intval(array_get($entry, 'level'));
            if (!in_array($type, [FilePermission::TARGET_USER, FilePermission::TARGET_ORGANIZATION], true) || $id <= 0 || !Level::isValid($level)) {
                continue;
            }
            $key = $type . ':' . $id;
            // the same user or organization twice: the higher level
            if (!isset($rows[$key]) || $rows[$key]['level'] < $level) {
                $rows[$key] = ['target_type' => $type, 'target_id' => $id, 'level' => $level];
            }
        }
        return array_values($rows);
    }

    // ---------------------------------------------------------------- reading

    /**
     * The folders and files in a folder (or at the top) that the user may see.
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public function children(FileWorkspace $workspace, ?FileNode $folder, string $sort = 'name', string $direction = 'asc')
    {
        $this->access->authorize($workspace, $folder, Level::VIEW);
        $query = FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at');
        isset($folder) ? $query->where('parent_id', $folder->id) : $query->whereNull('parent_id');

        $direction = $direction === 'desc' ? 'desc' : 'asc';
        // folders first, as the explorer of Windows
        $query->orderByRaw("CASE WHEN node_type = 'folder' THEN 0 ELSE 1 END");
        switch ($sort) {
            case 'updated_at':
            case 'size':
                $query->orderBy($sort, $direction);
                break;
            default:
                $query->orderBy('name', $direction);
        }

        return $query->get()->filter(function (FileNode $node) use ($workspace) {
            return !$node->has_unique_permissions || $this->access->can($workspace, $node, Level::VIEW);
        })->values();
    }

    /**
     * A node of the workspace the user may see (in the trash only when asked).
     */
    public function findNode(FileWorkspace $workspace, int $nodeId, bool $trashed = false, int $level = Level::VIEW): FileNode
    {
        $node = FileNode::query()->where('workspace_id', $workspace->id)->find($nodeId);
        if (!isset($node) || ($node->isTrashed() && !$trashed) || (!$node->isTrashed() && $trashed)) {
            throw FileServerException::notFound();
        }
        $this->access->authorize($workspace, $node, $level);
        return $node;
    }

    /**
     * The files changed lately in the workspaces of the user.
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public function recentFiles(int $limit = 10)
    {
        $workspaces = $this->access->workspaces()->keyBy('id');
        if ($workspaces->isEmpty()) {
            return collect();
        }
        $nodes = FileNode::query()->whereIn('workspace_id', $workspaces->keys()->all())
            ->where('node_type', FileNode::TYPE_FILE)->whereNull('trashed_at')
            ->orderByDesc('updated_at')->limit($limit * 5)->get();
        return $nodes->filter(function (FileNode $node) use ($workspaces) {
            return $this->access->can($workspaces[$node->workspace_id], $node, Level::VIEW);
        })->take($limit)->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public function favorites()
    {
        $userId = $this->access->userId();
        if (!isset($userId)) {
            return collect();
        }
        $ids = FileFavorite::query()->where('user_id', $userId)->pluck('node_id')->all();
        return FileNode::query()->whereIn('id', $ids)->whereNull('trashed_at')->orderBy('name')->get()
            ->filter(function (FileNode $node) {
                return isset($node->workspace) && $this->access->can($node->workspace, $node, Level::VIEW);
            })->values();
    }

    public function isFavorite(FileNode $node): bool
    {
        $userId = $this->access->userId();
        return isset($userId) && FileFavorite::query()->where('user_id', $userId)->where('node_id', $node->id)->exists();
    }

    /**
     * Mark or unmark a favorite. Returns whether it is a favorite now.
     */
    public function toggleFavorite(FileWorkspace $workspace, FileNode $node): bool
    {
        $this->access->authorize($workspace, $node, Level::VIEW);
        $userId = $this->access->userId();
        $query = FileFavorite::query()->where('user_id', $userId)->where('node_id', $node->id);
        if ($query->exists()) {
            $query->delete();
            return false;
        }
        FileFavorite::create(['user_id' => $userId, 'node_id' => $node->id]);
        return true;
    }

    // ---------------------------------------------------------------- folders and files

    public function createFolder(FileWorkspace $workspace, ?FileNode $parent, string $name): FileNode
    {
        $this->assertFolder($workspace, $parent);
        $this->access->authorize($workspace, $parent, Level::UPLOAD);
        $name = $this->validName($name);
        if ($this->sibling($workspace, $parent, $name) !== null) {
            throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $name]));
        }
        $folder = new FileNode([
            'workspace_id' => $workspace->id,
            'parent_id' => isset($parent) ? $parent->id : null,
            'node_type' => FileNode::TYPE_FOLDER,
            'name' => $name,
        ]);
        $folder->save();
        return $folder;
    }

    /**
     * The folders of a path ("a/b/c") under a folder: those that are there, and
     * new ones for the others (an upload of a folder of the computer).
     */
    public function ensurePath(FileWorkspace $workspace, ?FileNode $parent, string $path): ?FileNode
    {
        $parts = array_values(array_filter(preg_split('#[\\\\/]+#', $path) ?: [], function ($part) {
            return $part !== '' && $part !== '.';
        }));
        foreach ($parts as $part) {
            $part = $this->validName($part);
            $existing = $this->sibling($workspace, $parent, $part);
            if (isset($existing)) {
                if (!$existing->isFolder()) {
                    throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $part]));
                }
                $this->access->authorize($workspace, $existing, Level::VIEW);
                $parent = $existing;
                continue;
            }
            $parent = $this->createFolder($workspace, $parent, $part);
        }
        return $parent;
    }

    /**
     * Put a file into a folder from a local path. When a file with the same
     * name is there, the file becomes its new version (the user needs to be
     * able to edit it); otherwise a new file.
     */
    public function storeFile(FileWorkspace $workspace, ?FileNode $parent, string $name, string $localPath, ?string $comment = null, ?string $relativeDir = null): FileNode
    {
        if (isset($relativeDir) && trim($relativeDir) !== '') {
            $parent = $this->ensurePath($workspace, $parent, $relativeDir);
        }
        $this->assertFolder($workspace, $parent);
        $this->access->authorize($workspace, $parent, Level::UPLOAD);
        $name = $this->validName($name);

        $existing = $this->sibling($workspace, $parent, $name);
        if (isset($existing)) {
            if ($existing->isFolder()) {
                throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $name]));
            }
            if (!$this->access->can($workspace, $existing, Level::EDIT)) {
                throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $name]));
            }
            return $this->addVersion($workspace, $existing, $localPath, $comment);
        }

        $size = intval(filesize($localPath));
        $this->assertQuota($workspace, $size);

        return DB::transaction(function () use ($workspace, $parent, $name, $localPath, $comment) {
            $node = new FileNode([
                'workspace_id' => $workspace->id,
                'parent_id' => isset($parent) ? $parent->id : null,
                'node_type' => FileNode::TYPE_FILE,
                'name' => $name,
            ]);
            $node->save();
            Metadata::applyDefaults($workspace, $node);
            $this->writeVersion($workspace, $node, $localPath, $comment);
            return $node->refresh();
        });
    }

    /**
     * Put a new file into a folder (never a new version of one that is there:
     * a file with the same name stops it). For the request links.
     */
    public function storeNewFile(FileWorkspace $workspace, FileNode $parent, string $name, string $localPath, ?string $comment = null): FileNode
    {
        $this->assertFolder($workspace, $parent);
        $this->access->authorize($workspace, $parent, Level::UPLOAD);
        $name = $this->validName($name);
        $this->assertQuota($workspace, intval(filesize($localPath)));
        return DB::transaction(function () use ($workspace, $parent, $name, $localPath, $comment) {
            if ($this->sibling($workspace, $parent, $name) !== null) {
                throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $name]));
            }
            $node = new FileNode([
                'workspace_id' => $workspace->id,
                'parent_id' => $parent->id,
                'node_type' => FileNode::TYPE_FILE,
                'name' => $name,
            ]);
            $node->save();
            Metadata::applyDefaults($workspace, $node);
            $this->writeVersion($workspace, $node, $localPath, $comment);
            return $node->refresh();
        });
    }

    /**
     * Add a new version to a file (an upload over it, a check in, a restore).
     */
    public function addVersion(FileWorkspace $workspace, FileNode $node, string $localPath, ?string $comment = null): FileNode
    {
        $this->assertFile($node);
        $this->access->authorize($workspace, $node, Level::EDIT);
        $this->assertNotCheckedOutByOther($node);
        $this->assertQuota($workspace, intval(filesize($localPath)));

        DB::transaction(function () use ($workspace, $node, $localPath, $comment) {
            $this->writeVersion($workspace, $node, $localPath, $comment);
        });
        $this->trimVersions($workspace, $node);
        return $node->refresh();
    }

    /**
     * Keep the bytes of a local file as the newest version of the node.
     */
    protected function writeVersion(FileWorkspace $workspace, FileNode $node, string $localPath, ?string $comment): FileNodeVersion
    {
        $no = intval(FileNodeVersion::query()->where('node_id', $node->id)->max('version_no')) + 1;
        $dir = static::STORAGE_DIR . '/' . $workspace->id . '/' . $node->suuid;
        $objectName = $no . '_' . Str::random(16);

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new FileServerException(exmtrans('file_server.error_upload'));
        }
        try {
            $ok = static::disk()->writeStream($dir . '/' . $objectName, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($ok === false) {
            throw new FileServerException(exmtrans('file_server.error_upload'));
        }

        $size = intval(filesize($localPath));
        $mime = Preview::guessMimeType($localPath, $node->name);
        $version = new FileNodeVersion([
            'node_id' => $node->id,
            'version_no' => $no,
            'path' => $dir . '/' . $objectName,
            'size' => $size,
            'mime_type' => $mime,
            'hash' => hash_file('sha256', $localPath) ?: null,
            'comment' => isset($comment) && trim($comment) !== '' ? mb_substr(trim($comment), 0, 1000) : null,
        ]);
        $version->save();

        $node->current_version_id = $version->id;
        $node->size = $size;
        $node->mime_type = $mime;
        $node->updated_at = now();
        $node->save();

        if ($this->forLink) {
            // a file from people outside: no conversion and no text until someone inside opens it (ISS00410)
            $version->rendition_status = FileNodeVersion::RENDITION_HELD;
            $version->save();
        } else {
            Preview::queueRenditions($version);
            FileSearch::queueText($version);
        }
        return $version;
    }

    /**
     * Remove the oldest versions past the limit of the workspace (never the current one).
     */
    protected function trimVersions(FileWorkspace $workspace, FileNode $node): void
    {
        $limit = max(1, intval($workspace->version_limit));
        $old = FileNodeVersion::query()->where('node_id', $node->id)->orderByDesc('version_no')->skip($limit)->take(PHP_INT_MAX)->get();
        foreach ($old as $version) {
            if ($version->id === $node->current_version_id) {
                continue;
            }
            $this->deleteVersionObjects($version);
            $version->delete();
        }
    }

    /**
     * Make an older version the newest one again (its bytes become a new version,
     * so that no version is lost, as in SharePoint).
     */
    public function restoreVersion(FileWorkspace $workspace, FileNode $node, FileNodeVersion $version): FileNode
    {
        $this->assertFile($node);
        if ($version->node_id !== $node->id) {
            throw FileServerException::notFound();
        }
        $local = $this->downloadToLocal($version);
        try {
            return $this->addVersion($workspace, $node, $local, exmtrans('file_server.restored_from', ['no' => $version->version_no]));
        } finally {
            @unlink($local);
        }
    }

    public function rename(FileWorkspace $workspace, FileNode $node, string $name): FileNode
    {
        $this->access->authorize($workspace, $node, Level::EDIT);
        $this->assertNotCheckedOutByOther($node);
        $name = $this->validName($name);
        $parent = isset($node->parent_id) ? FileNode::find($node->parent_id) : null;
        $other = $this->sibling($workspace, $parent, $name);
        if (isset($other) && $other->id !== $node->id) {
            throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $name]));
        }
        $node->name = $name;
        $node->save();
        FileSearch::sync([$node->id]);
        return $node;
    }

    /**
     * Move into another folder of the same workspace (null: the top).
     */
    public function move(FileWorkspace $workspace, FileNode $node, ?FileNode $target): FileNode
    {
        $this->access->authorize($workspace, $node, Level::EDIT);
        $this->assertNotCheckedOutByOther($node);
        $this->assertFolder($workspace, $target);
        $this->access->authorize($workspace, $target, Level::UPLOAD);
        if (isset($target) && ($target->id === $node->id || in_array($target->id, $node->descendantIds(), true))) {
            throw new FileServerException(exmtrans('file_server.error_move_into_itself'));
        }
        $other = $this->sibling($workspace, $target, $node->name);
        if (isset($other) && $other->id !== $node->id) {
            throw new FileServerException(exmtrans('file_server.error_name_exists', ['name' => $node->name]));
        }
        $node->parent_id = isset($target) ? $target->id : null;
        $node->save();
        $this->access->flush();
        return $node;
    }

    /**
     * Copy into a folder of the same workspace (null: the top). A file is copied
     * with its current version only; a folder with all that is in it. The copy
     * takes the permissions of the folder it is put into.
     */
    public function copy(FileWorkspace $workspace, FileNode $node, ?FileNode $target): FileNode
    {
        $this->access->authorize($workspace, $node, Level::VIEW);
        $this->assertFolder($workspace, $target);
        $this->access->authorize($workspace, $target, Level::UPLOAD);
        if ($node->isFolder() && isset($target) && ($target->id === $node->id || in_array($target->id, $node->descendantIds(), true))) {
            throw new FileServerException(exmtrans('file_server.error_move_into_itself'));
        }
        $this->assertQuota($workspace, $this->treeSize($node));
        $name = $this->freeName($workspace, $target, $node->name);
        return $this->copyTree($workspace, $node, $target, $name);
    }

    protected function copyTree(FileWorkspace $workspace, FileNode $node, ?FileNode $target, string $name): FileNode
    {
        if ($node->isFile()) {
            $version = $node->current_version;
            if (!isset($version)) {
                throw FileServerException::notFound();
            }
            $local = $this->downloadToLocal($version);
            try {
                $copy = new FileNode([
                    'workspace_id' => $workspace->id,
                    'parent_id' => isset($target) ? $target->id : null,
                    'node_type' => FileNode::TYPE_FILE,
                    'name' => $name,
                ]);
                $copy->metadata = $node->metadata;
                $copy->save();
                $this->writeVersion($workspace, $copy, $local, null);
                return $copy->refresh();
            } finally {
                @unlink($local);
            }
        }

        $copy = new FileNode([
            'workspace_id' => $workspace->id,
            'parent_id' => isset($target) ? $target->id : null,
            'node_type' => FileNode::TYPE_FOLDER,
            'name' => $name,
        ]);
        $copy->metadata = $node->metadata;
        $copy->save();
        foreach (FileNode::query()->where('parent_id', $node->id)->whereNull('trashed_at')->get() as $child) {
            if (!$this->access->can($workspace, $child, Level::VIEW)) {
                continue;
            }
            $this->copyTree($workspace, $child, $copy, $child->name);
        }
        return $copy;
    }

    /**
     * Put into the trash, with all that is in it.
     */
    public function trash(FileWorkspace $workspace, FileNode $node): void
    {
        $this->access->authorize($workspace, $node, Level::EDIT);
        $this->assertNotCheckedOutByOther($node);
        $ids = $node->descendantIds();
        foreach (FileNode::query()->whereIn('id', $ids)->get() as $child) {
            if (!$this->access->can($workspace, $child, Level::EDIT)) {
                throw new FileServerException(exmtrans('file_server.error_trash_inner_forbidden', ['name' => $child->name]), 403);
            }
            $this->assertNotCheckedOutByOther($child);
        }
        $now = now();
        DB::transaction(function () use ($node, $ids, $now) {
            FileNode::query()->whereIn('id', array_merge([$node->id], $ids))->update([
                'trashed_at' => $now,
                'trashed_user_id' => $this->access->userId(),
                'trash_root_id' => $node->id,
            ]);
        });
        FileSearch::sync(array_merge([$node->id], $ids));
    }

    /**
     * The folders and files removed by a user (not those removed with their folder).
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public function trashItems(FileWorkspace $workspace)
    {
        $this->access->authorize($workspace, null, Level::VIEW);
        return FileNode::query()->where('workspace_id', $workspace->id)->whereNotNull('trashed_at')
            ->whereColumn('trash_root_id', 'id')->orderByDesc('trashed_at')->get()
            ->filter(function (FileNode $node) use ($workspace) {
                return $this->access->can($workspace, $node, Level::EDIT);
            })->values();
    }

    /**
     * Take out of the trash, with all that was removed with it. When its folder
     * is not there any more (in the trash, or removed), it goes to the top.
     */
    public function restore(FileWorkspace $workspace, FileNode $node): FileNode
    {
        if (!$node->isTrashed() || $node->trash_root_id !== $node->id) {
            throw FileServerException::notFound();
        }
        $this->access->authorize($workspace, $node, Level::EDIT);

        $parent = isset($node->parent_id) ? FileNode::find($node->parent_id) : null;
        if (isset($node->parent_id) && (!isset($parent) || $parent->isTrashed())) {
            $parent = null;
        }
        $name = $this->freeName($workspace, $parent, $node->name);
        $ids = FileNode::query()->where('trash_root_id', $node->id)->pluck('id')->map('intval')->all();

        DB::transaction(function () use ($node, $parent, $name) {
            FileNode::query()->where('trash_root_id', $node->id)->update([
                'trashed_at' => null, 'trashed_user_id' => null, 'trash_root_id' => null,
            ]);
            $node->refresh();
            $node->parent_id = isset($parent) ? $parent->id : null;
            $node->name = $name;
            $node->save();
        });
        $this->access->flush();
        FileSearch::sync($ids);
        return $node;
    }

    /**
     * Remove from the trash for good (the bytes too).
     */
    public function purge(FileWorkspace $workspace, FileNode $node, bool $checkPermission = true): void
    {
        if (!$node->isTrashed() || $node->trash_root_id !== $node->id) {
            throw FileServerException::notFound();
        }
        if ($checkPermission) {
            $this->access->authorize($workspace, $node, Level::MANAGE);
        }
        $nodes = FileNode::query()->where('trash_root_id', $node->id)->get();
        $ids = $nodes->pluck('id')->all();
        foreach (FileNodeVersion::query()->whereIn('node_id', $ids)->get() as $version) {
            $this->deleteVersionObjects($version);
        }
        DB::transaction(function () use ($ids) {
            FileNodeVersion::query()->whereIn('node_id', $ids)->delete();
            FilePermission::query()->whereIn('node_id', $ids)->delete();
            FileFavorite::query()->whereIn('node_id', $ids)->delete();
            FileNodeText::query()->whereIn('node_id', $ids)->delete();
            FileDataLink::query()->whereIn('node_id', $ids)->delete();
            FileNode::query()->whereIn('id', $ids)->delete();
        });
        FileSearch::sync(array_map('intval', $ids));
        foreach ($nodes as $removed) {
            if ($removed->isFile()) {
                static::disk()->deleteDirectory(static::STORAGE_DIR . '/' . $workspace->id . '/' . $removed->suuid);
            }
        }
    }

    /**
     * Remove for good what stayed in the trash longer than the days of its workspace.
     * Returns the count of the folders and files removed by users that were removed for good.
     */
    public static function purgeExpired(): int
    {
        $count = 0;
        $library = new self(new Access(null, true, []));
        foreach (FileWorkspace::query()->where('trash_days', '>', 0)->get() as $workspace) {
            $limit = now()->subDays($workspace->trash_days);
            $roots = FileNode::query()->where('workspace_id', $workspace->id)->whereNotNull('trashed_at')
                ->whereColumn('trash_root_id', 'id')->where('trashed_at', '<', $limit)->get();
            foreach ($roots as $root) {
                $library->purge($workspace, $root, false);
                $count++;
            }
        }
        return $count;
    }

    // ---------------------------------------------------------------- check out (phase 2)

    /**
     * Lock the file for the user while they edit it: the others cannot change it.
     */
    public function checkout(FileWorkspace $workspace, FileNode $node): FileNode
    {
        $this->assertFile($node);
        $this->access->authorize($workspace, $node, Level::EDIT);
        $this->assertNotCheckedOutByOther($node);
        $node->checked_out_user_id = $this->access->userId();
        $node->checked_out_at = now();
        $node->save();
        return $node;
    }

    /**
     * Unlock the file; with a local file, it becomes the new version first.
     */
    public function checkin(FileWorkspace $workspace, FileNode $node, ?string $localPath = null, ?string $comment = null): FileNode
    {
        $this->assertFile($node);
        $this->access->authorize($workspace, $node, Level::EDIT);
        if (intval($node->checked_out_user_id) !== $this->access->userId()) {
            throw new FileServerException(exmtrans('file_server.error_not_checked_out'));
        }
        if (isset($localPath)) {
            $this->addVersion($workspace, $node, $localPath, $comment);
        }
        $node->refresh();
        $node->checked_out_user_id = null;
        $node->checked_out_at = null;
        $node->save();
        return $node;
    }

    /**
     * Unlock a file another user locked (those who manage it only).
     */
    public function discardCheckout(FileWorkspace $workspace, FileNode $node): FileNode
    {
        $this->assertFile($node);
        if (intval($node->checked_out_user_id) !== $this->access->userId()) {
            $this->access->authorize($workspace, $node, Level::MANAGE);
        } else {
            $this->access->authorize($workspace, $node, Level::EDIT);
        }
        $node->checked_out_user_id = null;
        $node->checked_out_at = null;
        $node->save();
        return $node;
    }

    // ---------------------------------------------------------------- download

    /**
     * A version of a file the user may see (the current one by default).
     */
    public function version(FileWorkspace $workspace, FileNode $node, ?int $versionId = null): FileNodeVersion
    {
        $this->assertFile($node);
        $this->access->authorize($workspace, $node, Level::VIEW);
        $version = isset($versionId)
            ? FileNodeVersion::query()->where('node_id', $node->id)->find($versionId)
            : $node->current_version;
        if (!isset($version)) {
            throw FileServerException::notFound();
        }
        return $version;
    }

    /**
     * Copy the bytes of a version into a local temporary file (its path).
     */
    public function downloadToLocal(FileNodeVersion $version): string
    {
        $local = static::tempPath('dl_');
        $in = static::disk()->readStream($version->path);
        if (!is_resource($in)) {
            throw FileServerException::notFound();
        }
        $out = fopen($local, 'wb');
        if ($out === false) {
            throw new FileServerException(exmtrans('file_server.error_upload'));
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
        return $local;
    }

    /**
     * A zip of the folders and files (what the user may see in them), as a local
     * temporary file.
     *
     * @param FileNode[] $nodes
     */
    public function zip(FileWorkspace $workspace, array $nodes): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileServerException(exmtrans('file_server.error_zip'));
        }
        foreach ($nodes as $node) {
            $this->access->authorize($workspace, $node, Level::VIEW);
        }
        $max = static::zipMaxBytes();
        if ($max > 0) {
            $total = 0;
            foreach ($nodes as $node) {
                $total += $this->zipSize($workspace, $node, true);
            }
            if ($total > $max) {
                throw new FileServerException(exmtrans('file_server.error_zip_too_large', ['size' => bytesToHuman($max)]), 413);
            }
        }

        // one zip at a time for each user (for the links: each workspace), so
        // that many at the same time do not fill the disk (ISS00410)
        $lock = \Illuminate\Support\Facades\Cache::lock('exment_file_server_zip_' . ($this->forLink ? 'link_' . $workspace->id : 'user_' . intval($this->access->userId())), 900);
        if (!$lock->get()) {
            throw new FileServerException(exmtrans('file_server.error_zip_busy'), 429);
        }
        $path = static::tempPath('zip_');
        $locals = [];
        $done = false;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new FileServerException(exmtrans('file_server.error_zip'));
            }
            foreach ($nodes as $node) {
                $this->addToZip($workspace, $zip, $node, '', $locals);
            }
            $zip->close();
            $done = true;
        } finally {
            foreach ($locals as $local) {
                @unlink($local);
            }
            if (!$done) {
                @unlink($path);
            }
            $lock->release();
        }
        return $path;
    }

    /**
     * The bytes of what goes into a zip: what the user may see (for a link:
     * not what has its own permissions, so a refusal tells nothing of it).
     */
    protected function zipSize(FileWorkspace $workspace, FileNode $node, bool $root): int
    {
        if (!$this->access->can($workspace, $node, Level::VIEW) || ($this->forLink && !$root && $node->has_unique_permissions)) {
            return 0;
        }
        if ($node->isFile()) {
            return intval($node->size);
        }
        $total = 0;
        foreach (FileNode::query()->where('parent_id', $node->id)->whereNull('trashed_at')->get() as $child) {
            $total += $this->zipSize($workspace, $child, false);
        }
        return $total;
    }

    /**
     * @param string[] $locals
     */
    protected function addToZip(FileWorkspace $workspace, \ZipArchive $zip, FileNode $node, string $prefix, array &$locals): void
    {
        if (!$this->access->can($workspace, $node, Level::VIEW)) {
            return;
        }
        // a link gives what is under it, but not what has its own permissions (ISS00410)
        if ($this->forLink && $prefix !== '' && $node->has_unique_permissions) {
            return;
        }
        $name = $prefix . $node->name;
        if ($node->isFile()) {
            $version = $node->current_version;
            if (!isset($version)) {
                return;
            }
            $local = $this->downloadToLocal($version);
            $locals[] = $local;
            $zip->addFile($local, $name);
            return;
        }
        $zip->addEmptyDir($name);
        foreach (FileNode::query()->where('parent_id', $node->id)->whereNull('trashed_at')->orderBy('name')->get() as $child) {
            $this->addToZip($workspace, $zip, $child, $name . '/', $locals);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The name of a folder or file, cleaned; stops when it cannot be used.
     */
    public function validName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > static::MAX_NAME_LENGTH
            || preg_match('#[\\\\/:*?"<>|]#', $name) === 1) {
            throw new FileServerException(exmtrans('file_server.error_name', ['name' => $name]));
        }
        return $name;
    }

    /**
     * The folder or file with the name in a folder (not in the trash), without regard to the case.
     */
    public function sibling(FileWorkspace $workspace, ?FileNode $parent, string $name): ?FileNode
    {
        $query = FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at');
        isset($parent) ? $query->where('parent_id', $parent->id) : $query->whereNull('parent_id');
        $lower = mb_strtolower($name);
        return $query->get()->first(function (FileNode $node) use ($lower) {
            return mb_strtolower($node->name) === $lower;
        });
    }

    /**
     * The name, or "name (2).ext", "name (3).ext"... when it is used in the folder.
     */
    public function freeName(FileWorkspace $workspace, ?FileNode $parent, string $name): string
    {
        if ($this->sibling($workspace, $parent, $name) === null) {
            return $name;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext === '' ? $name : mb_substr($name, 0, mb_strlen($name) - mb_strlen($ext) - 1);
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . ' (' . $i . ')' . ($ext === '' ? '' : '.' . $ext);
            if ($this->sibling($workspace, $parent, $candidate) === null) {
                return $candidate;
            }
        }
        return $base . ' (' . Str::random(6) . ')' . ($ext === '' ? '' : '.' . $ext);
    }

    protected function assertFolder(FileWorkspace $workspace, ?FileNode $folder): void
    {
        if (!isset($folder)) {
            return;
        }
        if ($folder->workspace_id !== $workspace->id || !$folder->isFolder() || $folder->isTrashed()) {
            throw FileServerException::notFound();
        }
    }

    protected function assertFile(FileNode $node): void
    {
        if (!$node->isFile()) {
            throw FileServerException::notFound();
        }
    }

    protected function assertNotCheckedOutByOther(FileNode $node): void
    {
        if (isset($node->checked_out_user_id) && intval($node->checked_out_user_id) !== $this->access->userId()) {
            throw new FileServerException(exmtrans('file_server.error_checked_out', ['name' => $node->name]), 423);
        }
    }

    /**
     * Stop when the bytes do not fit in the space of the workspace.
     */
    public function assertQuota(FileWorkspace $workspace, int $addBytes): void
    {
        if (!isset($workspace->quota_bytes)) {
            return;
        }
        if ($workspace->usedBytes() + $addBytes > $workspace->quota_bytes) {
            throw new FileServerException(exmtrans('file_server.error_quota'), 413);
        }
    }

    protected function treeSize(FileNode $node): int
    {
        if ($node->isFile()) {
            return intval($node->size);
        }
        return intval(FileNode::query()->whereIn('id', $node->descendantIds())->where('node_type', FileNode::TYPE_FILE)->sum('size'));
    }

    protected function deleteVersionObjects(FileNodeVersion $version): void
    {
        foreach ([$version->path, $version->thumbnail_path, $version->preview_path] as $path) {
            if (isset($path) && $path !== '') {
                try {
                    static::disk()->delete($path);
                } catch (\Throwable $e) {
                    \Log::warning('[FileServer] could not remove ' . $path . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * A new local temporary file (its path).
     */
    /**
     * Remove the temporary files older than the hours given (a zip that was
     * not sent, a download that stopped). Run from the daily purge.
     */
    public static function removeOldTempFiles(int $hours = 24): int
    {
        $dir = storage_path('app/file_server_tmp');
        if (!is_dir($dir)) {
            return 0;
        }
        $limit = time() - $hours * 3600;
        $count = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if ($entry === '.' || $entry === '..' || !is_file($path)) {
                continue;
            }
            $time = @filemtime($path);
            if ($time !== false && $time < $limit && @unlink($path)) {
                $count++;
            }
        }
        return $count;
    }

    public static function tempPath(string $prefix): string
    {
        $dir = storage_path('app/file_server_tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $prefix . Str::random(24);
    }
}
