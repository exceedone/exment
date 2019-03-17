<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\MenuType;
use Encore\Admin\Auth\Database\Menu as AdminMenu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class Menu.
 *
 * @property int $id
 *
 * @method where($parent_id, $id)
 */
class Menu extends AdminMenu implements Interfaces\TemplateImporterInterface
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    
    /**
     * @var string
     */
    protected $titleColumn = 'title';

    protected static $templateItems = [
        'excepts' => ['id', 'menu_target', 'parent_id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['menu_type', 'menu_name'],
        'langs' => ['title'],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacedName' => [
                            'parent_name' => 'parent_name',
                            'menu_target_name' => 'menu_target_name',
                            'uri' => 'uri',
                        ]
                    ]
                ],
                'uniqueKeyFunction' => 'getUniqueKeyValues',
            ],
        ]
    ];

    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * @return array
     */
    public function allNodes() : array
    {
        $orderColumn = DB::getQueryGrammar()->wrap($this->orderColumn);
        $byOrder = "m.$orderColumn = 0, m.$orderColumn";

        // get all menu, custom table, plugin table.
        $query = DB::table("{$this->getTable()} as m")
            // join table
            ->leftJoin(CustomTable::getTableName()." as c", function ($join) {
                $join->where("m.menu_type", MenuType::TABLE);
                $join->on("m.menu_target", "c.id");
            })
            // join plugin
            ->leftJoin(Plugin::getTableName()." as p", function ($join) {
                $join->where("m.menu_type", MenuType::PLUGIN);
                $join->on("m.menu_target", "p.id");
            })
            ->orderByRaw($byOrder);

        //->map(function ($item, $key) {
        //    return (array) $item;
        //})
        //->all();
        ;
        $rows = $query->get(['m.*',
                'c.id AS custom_table_id',
                'c.table_name',
                'c.table_view_name',
                'c.options AS table_options',
                'p.id AS plugin_id',
                'p.plugin_name'])->map(function ($item, $key) {
                    return (array) $item;
                })
        ->all();

        foreach ($rows as &$row) {
            switch ($row['menu_type']) {
                case MenuType::PLUGIN:
                    //$row['icon'] = null;
                    $row['uri'] = 'plugins/'.$row['uri'];;
                    break;
                case MenuType::TABLE:
                    if (is_nullorempty($row['icon'])) {
                        $table_options = json_decode(array_get($row, 'table_options'), true);
                        $row['icon'] = array_get($table_options, 'icon');
                    }
                    $row['uri'] = 'data/'.$row['table_name'];
                    break;
                case MenuType::SYSTEM:
                    $defines = array_get(Define::MENU_SYSTEM_DEFINITION, $row['menu_name']);
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
        }

        return $rows;
    }

    /**
     * import template
     */
    public static function importTemplate($menu, $options = [])
    {
        // Create menu. --------------------------------------------------
        $hasname = array_get($options, 'hasname');

        // get parent id
        $parent_id = null;
        // get parent id from parent_name
        if (array_key_exists('parent_name', $menu)) {
            // if $hasname is 0, $menu['parent_name'] is not null(not root) then continue
            if ($hasname == 0 && !is_null($menu['parent_name'])) {
                return null;
            }
            // if $hasname is 1, $menu['parent_name'] is null(root) then continue
            elseif ($hasname == 1 && is_null($menu['parent_name'])) {
                return null;
            }

            $parent = static::where('menu_name', $menu['parent_name'])->first();
            if (isset($parent)) {
                $parent_id = $parent->id;
            }
        }
        if (is_null($parent_id)) {
            $parent_id = 0;
        }

        // set title
        if (array_key_value_exists('title', $menu)) {
            $title = array_get($menu, 'title');
        }
        // title not exists, translate
        else {
            $translate_key = array_key_value_exists('menu_target_name', $menu) ? array_get($menu, 'menu_target_name') : array_get($menu, 'menu_name');
            $title = exmtrans('menu.system_definitions.'.$translate_key);
        }

        $menu_type = MenuType::getEnumValue(array_get($menu, 'menu_type'));
        $obj_menu = static::firstOrNew(['menu_name' => array_get($menu, 'menu_name'), 'parent_id' => $parent_id]);
        $obj_menu->menu_type = $menu_type;
        $obj_menu->menu_name = array_get($menu, 'menu_name');
        $obj_menu->title = $title;
        $obj_menu->parent_id = $parent_id;

        // get menu target id
        if (isset($menu['menu_target_id'])) {
            $obj_menu->menu_target = $menu['menu_target_id'];
        }
        // get menu target id from menu_target_name
        elseif (isset($menu['menu_target_name'])) {
            // case plugin or table
            switch ($menu_type) {
                case MenuType::PLUGIN:
                    $parent = Plugin::where('plugin_name', $menu['menu_target_name'])->first();
                    if (isset($parent)) {
                        $obj_menu->menu_target = $parent->id;
                    }
                    break;
                case MenuType::TABLE:
                    $parent = CustomTable::getEloquent($menu['menu_target_name']);
                    if (isset($parent)) {
                        $obj_menu->menu_target = $parent->id;
                    }
                    break;
                case MenuType::SYSTEM:
                    $menus = collect(Define::MENU_SYSTEM_DEFINITION)->filter(function ($system_menu, $key) use ($menu) {
                        return $key == $menu['menu_target_name'];
                    })->each(function ($system_menu, $key) use ($obj_menu) {
                        $obj_menu->menu_target = $key;
                    });
                    break;
            }
        }

        // get order
        if (isset($menu['order'])) {
            $obj_menu->order = $menu['order'];
        } else {
            $obj_menu->order = static::where('parent_id', $obj_menu->parent_id)->max('order') + 1;
        }

        ///// icon
        if (isset($menu['icon'])) {
            $obj_menu->icon = $menu['icon'];
        }
        // else, get icon from table, system, etc
        else {
            switch ($obj_menu->menu_type) {
                case MenuType::SYSTEM:
                    $obj_menu->icon = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".icon");
                    break;
                case MenuType::TABLE:
                    $obj_menu->icon = CustomTable::getEloquent($obj_menu->menu_name)->icon ?? null;
                    break;
            }
        }
        if (is_null($obj_menu->icon)) {
            $obj_menu->icon = '';
        }

        ///// uri
        if (isset($menu['uri'])) {
            $obj_menu->uri = $menu['uri'];
        }
        // else, get icon from table, system, etc
        else {
            switch ($obj_menu->menu_type) {
                case MenuType::SYSTEM:
                    $obj_menu->uri = array_get(Define::MENU_SYSTEM_DEFINITION, $obj_menu->menu_name.".uri");
                    break;
                case MenuType::TABLE:
                    $obj_menu->uri = $obj_menu->menu_name;
                    break;
                case MenuType::TABLE:
                    $obj_menu->uri = '#';
                    break;
            }
        }

        $obj_menu->saveOrFail();

        return $obj_menu;
    }

    /**
     * get Table And Column Name
     */
    protected function getUniqueKeyValues()
    {
        // add item
        // replace id to name
        //get parent name
        if (!isset($this['parent_id']) || $this['parent_id'] == '0') {
            $parent_name = null;
        } else {
            $parent_id = $this['parent_id'];
            $menulist = (new Menu)->allNodes(); // allNodes:dimensional
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
        }else{
            $uri = $this['uri'];
        }

        return [
            'parent_name' => $parent_name,
            'menu_target_name' => $menu_target_name,
            'uri' => $uri,
        ];
        

        // if has children, loop
        if (array_key_value_exists('children', $menu)) {
            foreach (array_get($menu, 'children') as $child) {
                // set children menu item recursively to $menus.
                $menus = array_merge($menus, static::getTemplateMenuItems($child, $target_tables, $menulist));
            }
        }
        return $menus;
    }
    
    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        static::treeBoot();
    }
}
