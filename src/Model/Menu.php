<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\TemplateImportResult;
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

    public static $templateItems = [
        'excepts' => [
            'import' => ['permission'],
            'export' => ['menu_target', 'permission'],
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

    public static function importReplaceJson(&$json, $options = []){
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
                    $parent = Plugin::where('plugin_name', $json['menu_target_name'])->first();
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
                    $menus = collect(Define::MENU_SYSTEM_DEFINITION)->filter(function ($system_menu, $key) use (&$json) {
                        return $key == $json['menu_target_name'];
                    })->each(function ($system_menu, $key) use(&$json) {
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
                    $json['icon'] = array_get(Define::MENU_SYSTEM_DEFINITION, $json['menu_name'].".icon");
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
        if (!isset($json['uri'])) {
            switch ($json['menu_type']) {
                case MenuType::SYSTEM:
                    $json['uri'] = array_get(Define::MENU_SYSTEM_DEFINITION, $json['menu_name'].".uri");
                    break;
                case MenuType::TABLE:
                    $json['uri'] = $json['menu_name'];
                    break;
                case MenuType::TABLE:
                    $json['uri'] = '#';
                    break;
            }
        }
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
        } else {
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
