<?php

namespace Exceedone\Exment\Model;

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
                $join->where("m.menu_type", Define::MENU_TYPE_TABLE);
                $join->on("m.menu_target", "c.id");
            })
            // join plugin
            ->leftJoin(Plugin::getTableName()." as p", function ($join) {
                $join->where("m.menu_type", Define::MENU_TYPE_PLUGIN);
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
                'c.icon AS table_icon',
                'p.id AS plugin_id',
                'p.plugin_name'])->map(function ($item, $key) {
                    return (array) $item;
                })
        ->all();

        foreach ($rows as &$row) {
            switch ($row['menu_type']) {
                case Define::MENU_TYPE_PLUGIN:
                    //$row['icon'] = null;
                    $row['uri'] = 'plugins/'.$row['uri'];;
                    break;
                case Define::MENU_TYPE_TABLE:
                    $row['icon'] = array_get($row, 'table_icon');
                    $row['uri'] = 'data/'.$row['table_name'];
                    break;
                case Define::MENU_TYPE_SYSTEM:
                    $defines = array_get(Define::MENU_SYSTEM_DEFINITION, $row['menu_name']);
                    // if not set menu icon, set Define's default icon.
                    if (is_nullorempty($row['icon'])) {
                        $row['icon'] = array_get($defines, 'icon');
                    }
                    $row['uri'] = array_get($defines, "uri");
                    break;
                default:
                    $row['uri'] = null;
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
