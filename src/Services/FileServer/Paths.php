<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * File server (ISS02815): the address of a folder or a file is its path,
 * /file_server/<workspace>/<folder>/.../<file>, not its number.
 *
 * - The names are encoded in the address (Japanese, spaces, "#", "%"...).
 *   No name has a "/" (Library::validName), so a path names one item.
 * - Some keep the addresses with numbers:
 *   - a workspace whose name cannot be in an address (only digits, the pages
 *     under file_server/ such as "create" or "search", a "/"), or that another
 *     workspace also has (the path would not say which one);
 *   - a name the web servers keep for themselves (".php" at the end, a dot first);
 *   - a path too long for an address (MAX_URL_LENGTH).
 * - When a folder or a file is renamed or moved, its old path is kept and
 *   leads to it. A new item that takes the old path wins (the old path is
 *   forgotten). ?n=<id> in a link names the item for good.
 * - Nothing is told about what the user may not see: a path to it is the
 *   same as a path to nothing (404). An item in a folder the user may not see
 *   keeps the number for them, and a path through that folder is not found:
 *   the path would tell the name of the folder (ISS00433).
 *
 * The API, WebDAV and the operations of the pages keep the numbers.
 */
class Paths
{
    public const TABLE = 'file_path_histories';

    /**
     * The longest address made with the path. A longer one keeps the number:
     * the web servers refuse long addresses (nginx: 8 KB, 414), and the mails,
     * Teams and Office cut them. 2,000 is what all of them take.
     */
    public const MAX_URL_LENGTH = 2000;

    /** the first parts of the addresses under file_server/ that are pages, not workspaces (with those of the routes) */
    public const RESERVED = ['create', 'search', 'data_lookup', 'picker'];

    protected static ?bool $ready = null;

    /** @var string[]|null */
    protected static ?array $reserved = null;

    /** @var array<int, string[]> the names of the folders, from the top, by their id */
    protected array $folders = [];

    /** @var array<int, int|null> the folder each folder is in, by their id */
    protected array $parents = [];

    /** @var array<int, bool> the folders the user may see, by their id */
    protected array $seen = [];

    /** @var array<string, int>|null how many workspaces have each name (by its key) */
    protected ?array $names = null;

    /**
     * @param Access|null $access the user the addresses are for: an item in a
     *        folder they may not see keeps the number (the path would tell its
     *        name, ISS00433). Null: no one in particular (all the paths).
     */
    public function __construct(protected ?Access $access = null)
    {
    }

    /**
     * The name of the workspace as it is in an address, or null when it cannot
     * be one (whatever the other workspaces are called).
     */
    public static function segment(FileWorkspace $workspace): ?string
    {
        $name = Library::normalizeName(trim(strval($workspace->name)));
        if (!static::canBeInAddress($name) || preg_match('/^[0-9]+$/', $name) === 1
            || in_array(mb_strtolower($name), static::reserved(), true)) {
            return null;
        }
        return $name;
    }

    /**
     * A name the web servers pass on to Exment: not empty, no "/", "\" or
     * control character, not ".php" at the end (nginx gives those to PHP-FPM
     * as scripts), not a dot first (the hidden files are refused, and "." "..").
     */
    protected static function canBeInAddress(string $name): bool
    {
        return $name !== '' && preg_match('#[/\\\\\x00-\x1F\x7F]#', $name) !== 1
            && !str_starts_with($name, '.') && preg_match('/\.(php[0-9]*|phtml|phar)$/i', $name) !== 1;
    }

    /**
     * The words after file_server/ that are pages (the routes), in lower case.
     *
     * @return string[]
     */
    protected static function reserved(): array
    {
        if (!isset(static::$reserved)) {
            $words = static::RESERVED;
            try {
                foreach (app('router')->getRoutes()->getRoutes() as $route) {
                    if (preg_match('#(^|/)file_server/([^/{]+)#', $route->uri(), $m) === 1) {
                        $words[] = mb_strtolower($m[2]);
                    }
                }
            } catch (\Throwable $e) {
                // the routes are not there (a command): the list above
            }
            static::$reserved = array_values(array_unique($words));
        }
        return static::$reserved;
    }

    /**
     * The key of a path in one form: the same for "A/b.txt", "a/B.TXT" and its NFD form.
     *
     * @param string[] $names
     */
    public static function key(array $names): string
    {
        return hash('sha256', mb_strtolower(Library::normalizeName(implode('/', $names))));
    }

    /**
     * The name of the workspace in its addresses: null when it cannot be one,
     * or another workspace has it too.
     */
    public function workspaceSegment(FileWorkspace $workspace): ?string
    {
        $segment = static::segment($workspace);
        if (!isset($segment)) {
            return null;
        }
        if (!isset($this->names)) {
            $this->names = [];
            foreach (FileWorkspace::query()->pluck('name') as $name) {
                $key = static::key([trim(strval($name))]);
                $this->names[$key] = ($this->names[$key] ?? 0) + 1;
            }
        }
        return ($this->names[static::key([$segment])] ?? 0) > 1 ? null : $segment;
    }

    /**
     * The names from the top of the workspace to the node (the node last).
     *
     * @return string[]
     */
    public function names(FileNode $node): array
    {
        return array_merge($this->folderNames($node->parent_id), [strval($node->name)]);
    }

    /**
     * @return string[]
     */
    protected function folderNames(?int $folderId): array
    {
        $list = [];
        $ids = [];
        $id = $folderId;
        $guard = 0;
        while (isset($id) && $guard++ < 1000) {
            if (isset($this->folders[$id])) {
                $list = array_merge($this->folders[$id], $list);
                break;
            }
            $folder = FileNode::find($id);
            if (!isset($folder)) {
                break;
            }
            array_unshift($list, strval($folder->name));
            $ids[] = $id;
            $this->parents[$id] = $folder->parent_id;
            $id = $folder->parent_id;
        }
        // keep each folder on the way (the last one met is the highest)
        $count = count($list);
        foreach ($ids as $i => $folder) {
            $this->folders[$folder] = array_slice($list, 0, $count - $i);
        }
        return $list;
    }

    /**
     * The address of the page of a folder (its list) or a file, or of the top
     * of the workspace; null when it keeps the address with numbers.
     */
    public function url(FileWorkspace $workspace, ?FileNode $node = null): ?string
    {
        $segment = $this->workspaceSegment($workspace);
        if (!isset($segment)) {
            return null;
        }
        if (isset($node) && $this->hidden($workspace, $node)) {
            return null;
        }
        $names = array_merge([$segment], isset($node) ? $this->names($node) : []);
        foreach ($names as $name) {
            if (!static::canBeInAddress($name)) {
                return null;
            }
        }
        $url = admin_url('file_server/' . implode('/', array_map('rawurlencode', $names)));
        // with room for "?n=<id>"
        return strlen($url) + 13 <= static::MAX_URL_LENGTH ? $url : null;
    }

    /**
     * The address to give to others ("リンクをコピー"): the path, and the number
     * of the item, which opens it after it is renamed or moved.
     *
     * @param string|null $url the path of the item when it is known (url())
     */
    public function link(FileWorkspace $workspace, ?FileNode $node = null, ?string $url = null): string
    {
        $url = $url ?? $this->url($workspace, $node);
        if (!isset($url)) {
            return static::numberUrl($workspace, $node);
        }
        return isset($node) ? $url . '?n=' . $node->id : $url;
    }

    /**
     * The address with numbers (as before ISS02815).
     */
    public static function numberUrl(FileWorkspace $workspace, ?FileNode $node = null): string
    {
        if (!isset($node)) {
            return admin_urls('file_server', $workspace->id);
        }
        return admin_urls('file_server', $workspace->id, $node->isFolder() ? 'folder' : 'nodes', $node->id);
    }

    /**
     * The user may not see the node, or a folder it is in (false when no user is given).
     */
    public function hidden(FileWorkspace $workspace, FileNode $node): bool
    {
        if (!isset($this->access)) {
            return false;
        }
        if (!$this->access->can($workspace, $node, Level::VIEW)) {
            return true;
        }
        $id = $node->parent_id;
        $guard = 0;
        while (isset($id) && $guard++ < 1000) {
            if (!array_key_exists($id, $this->seen)) {
                $folder = FileNode::find($id);
                $this->parents[$id] = isset($folder) ? $folder->parent_id : null;
                $this->seen[$id] = isset($folder) && $this->access->can($workspace, $folder, Level::VIEW);
            }
            if (!$this->seen[$id]) {
                return true;
            }
            if (!array_key_exists($id, $this->parents)) {
                $parent = FileNode::query()->whereKey($id)->value('parent_id');
                $this->parents[$id] = isset($parent) ? intval($parent) : null;
            }
            $id = $this->parents[$id];
        }
        return false;
    }

    // ================================================================ finding

    /**
     * The workspace and the node (null: the top) of a path. A node the user may
     * not see (or in a folder they may not see) is not found, the same as one
     * that is not there. Two workspaces with the name: the one where the path
     * is; none when it is in both.
     *
     * @return array{0: FileWorkspace, 1: FileNode|null}
     */
    public function resolve(Access $access, string $path, ?int $id = null): array
    {
        $this->access = $access;
        $segments = array_values(array_filter(explode('/', $path), function ($segment) {
            return $segment !== '';
        }));
        if (count($segments) === 0) {
            throw FileServerException::notFound();
        }
        $workspaces = $this->workspaces(array_shift($segments));

        // the number names the item for good; gone (in the trash): the path, as without it
        if (isset($id)) {
            foreach ($workspaces as $workspace) {
                $node = FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at')->find($id);
                if (isset($node)) {
                    return $this->visible($workspace, $node);
                }
            }
        }
        // the paths of now in all the workspaces of the name first, then the old ones
        foreach ([false, true] as $old) {
            $found = [];
            foreach ($workspaces as $workspace) {
                $node = $this->walk($workspace, $segments, $old);
                if ($node !== false) {
                    $found[] = [$workspace, $node];
                }
            }
            if (count($found) === 1) {
                return $this->visible($found[0][0], $found[0][1]);
            }
            if (count($found) > 1) {
                // the path does not say which: none (nor which ones the user may see)
                break;
            }
        }
        throw FileServerException::notFound();
    }

    /**
     * @return array{0: FileWorkspace, 1: FileNode|null}
     */
    protected function visible(FileWorkspace $workspace, ?FileNode $node): array
    {
        $visible = isset($node) ? !$this->hidden($workspace, $node)
            : isset($this->access) && $this->access->can($workspace, null, Level::VIEW);
        if (!$visible) {
            throw FileServerException::notFound();
        }
        return [$workspace, $node];
    }

    /**
     * The workspaces of the first part of a path: by their name, else by a name
     * they had.
     *
     * @return FileWorkspace[]
     */
    protected function workspaces(string $segment): array
    {
        $key = static::key([$segment]);
        $found = FileWorkspace::query()->orderBy('id')->get()->filter(function (FileWorkspace $workspace) use ($key) {
            $name = static::segment($workspace);
            return isset($name) && static::key([$name]) === $key;
        })->values()->all();
        if (count($found) > 0 || !static::ready()) {
            return $found;
        }
        $ids = DB::table(static::TABLE)->whereNull('node_id')->where('path_key', $key)
            ->orderByDesc('id')->pluck('workspace_id')->map(fn ($v) => intval($v))->unique()->all();
        return FileWorkspace::query()->whereIn('id', $ids)->get()->filter(function (FileWorkspace $workspace) {
            return static::segment($workspace) !== null;
        })->values()->all();
    }

    /**
     * Down the path from the top. With $old, a name not there now is looked
     * for among the old paths: as it was written, and under the folder it
     * leads to now.
     *
     * @param string[] $segments
     * @return FileNode|null|false null: the top, false: not there
     */
    protected function walk(FileWorkspace $workspace, array $segments, bool $old)
    {
        if (count($segments) === 0) {
            // the top is in each workspace of the name: once, with the paths of now
            return $old ? false : null;
        }
        $node = null;
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $child = $this->child($workspace, $node, $segment);
            if (!isset($child) && $old) {
                $keys = array_unique([
                    static::key(array_slice($segments, 0, $i + 1)),
                    static::key(array_merge(isset($node) ? $this->names($node) : [], [$segment])),
                ]);
                $child = $this->remembered($workspace, $keys);
            }
            if (!isset($child) || ($i < $last && !$child->isFolder())) {
                return false;
            }
            $node = $child;
        }
        return $node;
    }

    protected function child(FileWorkspace $workspace, ?FileNode $parent, string $name): ?FileNode
    {
        $query = FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at');
        isset($parent) ? $query->where('parent_id', $parent->id) : $query->whereNull('parent_id');
        $exact = (clone $query)->where('name', $name)->get()->first(function (FileNode $node) use ($name) {
            return strval($node->name) === $name;
        });
        if (isset($exact)) {
            return $exact;
        }
        // another case or form of the characters (NFD from a Mac): as Library::sibling
        $lower = mb_strtolower(Library::normalizeName($name));
        return $query->get()->first(function (FileNode $node) use ($lower) {
            return mb_strtolower(Library::normalizeName(strval($node->name))) === $lower;
        });
    }

    /**
     * @param string[] $keys
     */
    protected function remembered(FileWorkspace $workspace, array $keys): ?FileNode
    {
        if (!static::ready()) {
            return null;
        }
        $ids = DB::table(static::TABLE)->where('workspace_id', $workspace->id)->whereNotNull('node_id')
            ->whereIn('path_key', $keys)->orderByDesc('id')->pluck('node_id');
        foreach ($ids as $id) {
            $node = FileNode::query()->where('workspace_id', $workspace->id)->whereNull('trashed_at')->find(intval($id));
            if (isset($node)) {
                return $node;
            }
        }
        return null;
    }

    // ================================================================ keeping

    /**
     * The table is there (a site not updated yet has none: nothing is kept).
     */
    protected static function ready(): bool
    {
        if (!isset(static::$ready) || !static::$ready) {
            static::$ready = \Illuminate\Support\Facades\Schema::hasTable(static::TABLE);
        }
        return static::$ready;
    }

    /**
     * Before a folder or a file is renamed or moved: its path until now.
     */
    public static function rememberNode(FileNode $node): void
    {
        if (!static::ready()) {
            return;
        }
        if (!isset($node->id) || $node->getOriginal('trashed_at') !== null) {
            return;
        }
        $paths = new self();
        $parentId = $node->getOriginal('parent_id');
        $names = array_merge($paths->folderNames(isset($parentId) ? intval($parentId) : null), [strval($node->getOriginal('name'))]);
        static::keep(intval($node->workspace_id), intval($node->id), $names);
    }

    /**
     * Before a workspace is renamed: its name until now.
     */
    public static function rememberWorkspace(FileWorkspace $workspace): void
    {
        if (!static::ready()) {
            return;
        }
        $old = new FileWorkspace();
        $old->name = strval($workspace->getOriginal('name'));
        $segment = static::segment($old);
        if (isset($workspace->id) && isset($segment)) {
            static::keep(intval($workspace->id), null, [$segment]);
        }
    }

    /**
     * A folder or a file now at a path: the old paths there lead to it, not to the one that had it.
     */
    public static function forgetNode(FileNode $node): void
    {
        if (!static::ready()) {
            return;
        }
        if (!isset($node->id) || $node->isTrashed()) {
            return;
        }
        $key = static::key((new self())->names($node));
        DB::table(static::TABLE)->where('workspace_id', $node->workspace_id)->whereNotNull('node_id')->where('path_key', $key)->delete();
    }

    public static function forgetWorkspace(FileWorkspace $workspace): void
    {
        if (!static::ready()) {
            return;
        }
        $segment = static::segment($workspace);
        if (isset($segment)) {
            DB::table(static::TABLE)->whereNull('node_id')->where('path_key', static::key([$segment]))->delete();
        }
    }

    /**
     * Folders and files removed for good: nothing leads to them.
     *
     * @param int[] $ids
     */
    public static function forgetRemoved(array $ids): void
    {
        if (!static::ready() || count($ids) === 0) {
            return;
        }
        DB::table(static::TABLE)->whereIn('node_id', $ids)->delete();
    }

    /**
     * @param string[] $names
     */
    protected static function keep(int $workspaceId, ?int $nodeId, array $names): void
    {
        $key = static::key($names);
        $query = DB::table(static::TABLE)->where('path_key', $key);
        isset($nodeId) ? $query->where('workspace_id', $workspaceId)->whereNotNull('node_id') : $query->whereNull('node_id');
        // the latest one at a path is the one it leads to
        $query->delete();
        DB::table(static::TABLE)->insert([
            'workspace_id' => $workspaceId,
            'node_id' => $nodeId,
            'path_key' => $key,
            'path' => implode('/', $names),
            'created_at' => now(),
        ]);
    }
}
