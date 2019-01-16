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
class Menu extends AdminMenu
{
    use Traits\UseRequestSessionTrait;
    
    /**
     * @var string
     */
    protected $titleColumn = 'title';

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
        $byOrder = $orderColumn.' = 0,'.$orderColumn;

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
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function boot()
    {
        static::treeBoot();
    }
}
