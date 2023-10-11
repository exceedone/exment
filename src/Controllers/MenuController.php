<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
//use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Form;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Tree;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuController extends AdminControllerBase
{
    use HasResourceActions;
    use ExmentControllerTrait;

    public function __construct()
    {
        $this->setPageInfo(trans('admin.menu'), trans('admin.menu'), exmtrans('menu.description'), 'fa-sitemap');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        return
            $content->row(function (Row $row) {
                $row->column(5, $this->treeView()->render());

                $row->column(7, function (Column $column) {
                    $form = new \Encore\Admin\Widgets\Form();
                    $form->action(admin_url('auth/menu'));

                    $this->createMenuForm($form);
                    $form->hidden('_token')->default(csrf_token());

                    $column->append((new Box(trans('admin.new'), $form))->style('success'));
                });
            });
    }

    /**
     * Redirect to edit page.
     *
     * @param Request $request
     * @param Content $content
     * @param $id
     * @return Content|\Illuminate\Http\RedirectResponse
     */
    public function show(Request $request, Content $content, $id)
    {
        return redirect()->route('menu.edit', ['id' => $id]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        return $this->form($id)->update($id);
    }

    /**
     * @return \Encore\Admin\Tree
     */
    protected function treeView()
    {
        return Menu::tree(function (Tree $tree) {
            $tree->disableCreate();

            $tree->branch(function ($branch) {
                switch ($branch['menu_type']) {
                    case MenuType::PLUGIN:
                        $icon = null;
                        $uri = array_get($branch, 'uri');
                        break;
                    case MenuType::TABLE:
                        $icon = $branch['icon'];
                        $uri = isset($branch['uri']) ? $branch['uri'] : url_join('data', $branch['table_name']);
                        break;
                    case MenuType::SYSTEM:
                        $icon = $branch['icon'];
                        $uri = array_get(Define::MENU_SYSTEM_DEFINITION, "{$branch['menu_name']}.uri");
                        break;
                    case MenuType::PARENT_NODE:
                        $icon = $branch['icon'] ?? null;
                        $uri = null;
                        break;
                    default:
                        $icon = $branch['icon'] ?? null;
                        $uri = $branch['uri'] ?? null;
                        break;
                }

                // escape html
                $branch['title'] = esc_html($branch['title']);
                $payload = "<i class='fa {$icon}'></i>&nbsp;<strong>{$branch['title']}</strong>";

                if (!isset($branch['children'])) {
                    if (!url()->isValidUrl($uri)) {
                        $esc_uri = esc_html(trim(admin_base_path($uri), '/'));
                        $uri = admin_url($uri);
                    } else {
                        $esc_uri = esc_html($uri);
                    }

                    $payload .= "&nbsp;&nbsp;&nbsp;<a href=\"$uri\" class=\"dd-nodrag\">$esc_uri</a>";
                }

                return $payload;
            });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    public function form($id = null)
    {
        return Menu::form(function (Form $form) use ($id) {
            $this->createMenuForm($form, $id);
        });
    }

    protected function createMenuForm($form, $id = null)
    {
        // get setting menu object
        $menu = Menu::find($id);

        // set controller
        $contoller = $this;
        $form->select('parent_id', trans('admin.parent_id'))->options(Menu::selectOptions());
        $form->select('menu_type', exmtrans("menu.menu_type"))->options(MenuType::transArray("menu.menu_type_options"))
            ->load('menu_target', admin_url('webapi/menu/menutype'))
            ->required();

        $form->select('menu_target', exmtrans("menu.menu_target"))
            ->attribute(['data-changedata' => json_encode(
                ['getitem' =>
                    [  'uri' => admin_url('webapi/menu/menutargetvalue')
                        , 'key' => ['menu_type']
                    ]
                ]
            ), 'data-filter' => json_encode([
                'key' => 'menu_type', 'readonlyValue' => [MenuType::CUSTOM, MenuType::PARENT_NODE]
            ])])
            ->options(function ($value, $field, $model) use ($menu, $contoller) {
                // get menu type
                $menu_type = $contoller->getMenuTypeValue($field, $menu);

                if (!isset($menu_type)) {
                    return [];
                }

                // get model
                return $contoller->getMenuType($menu_type, false);
            })
            ->attribute([
                'data-linkage' => json_encode(['menu_target_view' => admin_url('webapi/menu/menutargetview')])
            ]);
        $form->select('menu_target_view', exmtrans("menu.menu_target_view"))
            ->attribute(['data-filter' => json_encode([
                'key' => 'menu_type', 'value' => [MenuType::TABLE]
            ])])
            ->options(function ($value, $field) use ($menu, $contoller) {
                $menu_type = $contoller->getMenuTypeValue($field, $menu);
                if ($menu_type != MenuType::TABLE) {
                    return [];
                }

                // check $value or $field->data()
                $custom_table = null;
                if (isset($value) && $value !== false) {
                    $custom_view = CustomView::getEloquent($value);
                    $custom_table = $custom_view ? $custom_view->custom_table : null;
                } elseif (!is_nullorempty($field->data())) {
                    $custom_table = CustomTable::getEloquent(array_get($field->data(), 'menu_target'));
                }

                if (!isset($custom_table)) {
                    return [];
                }

                return $contoller->getViewList($custom_table, false);
            })
        ;
        $form->text('uri', trans('admin.uri'))
            ->attribute(['data-filter' => json_encode([
                'key' => 'menu_type', 'readonlyValue' => [MenuType::SYSTEM, MenuType::PLUGIN, MenuType::TABLE, MenuType::PARENT_NODE]
            ])]);
        if (!isset($id)) {
            $form->text('menu_name', exmtrans("menu.menu_name"))
            ->required()
            ->rules(
                [
                    Rule::unique(config('admin.database.menu_table'))->ignore($id),
                    "max:40",
                    'regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/'
                ]
            )->help(exmtrans('common.help_code'));
        } else {
            $form->display('menu_name', exmtrans("menu.menu_name"));
        }
        $form->text('title', exmtrans("menu.title"))->required()->rules("max:40");
        $form->icon('icon', trans('admin.icon'))->required()->default('');
        $form->hidden('order');

        $form->saving(function ($form) {
            // whether set order
            $isset_order = false;
            // get parent id
            $parent_id = $form->parent_id;

            // get id
            $id = $form->model()->id;
            // if not set id(create), set order
            if (!isset($id)) {
                $isset_order = true;
            }
            // if set id(update), whether change parent id
            else {
                $model_parent_id = $form->model()->parent_id;
                $isset_order = ($model_parent_id != $parent_id);
            }

            // get same parent_id count
            if ($isset_order) {
                $query = Menu::where('parent_id', $parent_id);
                if (isset($id)) {
                    $query->where('id', '<>', $id);
                }
                $count = $query->count();
                // set order $count+1;
                $form->order = $count + 1;
            }
        });
    }

    // menu_type and menutargetvalue --------------------------------------------------

    // get menu type(calling from menu_type)
    public function menutype(Request $request)
    {
        $type = $request->input('q');
        return $this->getMenuType($type, true);
    }

    // get menu target view(calling from menu_target)
    public function menutargetview(Request $request)
    {
        $menu_target = $request->input('q');
        return $this->getViewList($menu_target, true);
    }

    /**
     * get view option array
     * @param string $menu_target string
     * @param boolean $isApi is api. if true, return id and value array. if false, return array(key:id, value:name)
     */
    /**
     * @param $custom_table
     * @param bool $isApi
     * @return array|mixed[]
     */
    protected function getViewList($custom_table, $isApi)
    {
        $custom_table = CustomTable::getEloquent($custom_table);
        if (!$custom_table) {
            return [];
        }

        $options = [];

        CustomView::where('view_type', ViewType::SYSTEM)
            ->where('custom_table_id', $custom_table->id)
            ->where('view_kind_type', '<>', ViewKindType::FILTER)->get()->each(function ($item) use (&$options) {
                $options[] = ['id' => $item->id, 'text' =>  $item->view_view_name ];
            });

        // if api, return
        if ($isApi) {
            return $options;
        }
        // if not api, return key:id, value:text array
        return collect($options)->pluck('text', 'id')->toArray();
    }

    /**
     * get menu type option array
     * @param string $type string
     * @param boolean $isApi is api. if true, return id and value array. if false, return array(key:id, value:name)
     */
    protected function getMenuType($type, $isApi)
    {
        $options = [];
        switch ($type) {
            case MenuType::SYSTEM:
                foreach (Define::MENU_SYSTEM_DEFINITION as $k => $value) {
                    if (!$this->isAddSystemMenuOptions($k, $value)) {
                        continue;
                    }
                    $options[] = ['id' => $k, 'text' => exmtrans("menu.system_definitions.".$k) ];
                }
                break;
            case MenuType::PLUGIN:
                $options = [];
                foreach (Plugin::getByPluginTypes(PluginType::PLUGIN_TYPE_SHOW_MENU()) as $value) {
                    $options[] = ['id' => $value->id, 'text' => $value->plugin_view_name];
                }
                break;
            case MenuType::TABLE:
                foreach (CustomTable::where('showlist_flg', true)->get() as $value) {
                    $options[] = ['id' => $value->id, 'text' => $value->table_view_name];
                }
                break;
        }

        // if api, return
        if ($isApi) {
            return $options;
        }
        // if not api, return key:id, value:text array
        return collect($options)->pluck('text', 'id')->toArray();
    }

    public function menutargetvalue(Request $request)
    {
        $type = $request->input('menu_type');
        $value = $request->input('value');
        switch ($type) {
            case MenuType::SYSTEM:
                $item = array_get(Define::MENU_SYSTEM_DEFINITION, $value);
                $result = [
                    'menu_name' => $value,
                    'title' => exmtrans("menu.system_definitions.".$value),
                    'icon' => array_get($item, 'icon'),
                    'uri' => array_get($item, 'uri'),
                ];
                break;
            case MenuType::PLUGIN:
                $item = Plugin::getEloquent($value);
                $result = [
                    'menu_name' => array_get($item, 'plugin_name'),
                    'title' => array_get($item, 'plugin_view_name'),
                    'icon' => array_get($item, 'options.icon'),
                    'uri' => $item->getRouteUri(),
                ];
                break;
            case MenuType::TABLE:
                $item = CustomTable::getEloquent($value);
                $result = [
                    'menu_name' => array_get($item, 'table_name'),
                    'title' => array_get($item, 'table_view_name'),
                    'icon' => array_get($item, 'options.icon'),
                    'uri' => array_get($item, 'table_name'),
                ];
                break;
            case MenuType::CUSTOM:
                $result = [
                    'menu_name' => '',
                    'title' => '',
                    'icon' => '',
                    'uri' => '',
                ];
                break;
            case MenuType::PARENT_NODE:
                $result = [
                    'menu_name' => '',
                    'title' => '',
                    'icon' => '',
                    'uri' => '#',
                ];
                break;
        }

        if (!isset($result) || is_nullorempty(array_get($result, 'menu_name'))) {
            return [];
        }


        // check same menu name
        $menu_name_base = array_get($result, 'menu_name');
        $menu_name = $menu_name_base;
        $menus = Menu::all();

        for ($i = 1; $i < 1000; $i++) {
            if (!$menus->contains(function ($menu) use ($menu_name) {
                return $menu_name == $menu->menu_name;
            })) {
                $result['menu_name'] = $menu_name;
                return $result;
            }

            $menu_name = "{$menu_name_base}_{$i}";
        }

        return $result;
    }

    /**
     * Whether is add system menu options
     *
     * @param string $k
     * @param mixed $value
     * @return boolean
     */
    protected function isAddSystemMenuOptions($k, $value)
    {
        if ($k == 'role_group') {
            return System::permission_available();
        } elseif ($k == 'api_setting') {
            return System::api_available();
        } elseif ($k == 'notify') {
            return false;
        }

        return true;
    }


    protected function getMenuTypeValue($field, $menu = null)
    {
        // get menu type
        $menu_type = null;
        if ($field) {
            $menu_type = array_get($field->data(), 'menu_type');
        }
        if (!$menu_type && request()->has('menu_type')) {
            $menu_type = request()->get('menu_type');
        }
        if (!$menu_type && isset($menu)) {
            $menu_type = array_get($menu, 'menu_type');
        }

        return $menu_type;
    }
}
