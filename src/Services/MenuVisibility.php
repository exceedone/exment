<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;

/**
 * Which menus a user sees. The left menu, the editing of the menus
 * (auth/menu) and the template export all ask here.
 *
 * 1. The rows (rows()): the menus as the database has them, with their uri
 *    and icon, the same for every user. They are the only thing that may be
 *    cached (cacheRowsWith(); not cached unless a site sets it).
 * 2. Feature management and demo mode (nodes()), on every call: a menu of a
 *    feature not used, or that the user may not use, or of a plugin whose
 *    menu is off, is left out - or, for the editing and the templates, kept
 *    and marked "feature_hidden". Demo mode leaves out the menus of the pages
 *    it refuses, also from the editing.
 * 3. The user (isVisible(), tree()): the permissions, the organizations not
 *    used, a group only for some role groups (a system administrator always
 *    sees it), a group of the feature map with nothing in it; a group shows
 *    when one of its children shows. The answers are kept for the request,
 *    for each user apart (cleared with System::clearRequestSession()).
 *
 * For the left menu: tree(). For the editing of the menus:
 * (new Menu())->withHidden() (everything, marked). For a template:
 * exportTree().
 */
class MenuVisibility
{
    /**
     * The key of the rows for a cache set with cacheRowsWith().
     */
    public const ROWS_CACHE_KEY = 'exment_menu_all_rows';

    /**
     * @var callable|null function (string $key, \Closure $load): array
     */
    protected static $rowsRemember;

    /**
     * @var callable|null function (string $key): void
     */
    protected static $rowsForget;

    /**
     * @var array<int, callable> function (array $item, LoginUser $user): bool, true hides the menu
     */
    protected static $hideRules = [];

    /**
     * @var LoginUser|null
     */
    protected $user;

    /**
     * @param LoginUser|null $user
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * @param mixed $user a LoginUser; the login user when not given
     */
    public static function forUser($user = null): self
    {
        if (!isset($user)) {
            $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        }
        return new self($user instanceof LoginUser ? $user : null);
    }

    // The rows -----------------------------------------------------------------

    /**
     * Keep the rows (only the rows, never what a user sees) in a cache, e.g.
     *
     *   MenuVisibility::cacheRowsWith(
     *       function ($key, $load) { return System::cache($key, $load); },
     *       function ($key) { System::clearCache($key); }
     *   );
     *
     * The rows are forgotten when a menu is saved or deleted (Menu::boot());
     * a change of a table, a view or a plugin must clear the cache too. Null
     * reads the database every time again (as without it).
     */
    public static function cacheRowsWith(?callable $remember, ?callable $forget = null): void
    {
        static::$rowsRemember = $remember;
        static::$rowsForget = isset($remember) ? $forget : null;
    }

    /**
     * Forget the cached rows (a menu was saved or deleted).
     */
    public static function forgetRows(): void
    {
        if (isset(static::$rowsForget)) {
            call_user_func(static::$rowsForget, static::ROWS_CACHE_KEY);
        }
    }

    /**
     * The rows of the menu, the same for every user.
     *
     * @param \Closure $load reads them from the database
     * @return array<int, array<string, mixed>>
     */
    public static function rows(\Closure $load): array
    {
        $rows = isset(static::$rowsRemember) ? call_user_func(static::$rowsRemember, static::ROWS_CACHE_KEY, $load) : $load();
        return is_array($rows) ? $rows : [];
    }

    /**
     * The rows the login user has in the menu: Feature management and demo
     * mode. With $withHidden, the menus of the features are kept, marked
     * "feature_hidden" (the editing of the menus, the templates).
     *
     * @param array<int, array<string, mixed>> $rows from rows()
     * @return array<int, array<string, mixed>>
     */
    public static function nodes(array $rows, bool $withHidden = false): array
    {
        $results = [];
        foreach ($rows as $row) {
            unset($row['feature_hidden']);
            if (static::refusedByDemoMode($row)) {
                continue;
            }
            if (static::hiddenByFeatures($row)) {
                if (!$withHidden) {
                    continue;
                }
                $row['feature_hidden'] = true;
            }
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Demo mode: the menu of a page it refuses.
     *
     * @param array<string, mixed> $row
     */
    public static function refusedByDemoMode(array $row): bool
    {
        return array_get($row, 'menu_type') == MenuType::SYSTEM && DemoMode::hidesMenuUri(array_get($row, 'uri'));
    }

    /**
     * Feature management: a system menu of a feature not used, or that the
     * login user may not use, or whose menu is off; the menu of a plugin
     * turned off; a menu of any kind to a page of a feature the user may not
     * use.
     *
     * @param array<string, mixed> $row
     */
    public static function hiddenByFeatures(array $row): bool
    {
        switch (array_get($row, 'menu_type')) {
            case MenuType::PLUGIN:
                if (Features::hidesPluginMenu(isset($row['plugin_name']) ? strval($row['plugin_name']) : null)) {
                    return true;
                }
                break;
            case MenuType::SYSTEM:
                if (Features::hidesSystemMenu(strval(array_get($row, 'menu_target')))) {
                    return true;
                }
                break;
        }
        return Features::hidesMenuUri(isset($row['uri']) ? strval($row['uri']) : null);
    }

    // The user -----------------------------------------------------------------

    /**
     * Hide more menus, e.g. a menu only for the system administrators:
     *
     *   MenuVisibility::addHideRule(function (array $item, LoginUser $user) {
     *       return array_get($item, 'menu_name') === 'my_menu' && !$user->isAdministrator();
     *   });
     *
     * A group with only hidden menus in it is hidden too.
     */
    public static function addHideRule(callable $rule): void
    {
        static::$hideRules[] = $rule;
    }

    /**
     * For the tests.
     */
    public static function reset(): void
    {
        static::$rowsRemember = null;
        static::$rowsForget = null;
        static::$hideRules = [];
    }

    /**
     * The left menu of the user: the menus the user sees, and in a group the
     * ones in it the user sees.
     *
     * @param array<int, array<string, mixed>>|null $tree (new Menu())->toTree() when not given
     * @return array<int, array<string, mixed>>
     */
    public function tree(?array $tree = null): array
    {
        return $this->filterTree($tree ?? (new Menu())->toTree());
    }

    /**
     * The menus a template takes: all of them, with the ones of the features
     * not used. With target tables, the menus of those tables the user sees,
     * and the groups they are in.
     *
     * @param array<int, string> $targetTables
     * @return array<int, array<string, mixed>>
     */
    public function exportTree(array $targetTables = []): array
    {
        $tree = (new Menu())->withHidden()->toTree();
        if (count($targetTables) === 0) {
            return $tree;
        }
        return $this->filterTree($tree, $targetTables);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, string> $targetTables
     * @return array<int, array<string, mixed>>
     */
    protected function filterTree(array $nodes, array $targetTables = []): array
    {
        $results = [];
        foreach ($nodes as $node) {
            if (!$this->isVisible($node, $targetTables)) {
                continue;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->filterTree($node['children'], $targetTables);
            }
            $results[] = $node;
        }
        return $results;
    }

    /**
     * Whether the user sees a menu (a node of the tree, with its children) or
     * a page (its uri).
     *
     * @param mixed $item
     * @param array<int, string> $targetTables for a template: only the menus of these tables
     */
    public function isVisible($item, array $targetTables = []): bool
    {
        if (is_string($item)) {
            $item = ['uri' => $item];
        } elseif (is_object($item) && method_exists($item, 'toArray')) {
            $item = $item->toArray();
        }
        if (empty($item) || !is_array($item)) {
            return false;
        }
        if (!isset($this->user)) {
            return false;
        }

        $memoKey = $this->memoKey();
        if (!isset($memoKey)) {
            return $this->compute($item, $targetTables);
        }
        $key = md5(strval(json_encode([static::signature($item), array_values($targetTables)])));
        $memo = System::requestSession($memoKey);
        if (is_array($memo) && array_key_exists($key, $memo)) {
            return boolval($memo[$key]);
        }
        $result = $this->compute($item, $targetTables);
        $memo = System::requestSession($memoKey);
        $memo = is_array($memo) ? $memo : [];
        $memo[$key] = $result;
        System::setRequestSession($memoKey, $memo);
        return $result;
    }

    /**
     * The key of the answers of this user for the request (none for a user
     * not saved).
     */
    protected function memoKey(): ?string
    {
        $id = isset($this->user) ? $this->user->getKey() : null;
        if (!isset($id) || $id === '') {
            return null;
        }
        return 'menu_visibility_' . strval($id);
    }

    /**
     * What tells two menus apart for the answer: a group has no uri or table,
     * its name, its options (the role groups) and its children do.
     *
     * @param array<string, mixed> $item
     * @return array<int, mixed>
     */
    protected static function signature(array $item): array
    {
        $children = null;
        if (array_key_exists('children', $item)) {
            $children = [];
            foreach (static::children($item) as $child) {
                $children[] = is_array($child) ? static::signature($child) : $child;
            }
        }
        return [
            array_get($item, 'id'),
            array_get($item, 'uri'),
            array_get($item, 'menu_type'),
            array_get($item, 'menu_name'),
            array_get($item, 'table_name'),
            array_get($item, 'options'),
            $children,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int|string, mixed>
     */
    protected static function children(array $item): array
    {
        $children = $item['children'] ?? [];
        if ($children instanceof \Illuminate\Support\Collection) {
            return $children->all();
        }
        return is_array($children) ? $children : [];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $targetTables
     */
    protected function compute(array $item, array $targetTables): bool
    {
        /** @var LoginUser $user */
        $user = $this->user;

        foreach (static::$hideRules as $rule) {
            if (call_user_func($rule, $item, $user) === true) {
                return false;
            }
        }

        // if organization and not use org setting, return false
        if (array_get($item, 'menu_type') == MenuType::TABLE
            && array_get($item, 'table_name') == SystemTableName::ORGANIZATION
            && !System::organization_available()) {
            return false;
        }

        // a parent menu (a group of the menu) with role groups: only for the
        // members of one of them (the system administrators always see it)
        if (array_get($item, 'menu_type') == MenuType::PARENT_NODE && !$this->canSeeGroup($item)) {
            return false;
        }

        // a group of the feature map (Define::MENU_GROUP_DEFINITION) with nothing
        // in it to show: the menus of the features turned off are taken out of
        // the tree before this, and a template or a plugin may make the group
        // before anything goes in it. A parent an administrator made empty
        // still shows, as before.
        if (array_get($item, 'menu_type') == MenuType::PARENT_NODE
            && empty(array_get($item, 'children'))
            && array_key_exists(strval(array_get($item, 'menu_name')), Define::MENU_GROUP_DEFINITION)) {
            return false;
        }

        // if $item has children, get children's visible result.
        // if children have true result, return true;
        if (array_key_exists('children', $item)) {
            foreach (static::children($item) as $child) {
                if ($this->isVisible($child, $targetTables)) {
                    return true;
                }
            }
            return false;
        }

        // if has target tables.
        if (count($targetTables) > 0) {
            // if $item->menu_name is not contains $target_tables, return false
            if (!collect($targetTables)->first(function ($target_table) use ($item) {
                return array_get($item, 'table_name') == $target_table;
            })) {
                return false;
            }
        }

        // get permission for target endpoint
        $permissons = $user->allPermissions();

        if (!$permissons->first(function ($permission) use ($item) {
            return $permission->shouldPassEndpoint(array_get($item, 'uri'));
        })) {
            return false;
        }
        return true;
    }

    /**
     * Whether the user may see a parent menu: it has no role groups, or the
     * user is a system administrator or a member of one of them.
     *
     * @param array<string, mixed> $item
     */
    public function canSeeGroup(array $item): bool
    {
        if (!isset($this->user)) {
            return false;
        }
        $user = $this->user;
        $options = array_get($item, 'options');
        if (is_string($options)) {
            $options = json_decode_ex($options, true);
        }
        $ids = Menu::roleGroupIds(array_get($options, 'visible_role_groups'));
        if (count($ids) === 0 || $user->isAdministrator()) {
            return true;
        }
        $mine = System::requestSession('menu_group_role_group_ids_' . $user->getUserId(), function () use ($user) {
            $base = $user->base_user;
            if (!isset($base)) {
                return [];
            }
            return $base->belong_role_groups_all()->pluck('id')->map(function ($id) {
                return intval($id);
            })->all();
        });
        return count(array_intersect($ids, (array) $mine)) > 0;
    }
}
