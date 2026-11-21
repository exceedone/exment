<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;

/**
 * File server: what a user may do in a workspace and in each folder and file
 * (ISS00293, phase 1).
 *
 * As in SharePoint, a folder or file takes the permissions of its parent,
 * unless it has its own ("unique") permissions. The top of the workspace
 * takes the members of the workspace. A permission is given to a user or to
 * an organization (the users of the organization). The highest level that
 * matches the user counts. The users with the system permission manage all.
 */
class Access
{
    protected ?int $userId;
    protected bool $isSystemAdmin;

    /** @var int[]|null */
    protected ?array $organizationIds = null;

    /** @var array<string, int> */
    protected array $cache = [];

    /**
     * @param int[]|null $organizationIds null: worked out when needed
     */
    public function __construct(?int $userId, bool $isSystemAdmin, ?array $organizationIds = null)
    {
        $this->userId = $userId;
        $this->isSystemAdmin = $isSystemAdmin;
        $this->organizationIds = $organizationIds;
    }

    /**
     * The access of the login user.
     */
    public static function current(): self
    {
        $user = \Exment::user();
        // the file server not used (Feature management): no one may do anything
        if (!isset($user) || !Library::featureEnabled()) {
            return new self(null, false, []);
        }
        $userId = \Exment::getUserId();
        return new self(isset($userId) ? intval($userId) : null, boolval($user->hasPermission(Permission::SYSTEM)));
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function isSystemAdmin(): bool
    {
        return $this->isSystemAdmin;
    }

    /**
     * @return int[]
     */
    public function organizationIds(): array
    {
        if (!isset($this->organizationIds)) {
            $ids = [];
            $user = isset($this->userId) ? getModelName(\Exceedone\Exment\Enums\SystemTableName::USER)::find($this->userId) : null;
            if (isset($user)) {
                $ids = array_map('intval', array_values((array)$user->getOrganizationIdsForQuery()));
            }
            $this->organizationIds = $ids;
        }
        return $this->organizationIds;
    }

    /**
     * The level of the user in the workspace (its top), or in a folder or file.
     */
    public function level(FileWorkspace $workspace, ?FileNode $node = null): int
    {
        $level = $this->baseLevel($workspace, $node);
        // a file that waits for its approval (or was rejected): its maker (who
        // may change it or take it back) and those who manage it (ISS00379)
        if (isset($node) && $level < Level::MANAGE && $node->isDraft()) {
            if (!isset($this->userId) || intval($node->created_user_id) !== $this->userId) {
                return Level::NONE;
            }
            // only while the user may still see it (taken out of the workspace: nothing)
            return $level >= Level::VIEW ? max($level, Level::EDIT) : Level::NONE;
        }
        return $level;
    }

    /**
     * The level from the permissions (the drafts aside).
     */
    protected function baseLevel(FileWorkspace $workspace, ?FileNode $node = null): int
    {
        // the system itself (no user: the trash emptied on the schedule, the files from a request link)
        if ($this->isSystemAdmin) {
            return Level::MANAGE;
        }
        if (!isset($this->userId)) {
            return Level::NONE;
        }

        // the node whose permissions count: itself, or the nearest parent with its own
        $source = $node;
        $guard = 0;
        while (isset($source) && !$source->has_unique_permissions && $guard++ < 1000) {
            $source = isset($source->parent_id) ? FileNode::find($source->parent_id) : null;
        }
        $key = $workspace->id . ':' . (isset($source) ? $source->id : 'top');
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $query = FilePermission::query()->where('workspace_id', $workspace->id);
        if (isset($source)) {
            $query->where('node_id', $source->id);
        } else {
            $query->whereNull('node_id');
        }
        $level = $this->matchLevel($query->get());
        return $this->cache[$key] = $level;
    }

    /**
     * The highest level of the permissions that match the user.
     *
     * @param iterable<FilePermission> $permissions
     */
    public function matchLevel(iterable $permissions): int
    {
        $level = Level::NONE;
        foreach ($permissions as $permission) {
            if ($this->matches($permission)) {
                $level = max($level, intval($permission->level));
            }
        }
        return $level;
    }

    protected function matches(FilePermission $permission): bool
    {
        if ($permission->target_type === FilePermission::TARGET_USER) {
            return intval($permission->target_id) === $this->userId;
        }
        if ($permission->target_type === FilePermission::TARGET_ORGANIZATION) {
            return in_array(intval($permission->target_id), $this->organizationIds(), true);
        }
        return false;
    }

    public function can(FileWorkspace $workspace, ?FileNode $node, int $level): bool
    {
        return $this->level($workspace, $node) >= $level;
    }

    /**
     * Stop with "forbidden" unless the user has the level.
     */
    public function authorize(FileWorkspace $workspace, ?FileNode $node, int $level): void
    {
        if (!$this->can($workspace, $node, $level)) {
            throw FileServerException::forbidden();
        }
    }

    /**
     * The workspaces the user may open: a member of it, or given a folder or file of it.
     *
     * @return \Illuminate\Support\Collection<int, FileWorkspace>
     */
    public function workspaces()
    {
        $query = FileWorkspace::query()->orderBy('name');
        if (!$this->isSystemAdmin) {
            if (!isset($this->userId)) {
                return collect();
            }
            $ids = $this->matchingPermissionsQuery()->distinct()->pluck('workspace_id')->all();
            $query->whereIn('id', $ids);
        }
        return $query->get();
    }

    /**
     * The folders and files given to the user one by one (not as a member of
     * the workspace): "shared with me".
     *
     * @return \Illuminate\Support\Collection<int, FileNode>
     */
    public function sharedNodes()
    {
        if (!isset($this->userId)) {
            return collect();
        }
        $nodeIds = $this->matchingPermissionsQuery()->whereNotNull('node_id')->distinct()->pluck('node_id')->all();
        return FileNode::query()->whereIn('id', $nodeIds)->whereNull('trashed_at')->orderBy('name')->get()
            ->filter(function (FileNode $node) {
                $workspace = $node->workspace;
                return isset($workspace) && $this->can($workspace, $node, Level::VIEW);
            })->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<FilePermission>
     */
    protected function matchingPermissionsQuery()
    {
        $orgIds = $this->organizationIds();
        return FilePermission::query()->where(function ($query) use ($orgIds) {
            $query->where(function ($query) {
                $query->where('target_type', FilePermission::TARGET_USER)->where('target_id', $this->userId);
            });
            if (count($orgIds) > 0) {
                $query->orWhere(function ($query) use ($orgIds) {
                    $query->where('target_type', FilePermission::TARGET_ORGANIZATION)->whereIn('target_id', $orgIds);
                });
            }
        });
    }

    /**
     * Who may make a workspace. "all" (the default): every user; "admin": the
     * users with the system permission.
     */
    public function canCreateWorkspace(): bool
    {
        if (!isset($this->userId)) {
            return false;
        }
        if ($this->isSystemAdmin) {
            return true;
        }
        // the system administrators only, unless the site says "all"
        return config('exment.file_server_workspace_create', 'admin') === 'all';
    }

    /**
     * Forget what was worked out (after the permissions changed).
     */
    public function flush(): void
    {
        $this->cache = [];
    }
}
