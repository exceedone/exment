<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Query\Grammars\SqlServerGrammar;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\TemplateImportResult;
use Exceedone\Exment\Services\MenuTitle;
use Exceedone\Exment\Services\MenuVisibility;
use ExmentAdminCore\Admin\Auth\Database\Menu as AdminMenu;
use Illuminate\Support\Facades\DB;

/**
 * Class Menu.
 *
 * @property int $id
 * @property mixed $icon
 * @property mixed $menu_name
 * @property mixed $menu_type
 * @property mixed $parent_id
 * @property mixed $title
 * @property mixed $uri
 *
 * @method where($parent_id, $id)
 * @phpstan-consistent-constructor
 */
class Menu extends AdminMenu implements Interfaces\TemplateImporterInterface
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\SerializeDateTrait;
    use Traits\NameLocalesTrait;

    /**
     * @var string
     */
    protected $titleColumn = 'title';

    protected $appends = ['menu_target_view', 'visible_role_groups', 'name_locales'];
    protected $casts = ['options' => 'json'];

    /**
     * The parent only declares the laravel-admin columns, so a Menu::create()
     * would silently drop the columns that decide what the menu points at and
     * leave an unusable row behind.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id', 'order', 'title', 'icon', 'uri', 'permission',
        'menu_type', 'menu_name', 'menu_target', 'options',
    ];


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => [
            'import' => ['permission'],
            'export' => ['menu_target', 'permission', 'menu_target_view', 'visible_role_groups', 'name_locales'],
        ],
        'uniqueKeys' => ['menu_type', 'menu_name'],
        'langs' => [
            'keys' => ['menu_type', 'menu_name'],
            'values' => ['title'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'parent_id',
                        'replacedName' => [
                            'parent_name' => 'parent_name',
                            'menu_target_name' => 'menu_target_name',
                            'uri' => 'uri',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.menu_target_view',
                        'replacedName' => [
                            'suuid' => 'options.menu_target_view_suuid',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomView::class,
            ],
        ]
    ];

    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     */

    // @phpstan-ignore-next-line
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }


    // @phpstan-ignore-next-line
    public function getMenuTargetViewAttribute()
    {
        return $this->getOption('menu_target_view', false);
    }


    // @phpstan-ignore-next-line
    public function setMenuTargetViewAttribute($value)
    {
        return $this->setOption('menu_target_view', $value);
    }

    /**
     * The role groups a parent menu (a group of the menu) is shown to; empty
     * shows it to everyone, as before.
     *
     * @return array<int, int>
     */
    public function getVisibleRoleGroupsAttribute(): array
    {
        return static::roleGroupIds($this->getOption('visible_role_groups', []));
    }

    /**
     * @param mixed $value
     * @return void
     */
    public function setVisibleRoleGroupsAttribute($value)
    {
        $ids = static::roleGroupIds($value);
        $this->setOption('visible_role_groups', count($ids) > 0 ? $ids : null);
    }

    /**
     * @param mixed $value ids as the form or the options give them
     * @return array<int, int>
     */
    public static function roleGroupIds($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (is_numeric($id) && intval($id) > 0) {
                $ids[] = intval($id);
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Export: the role groups of a parent menu travel by their name, not by
     * their id (on another site the same id is another role group or none).
     *
     * @param array<mixed> $json
     * @param-out array<mixed> $json
     * @return void
     */
    public static function exportReplaceJson(&$json)
    {
        $ids = static::roleGroupIds(array_get($json, 'options.visible_role_groups'));
        array_forget($json, 'options.visible_role_groups');
        if (count($ids) > 0) {
            $names = RoleGroup::whereIn('id', $ids)->pluck('role_group_name')->map(function ($name) {
                return strval($name);
            })->values()->all();
            array_set($json, 'options.visible_role_group_names', $names);
        }
    }

    /**
     * Import: the names of the role groups back to the ids of this site; a
     * name this site does not have is dropped.
     *
     * @param array<mixed> $json
     * @param-out array<mixed> $json
     * @return void
     */
    protected static function importVisibleRoleGroups(&$json)
    {
        $names = array_get($json, 'options.visible_role_group_names');
        array_forget($json, 'options.visible_role_group_names');
        array_forget($json, 'options.visible_role_groups');
        if (!is_array($names) || count($names) === 0) {
            return;
        }
        $ids = RoleGroup::whereIn('role_group_name', $names)->pluck('id')->map(function ($id) {
            return intval($id);
        })->values()->all();
        if (count($ids) > 0) {
            array_set($json, 'options.visible_role_groups', $ids);
        }
    }


    // @phpstan-ignore-next-line
    public static function getTableName()
    {
        return (new static())->getTable();
    }

    /**
     * The language of the site (APP_LOCALE), also when an update runs from
     * the command line (where the locale of Laravel may be another one).
     */
    public static function siteLocale(): string
    {
        // exment.locale is APP_LOCALE (config/exment.php)
        $locale = config('exment.locale');
        return is_string($locale) && $locale !== '' ? $locale : strval(app()->getLocale());
    }

    /**
     * The id of a group of the left menu (Define::MENU_GROUP_DEFINITION), made
     * at the top level (in the order of the feature map) when the site does not
     * have it yet. For the bundled templates and the official plugins, which
     * put their menus under their group:
     *
     *   if (method_exists(Menu::class, 'groupParentId')) {
     *       $parentId = Menu::groupParentId('project');
     *   }
     *
     * A name that is not a group returns null (and makes nothing).
     */
    public static function groupParentId(string $name): ?int
    {
        $define = array_get(Define::MENU_GROUP_DEFINITION, $name);
        if (!is_array($define)) {
            return null;
        }
        $table = (new static())->getTable();
        $id = DB::table($table)->where('menu_type', MenuType::PARENT_NODE)->where('menu_name', $name)->value('id');
        if (isset($id)) {
            return intval($id);
        }
        return intval(DB::table($table)->insertGetId([
            'parent_id' => 0,
            'order' => $define['order'],
            'title' => static::systemTitle($name),
            'icon' => $define['icon'],
            'uri' => null,
            'menu_type' => MenuType::PARENT_NODE,
            'menu_name' => $name,
            'menu_target' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * The title of a system menu in the language of the site, for a menu an
     * update adds.
     */
    /**
     * Put a system menu right after another one under the same parent (the security check
     * after Tuning): the ones after it move down by one. Nothing when either is missing or
     * they are under different parents (an administrator moved one).
     */
    public static function placeSystemMenuAfter(string $menuName, string $afterName): void
    {
        $table = config('admin.database.menu_table', 'admin_menu');
        $menu = \DB::table($table)->where('menu_type', 'system')->where('menu_name', $menuName)->first();
        $after = \DB::table($table)->where('menu_type', 'system')->where('menu_name', $afterName)->first();
        if (!isset($menu, $after) || intval($menu->parent_id) !== intval($after->parent_id)) {
            return;
        }
        $order = intval($after->order);
        if (intval($menu->order) === $order + 1) {
            return;
        }
        \DB::table($table)->where('parent_id', $after->parent_id)->where('id', '<>', $menu->id)->where('order', '>', $order)->increment('order');
        \DB::table($table)->where('id', $menu->id)->update(['order' => $order + 1]);
    }

    public static function systemTitle(string $menuName): string
    {
        // a language without the name: the one of Laravel, then English, then the name of the menu
        $key = 'exment::exment.menu.system_definitions.' . $menuName;
        foreach (array_unique([static::siteLocale(), strval(app()->getLocale()), 'en']) as $locale) {
            $title = trans($key, [], $locale);
            if (is_string($title) && $title !== '' && !str_starts_with($title, 'exment::')) {
                return $title;
            }
        }
        return $menuName;
    }

    /**
     * Whether the menus of the features not used are given too (marked
     * "feature_hidden"): for editing the menus and exporting a template,
     * not for the menu of the pages.
     */
    protected bool $withHidden = false;

    /**
     * @return $this
     */
    public function withHidden(bool $withHidden = true)
    {
        $this->withHidden = $withHidden;
        return $this;
    }

    /**
     * The menus the login user has (Services\MenuVisibility decides: Feature
     * management, demo mode). The permissions are checked when the tree is
     * shown (MenuVisibility::tree()).
     *
     * @return array
     */

    // @phpstan-ignore-next-line
    public function allNodes(): array
    {
        $rows = MenuVisibility::rows(function () {
            return $this->getAllNodesFromDatabase();
        });
        $nodes = MenuVisibility::nodes($rows, $this->withHidden);
        // the titles of the menus Exment made, in the language of the user (the rows are the same
        // for every user, and may come from the cache). Not for the export of a template and the
        // import, which need the stored texts.
        return $this->withHidden ? $nodes : MenuTitle::localizeRows($nodes);
    }

    /**
     * The menus as the database has them, with their uri and icon: the same
     * for every user (MenuVisibility::rows() may cache them). A menu of a
     * table or a plugin that is not there any more is left out.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllNodesFromDatabase(): array
    {
        $grammar = DB::getQueryGrammar();
        $orderColumn = $grammar->wrap($this->orderColumn);

        // get column
        // if SqlServer, needs cast
        if ($grammar instanceof \Illuminate\Database\Query\Grammars\SqlServerGrammar) {
            /** @var SqlServerGrammar $grammar */
            $tableQuery = $grammar->getCastColumn(DatabaseDataType::TYPE_STRING, 'c.id');
            $pluginQuery = $grammar->getCastColumn(DatabaseDataType::TYPE_STRING, 'p.id');
        } else {
            $tableQuery = $grammar->wrap('c.id');
            $pluginQuery = $grammar->wrap('p.id');
        }

        // get all menu, custom table, plugin table.
        $query = DB::table("{$this->getTable()} as m")
            // join table
            ->leftJoin(CustomTable::getTableName() . " as c", function ($join) use ($tableQuery) {
                $join->where("m.menu_type", MenuType::TABLE);
                $join->whereRaw("m.menu_target = " . $tableQuery);
            })
            // join plugin
            ->leftJoin(Plugin::getTableName() . " as p", function ($join) use ($pluginQuery) {
                $join->where("m.menu_type", MenuType::PLUGIN);
                $join->whereRaw("m.menu_target = " . $pluginQuery);
            })
            ->orderByRaw("CASE WHEN m.$orderColumn = 0 THEN 1 ELSE 0 END")
            ->orderByRaw("m.$orderColumn")
            // the same order: as they were made (the database may answer the ties in any order)
            ->orderBy('m.id');

            //->map(function ($item, $key) {
            //    return (array) $item;
            //})
            //->all();
        ;
        $rows = $query->get([
            'm.*',
            'c.id AS custom_table_id',
            'c.table_name',
            'c.table_view_name',
            'c.options AS table_options',
            'p.id AS plugin_id',
            'p.plugin_name'
        ])->map(function ($item, $key) {
            return (array) $item;
        })
            ->all();

        $results = [];
        foreach ($rows as &$row) {
            $result = true;
            if (isset($row['options'])) {
                $row['options'] = json_decode_ex($row['options'], true);
            }
            switch ($row['menu_type']) {
                case MenuType::PLUGIN:
                    $plugin = Plugin::getEloquent($row['menu_target']);
                    if (!isset($plugin)) {
                        $result = false;
                        break;
                    }
                    $row['uri'] = $plugin->getRouteUri();
                    // for MenuVisibility::hiddenByFeatures(): the menu switch of the plugin
                    $row['plugin_name'] = $plugin->plugin_name;
                    break;
                case MenuType::TABLE:
                    if (!isset($row['table_name'])) {
                        $result = false;
                        break;
                    }
                    if (is_nullorempty($row['icon'])) {
                        $table_options = json_decode_ex(array_get($row, 'table_options'), true);

                        // @phpstan-ignore-next-line
                        $row['icon'] = array_get($table_options, 'icon');
                    }
                    $row['uri'] = 'data/' . $row['table_name'];

                    if (!is_null($view_id = array_get($row, 'options.menu_target_view'))) {
                        $view = CustomView::getEloquent($view_id);
                        if (!is_nullorempty($view)) {
                            $view_suuid = $view->suuid;
                            $row['uri'] .= '?view=' . $view_suuid;
                        }
                    }
                    break;
                case MenuType::SYSTEM:
                    // a system menu row may have only its menu_name
                    $defines = array_get(\Exceedone\Exment\Services\CoreExtensions::systemMenus(), array_get($row, 'menu_target') ?: $row['menu_name']);
                    // if not set menu icon, set Define's default icon.
                    if (is_nullorempty($row['icon'])) {
                        $row['icon'] = array_get($defines, 'icon');
                    }
                    $row['uri'] = array_get($defines, "uri");
                    break;
                case MenuType::PARENT_NODE:
                    $row['uri'] = null;
                    break;
                default:
                    break;

                    // database-row has icon column, set icon
            }

            if (!$result) {
                continue;
            }
            $results[] = $row;
        }

        return $results;
    }


    // @phpstan-ignore-next-line
    public static function importReplaceJson(&$json, $options = [])
    {
        static::importVisibleRoleGroups($json);

        // Create menu. --------------------------------------------------
        $hasname = array_get($options, 'hasname');

        // get parent id
        $parent_id = 0;
        // get parent id from parent_name
        if (array_key_value_exists('parent_name', $json)) {
            // if $hasname is 0, $json['parent_name'] is not null(not root) then continue
            if ($hasname == 0 && !is_null($json['parent_name'])) {
                return TemplateImportResult::CONITNUE;
            }
            // if $hasname is 1, $json['parent_name'] is null(root) then continue
            elseif ($hasname == 1 && is_null($json['parent_name'])) {
                return TemplateImportResult::CONITNUE;
            }

            $parent = static::where('menu_name', $json['parent_name'])->first();
            if (isset($parent)) {
                $parent_id = $parent->id;
            }
        }
        $json['parent_id'] = $parent_id;
        array_forget($json, 'parent_name');

        // convert menu type
        $json['menu_type'] = MenuType::getEnumValue($json['menu_type']);

        if (isset($json['menu_target_name'])) {
            // case plugin or table
            switch ($json['menu_type']) {
                case MenuType::PLUGIN:
                    $parent = Plugin::getEloquent($json['menu_target_name']);
                    if (isset($parent)) {
                        $json['menu_target'] = $parent->id;
                    }
                    break;
                case MenuType::TABLE:
                    $parent = CustomTable::getEloquent($json['menu_target_name']);
                    if (isset($parent)) {
                        $json['menu_target'] = $parent->id;
                    }
                    break;
                case MenuType::SYSTEM:
                    $menus = collect(\Exceedone\Exment\Services\CoreExtensions::systemMenus())->filter(function ($system_menu, $key) use (&$json) {
                        return $key == $json['menu_target_name'];
                    })->each(function ($system_menu, $key) use (&$json) {
                        $json['menu_target'] = $key;
                    });
                    break;
            }
        }
        array_forget($json, 'menu_target_name');

        // get order
        if (!isset($json['order'])) {
            $json['order'] = static::where('parent_id', $json['parent_id'])->max('order') + 1;
        }

        ///// icon
        if (!isset($json['icon'])) {
            switch ($json['menu_type']) {
                case MenuType::SYSTEM:
                    $json['icon'] = array_get(\Exceedone\Exment\Services\CoreExtensions::systemMenus(), $json['menu_name'] . ".icon");
                    break;
                case MenuType::TABLE:
                    $json['icon'] = array_get(CustomTable::getEloquent($json['menu_name']), 'options.icon');
                    break;
            }
        }
        if (!isset($json['icon'])) {
            $json['icon'] = '';
        }

        ///// uri
        // a link to a dashboard of the template (its suuid is made on the import): the dashboard
        // by its name ("options.menu_target_dashboard_name", the dashboards are imported first)
        $dashboardName = array_get($json, 'options.menu_target_dashboard_name');
        if ($json['menu_type'] == MenuType::CUSTOM && is_string($dashboardName) && $dashboardName !== '') {
            $dashboard = Dashboard::where('dashboard_name', $dashboardName)->first();
            if (!isset($dashboard)) {
                return TemplateImportResult::CONITNUE;
            }
            $json['uri'] = '?dashboard=' . $dashboard->suuid;
        }
        if (!isset($json['uri'])) {
            switch ($json['menu_type']) {
                case MenuType::SYSTEM:
                    $json['uri'] = array_get(\Exceedone\Exment\Services\CoreExtensions::systemMenus(), $json['menu_name'] . ".uri");
                    break;
                case MenuType::TABLE:
                    $json['uri'] = $json['menu_name'];
                    break;
                case MenuType::PARENT_NODE:
                    $json['uri'] = '#';
                    break;
            }
        }

        // (v2.0) replace "role" to "role_group"
        if (array_get($json, 'menu_type') == MenuType::SYSTEM && array_get($json, 'menu_name') == 'role') {
            $json['uri'] = 'role_group';
            $json['menu_name'] = 'role_group';
            $json['title'] = exmtrans('menu.system_definitions.role_group');
        }
    }

    /**
     * get Table And Column Name
     */

    // @phpstan-ignore-next-line
    protected function getUniqueKeyValues()
    {
        // add item
        // replace id to name
        //get parent name
        if (!isset($this['parent_id']) || $this['parent_id'] == '0') {
            $parent_name = null;
        } else {
            $parent_id = $this['parent_id'];
            $menulist = (new Menu())->withHidden()->allNodes(); // allNodes:dimensional
            $parent = collect($menulist)->first(function ($value, $key) use ($parent_id) {
                return array_get($value, 'id') == $parent_id;
            });
            $parent_name = isset($parent) ? array_get($parent, 'menu_name') : null;
        }

        // menu_target
        $menu_type = $this['menu_type'];
        if (MenuType::TABLE == $menu_type) {
            $menu_target_name = CustomTable::getEloquent($this['menu_target'])->table_name ?? null;
        } elseif (MenuType::PLUGIN == $menu_type) {
            $menu_target_name = Plugin::getEloquent($this['menu_target'])->plugin_name;
        } elseif (MenuType::SYSTEM == $menu_type) {
            $menu_target_name = $this['menu_name'];
        }
        // custom, parent_node
        else {
            $menu_target_name = $this['menu_target'];
        }

        //// url
        // menu type is table, remove uri "data/"
        if (MenuType::TABLE == $menu_type) {
            $uri = preg_replace('/^data\//', '', $this['uri']);
        } else {
            $uri = $this['uri'];
        }

        return [
            'parent_name' => $parent_name,
            'menu_target_name' => $menu_target_name,
            'uri' => $uri,
        ];
    }

    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        static::treeBoot();

        // the rows of the menu, when a site caches them (MenuVisibility::cacheRowsWith())
        static::saved(function ($model) {
            MenuVisibility::forgetRows();
        });
        static::deleted(function ($model) {
            MenuVisibility::forgetRows();
        });
    }
}
