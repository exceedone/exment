<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\TestDefine;

class IMenuTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     */
    public function testDisplayMenu()
    {
        $this->visit(admin_url('auth/menu'))
            ->seePageIs(admin_url('auth/menu'))
            ->see(trans('admin.menu'))
            ->seeInElement('label', trans('admin.parent_id'))
            ->seeInElement('label', exmtrans("menu.menu_type"))
            ->seeInElement('label', exmtrans("menu.menu_target"))
            ->seeInElement('label', trans('admin.uri'))
            ->seeInElement('label', exmtrans("menu.menu_name"))
            ->seeInElement('label', exmtrans("menu.title"))
            ->seeInElement('label', trans('admin.icon'))
            ->seeInElement('button', trans('admin.save'));
    }


    public function testCreateMenuParent()
    {
        $this->_testCreateMenu('parent_menu_name', [
            'parent_id' =>'0',
            'menu_type' =>'parent_node',
            'menu_target' =>'',
            'uri' =>'/',
            'title' =>'MenuTestParent',
            'icon' =>'fa-user',
        ]);
    }


    public function testCreateMenuSystem()
    {
        $menu_name  = short_uuid();

        $this->_testCreateMenu($menu_name, [
            'parent_id' => $this->getParentMenuTestModel()->id,
            'menu_type' => 'system',
            'menu_target' => 'home',
            'uri' => '/',
            'title' => 'MenuTestSystem',
            'icon' => 'fa-home',
        ]);
    }

    public function testCreateMenuTable()
    {
        $menu_name  = short_uuid();

        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $this->_testCreateMenu($menu_name, [
            'parent_id' => $this->getParentMenuTestModel()->id,
            'menu_type' => 'table',
            'menu_target' => $custom_table->id,
            'uri' => $custom_table->table_name,
            'title' => $custom_table->table_view_name,
            'icon' => $custom_table->getOption('icon') ?? 'fa-table',
        ]);
    }


    public function testCreateMenuTableView()
    {
        $menu_name  = short_uuid();

        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $this->_testCreateMenu($menu_name, [
            'parent_id' => $this->getParentMenuTestModel()->id,
            'menu_type' => 'table',
            'menu_target' => $custom_table->id,
            'menu_target_view' => Model\CustomView::getDefault($custom_table)->id,
            'uri' => $custom_table->table_name,
            'title' => $custom_table->table_view_name,
            'icon' => $custom_table->getOption('icon') ?? 'fa-table',
        ]);
    }


    public function testCreateMenuCustomUrl()
    {
        $menu_name  = short_uuid();

        $this->_testCreateMenu($menu_name, [
            'parent_id' => $this->getParentMenuTestModel()->id,
            'menu_type' => 'custom',
            'menu_target' => null,
            'uri' => 'https://exment.net',
            'title' => 'Exment',
            'icon' => 'fa-exclamation-triangle',
        ]);
    }


    public function testEditMenuParent()
    {
        $menu = $this->getMenuTestModel('parent_menu_name');
        $this->_testEditMenu($menu, [
            'title' =>'MenuTestParentEdit',
            'icon' =>'fa-database',
        ]);
    }


    public function testEditMenuSystem()
    {
        $menu = $this->getMenuEditTestModel('system');
        $this->_testEditMenu($menu, [
            'menu_target' => 'custom_table',
            'uri' => 'table',
            'title' => 'CustomTable',
            'icon' => 'fa-table',
        ]);
    }


    public function testEditMenuTable()
    {
        $custom_table = Model\CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW);
        $menu = $this->getMenuEditTestModel('table');
        $this->_testEditMenu($menu, [
            'menu_target' => $custom_table->id,
            'title' => $custom_table->table_view_name . 'Edit',
            'icon' => $custom_table->getOption('icon') ?? 'fa-table',
        ]);
    }


    public function testEditMenuCustomUrl()
    {
        $menu = $this->getMenuEditTestModel('custom');
        $this->_testEditMenu($menu, [
            'uri' => 'https://github.com/exceedone/exment',
            'title' => 'ExmentGitHub',
        ]);
    }





    protected function _testCreateMenu(string $menu_name, array $data, ?\Closure $checkFunc = null)
    {
        $data['menu_name'] = $menu_name;

        $this->visit(admin_url('auth/menu'))
            ->seePageIs(admin_url('auth/menu'));

        $this->post(admin_url('auth/menu'), $data);
        $this->assertPostResponse($this->response, admin_url('auth/menu'));

        // Check database
        $model = $this->getMenuTestModel($menu_name);

        foreach ($data as $key => $value) {
            $this->assertMatch($model->{$key}, $value);
        }
    }

    /**
     * Test edit menu
     *
     * @param Menu $menu
     * @param array $editData
     * @return void
     */
    protected function _testEditMenu(Menu $menu, array $editData)
    {
        $this->visit(admin_urls('auth', 'menu', $menu->id, 'edit'))
            ->seePageIs(admin_urls('auth', 'menu', $menu->id, 'edit'));

        $data = [];

        foreach (['parent_id', 'menu_type', 'menu_target_view', 'uri', 'menu_name', 'title', 'icon'] as $checkKey) {
            // if has editData in editData, set post value
            if (array_has($editData, $checkKey)) {
                $data[$checkKey] = array_get($editData, $checkKey);
            }
            // if not has, get model
            else {
                $data[$checkKey] = array_get($menu, $checkKey);
            }
        }

        $this->put(admin_urls('auth', 'menu', $menu->id), $data);
        $this->assertPostResponse($this->response, admin_url('auth/menu'));

        $model = Menu::find($menu->id);
        foreach ($data as $key => $value) {
            $this->assertMatch($model->{$key}, $value);
        }
    }


    protected function getParentMenuTestModel(): Menu
    {
        return $this->getMenuTestModel('parent_menu_name');
    }


    protected function getMenuTestModel(string $menu_name): Menu
    {
        $model = Menu::where('menu_name', $menu_name)->first();
        $this->assertTrue(isset($model), 'menu not found');
        return $model;
    }


    protected function getMenuEditTestModel(string $menu_type): Menu
    {
        $parent_menu = $this->getParentMenuTestModel();
        $model = Menu::where('menu_type', $menu_type)->where('parent_id', $parent_menu->id)->first();
        $this->assertTrue(isset($model), 'menu not found');
        return $model;
    }
}
