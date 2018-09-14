<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Menu;
use Encore\Admin\Controllers\ModelForm;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Tree;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;

class MenuController extends AdminControllerBase
{
    use ModelForm, ExmentControllerTrait;

    public function __construct(Request $request){
        $this->setPageInfo(trans('admin.menu'), trans('admin.menu'), trans('admin.list'));  
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Content $content)
    {
        return $this->AdminContent(function (Content $content) {
            $content->row(function (Row $row) {
                $row->column(5, $this->treeView()->render());

                $row->column(7, function (Column $column) {
                    $form = new \Encore\Admin\Widgets\Form();
                    $form->action(admin_base_path('auth/menu'));

                    $this->createMenuForm($form);
                    //$form->select('parent_id', trans('admin.parent_id'))->options(Menu::selectOptions());
                    //$form->text('menu_name', trans('admin.title'))->rules('required');
                    //$form->icon('icon', trans('admin.icon'))->default('fa-bars')->rules('required')->help($this->iconHelp());
                    //$form->text('uri', trans('admin.uri'));
                    //$form->multipleSelect('roles', trans('admin.roles'))->options(Role::all()->pluck('name', 'id'));
                    $form->hidden('_token')->default(csrf_token());

                    $column->append((new Box(trans('admin.new'), $form))->style('success'));
                });
            });
        });
    }

    /**
     * Redirect to edit page.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function show($id)
    {
        return redirect()->route('menu.edit', ['id' => $id]);
    }

    /**
     * @return \Encore\Admin\Tree
     */
    protected function treeView()
    {
        return Menu::tree(function (Tree $tree) {
            $tree->disableCreate();

            $tree->branch(function ($branch) {

                switch($branch['menu_type']){
                    case Define::MENU_TYPE_PLUGIN;
                        $icon = null;
                        $uri = null;
                        break;
                    case Define::MENU_TYPE_TABLE;
                        $icon = $branch['icon'];
                        $uri = $branch['table_name'];
                        break;
                    case Define::MENU_TYPE_SYSTEM;
                        $icon = $branch['icon'];
                        $uri = array_get(Define::MENU_SYSTEM_DEFINITION, "{$branch['menu_name']}.uri");
                        break;
                    default:
                        $icon = null;
                        $uri = null;
                        break;
                }
                $payload = "<i class='fa {$icon}'></i>&nbsp;<strong>{$branch['title']}</strong>";

                if (!isset($branch['children'])) {
                    if (!url()->isValidUrl($uri)) {
                        $uri = admin_base_path($uri);
                    }

                    $payload .= "&nbsp;&nbsp;&nbsp;<a href=\"$uri\" class=\"dd-nodrag\">$uri</a>";
                }

                return $payload;
            });
        });
    }

    /**
     * Edit interface.
     *
     * @param string $id
     *
     * @return Content
     */
    public function edit($id)
    {
        return $this->AdminContent(function (Content $content) use ($id) {
            $content->row($this->form()->edit($id));
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    public function form()
    {
        return Menu::form(function (Form $form) {
            $this->createMenuForm($form);
        });
    }

    public function menutype(Request $request){
        $type = $request->input('q');
        switch($type){
            case Define::MENU_TYPE_SYSTEM:
                $options = [];
                foreach (Define::MENU_SYSTEM_DEFINITION as $k => $value)
                {
                    array_push($options, ['id' => $k, 'text' => exmtrans("menu.menu_system_definitions.".$k ) ]);
                }
                return $options;
            case Define::MENU_TYPE_PLUGIN:
                $options = [];
                foreach (Plugin::where('plugin_type', 'page')->get() as $value)
                {
                    array_push($options, ['id' => $value->id, 'text' => $value->plugin_view_name]);
                }
                return $options;
            case Define::MENU_TYPE_TABLE:
                $options = [];
                foreach (CustomTable::all() as $value)
                {
                    array_push($options, ['id' => $value->id, 'text' => $value->table_view_name]);
                }
                return $options;
        }

        return [];
    }

    public function menutargetvalue(Request $request){
        $type = $request->input('menu_type');
        $value = $request->input('value');
        switch($type){
            case Define::MENU_TYPE_SYSTEM:          
                $item = array_get(Define::MENU_SYSTEM_DEFINITION, $value);
                return [
                    'menu_name' => $value,
                    'title' => exmtrans("menu.menu_system_definitions.".$value ),
                    'icon' => array_get($item, 'icon'),
                    'uri' => array_get($item, 'uri'),
                ];  
            case Define::MENU_TYPE_PLUGIN:
                $item = Plugin::find($value);
                return [
                    'menu_name' => array_get($item, 'plugin_name'),
                    'title' => array_get($item, 'plugin_view_name'),
                    'icon' => array_get($item, 'icon'),
                    'uri' => array_get($item, 'options.uri'),
                ];  
                return Plugin::find($value);
            case Define::MENU_TYPE_TABLE:
                    $item = CustomTable::find($value);
                        return [
                            'menu_name' => array_get($item, 'table_name'),
                            'title' => array_get($item, 'table_view_name'),
                            'icon' => array_get($item, 'icon'),
                            'uri' => array_get($item, 'table_name'),
                        ];  
            case Define::MENU_TYPE_CUSTOM:
                    return [
                        'menu_name' => '',
                        'title' => '',
                        'icon' => '',
                        'uri' => '',
                    ];  
        }

        return [];
    }

    protected function createMenuForm($form){
        $form->select('parent_id', trans('admin.parent_id'))->options(Menu::selectOptions());
        $form->select('menu_type', exmtrans("menu.menu_type"))->options(getTransArray(Define::MENU_TYPES, "menu.menu_type_options"))
            ->load('menu_target', admin_base_path('api/menu/menutype'))
            ->rules('required');
        $form->select('menu_target', exmtrans("menu.menu_target"))
            ->attribute(['data-changedata' => json_encode(
                ['getitem' => 
                    [  'uri' => admin_base_path('api/menu/menutargetvalue') 
                       , 'key' => ['menu_type']
                    ]
                ]
            ), 'data-filter' => json_encode([
                'key' => 'menu_type', 'readonlyValue' => [Define::MENU_TYPE_CUSTOM]
            ])])
        ;
        $form->text('menu_name', exmtrans("menu.menu_name"))->attribute(['readonly' => true]);
        $form->text('uri', trans('admin.uri'))
            ->attribute(['data-filter' => json_encode([
                'key' => 'menu_type', 'readonlyValue' => [Define::MENU_TYPE_SYSTEM, Define::MENU_TYPE_PLUGIN, Define::MENU_TYPE_TABLE]
            ])]);
        $form->text('title', exmtrans("menu.title"))->rules('required');
        $form->icon('icon', trans('admin.icon'))->default('');
    }
}
