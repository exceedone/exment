<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\TestDefine;

class IMenuTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }


    /**
     * @return void
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


    /**
     * @return void
     */
    public function testCreateMenuParent()
    {
        $this->_testCreateMenu('parent_menu_name', [
            'parent_id' =>'0',
            'menu_type' =>'parent_node',
            'menu_target' => null,
            'title' =>'MenuTestParent',
            'icon' =>'fa-user',
        ]);
    }


    /**
     * @return void
     */
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

    /**
     * @return void
     */
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


    /**
     * @return void
     */
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


    /**
     * @return void
     */
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


    /**
     * @return void
     */
    public function testEditMenuParent()
    {
        $menu = $this->getMenuTestModel('parent_menu_name');
        $this->_testEditMenu($menu, [
            'title' =>'MenuTestParentEdit',
            'icon' =>'fa-database',
        ]);
    }


    /**
     * @return void
     */
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


    /**
     * @return void
     */
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


    /**
     * @return void
     */
    public function testEditMenuCustomUrl()
    {
        $menu = $this->getMenuEditTestModel('custom');
        $this->_testEditMenu($menu, [
            'uri' => 'https://github.com/exceedone/exment',
            'title' => 'ExmentGitHub',
        ]);
    }


    /**
     * Regression test: allNodes() must resolve system menu uri/icon from menu_target, not menu_name.
     *
     * Scenario (deduplication): when MenuController::menutargetvalue() detects a duplicate
     * menu_name (e.g. 'custom_table' already exists), it suffixes the name ('custom_table_1').
     * After the save:
     *   menu_target = 'custom_table'  (FK key into MENU_SYSTEM_DEFINITION — unchanged)
     *   menu_name   = 'custom_table_1' (UI label — deduplicated)
     *
     * Buggy code:  array_get(MENU_SYSTEM_DEFINITION, $row['menu_name'])  → null  → uri = null
     * Fixed code:  array_get(MENU_SYSTEM_DEFINITION, $row['menu_target']) → correct uri/icon
     *
     * @return void
     */
    public function testAllNodesSystemMenuUsesMenuTarget()
    {
        $parentMenu = $this->getParentMenuTestModel();

        // Directly insert a record that simulates a deduplicated menu_name
        $menu = new Menu();
        $menu->parent_id = $parentMenu->id;
        $menu->menu_type = 'system';
        $menu->menu_target = 'custom_table';   // key in MENU_SYSTEM_DEFINITION
        $menu->menu_name   = 'custom_table_1'; // deduplicated name — NOT a key in MENU_SYSTEM_DEFINITION
        $menu->title = 'AllNodesSystemTest';
        $menu->icon  = '';                     // empty → allNodes() must fill from MENU_SYSTEM_DEFINITION
        $menu->uri   = 'table';
        $menu->order = Menu::where('parent_id', $parentMenu->id)->count() + 1;
        $menu->save();

        try {
            $allNodes = (new Menu())->allNodes();
            $node = collect($allNodes)->first(function ($n) use ($menu) {
                return array_get($n, 'id') == $menu->id;
            });

            $this->assertNotNull($node, 'System menu node must appear in allNodes()');

            // MENU_SYSTEM_DEFINITION['custom_table'] = ['uri' => 'table', 'icon' => 'fa-table']
            // Using menu_name ('custom_table_1') returns null → uri = null  (BUG)
            // Using menu_target ('custom_table')   returns definition → uri = 'table' (FIX)
            $this->assertEquals(
                'table',
                array_get($node, 'uri'),
                'URI must be resolved from menu_target, not deduplicated menu_name'
            );
            $this->assertEquals(
                'fa-table',
                array_get($node, 'icon'),
                'Icon must be resolved from menu_target when icon column is empty'
            );
        } finally {
            $menu->delete();
        }
    }


    /**
     * Baseline: allNodes() works correctly when menu_name == menu_target (no deduplication).
     * Both old and fixed code pass this; it documents the expected normal-case contract.
     *
     * @return void
     */
    public function testAllNodesSystemMenuNormalCase()
    {
        $parentMenu = $this->getParentMenuTestModel();

        $menu = new Menu();
        $menu->parent_id = $parentMenu->id;
        $menu->menu_type = 'system';
        $menu->menu_target = 'backup'; // key in MENU_SYSTEM_DEFINITION
        $menu->menu_name   = 'backup'; // same as menu_target — no deduplication
        $menu->title = 'BackupAllNodesTest';
        $menu->icon  = '';
        $menu->uri   = 'backup';
        $menu->order = Menu::where('parent_id', $parentMenu->id)->count() + 1;
        $menu->save();

        try {
            $allNodes = (new Menu())->allNodes();
            $node = collect($allNodes)->first(function ($n) use ($menu) {
                return array_get($n, 'id') == $menu->id;
            });

            $this->assertNotNull($node, 'System menu node must appear in allNodes()');
            // MENU_SYSTEM_DEFINITION['backup'] = ['uri' => 'backup', 'icon' => 'fa-database']
            $this->assertEquals('backup', array_get($node, 'uri'));
            $this->assertEquals('fa-database', array_get($node, 'icon'));
        } finally {
            $menu->delete();
        }
    }


    /**
     * @param string $menu_name
     * @param array<mixed> $data
     * @param \Closure|null $checkFunc
     * @return void
     */
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

        // Additional assertion for parent_node: uri must be null
        if (array_get($data, 'menu_type') === 'parent_node') {
            $this->assertNull($model->uri, 'parent_node menu must have null uri');
        }
        if ($checkFunc instanceof \Closure) {
            $checkFunc($model);
        }
    }

    /**
     * Test edit menu
     *
     * @param Menu $menu
     * @param array<mixed> $editData
     * @return void
     */
    protected function _testEditMenu(Menu $menu, array $editData)
    {
        $this->visit(admin_urls('auth', 'menu', $menu->id, 'edit'))
            ->seePageIs(admin_urls('auth', 'menu', $menu->id, 'edit'));

        $data = [];

        foreach (['parent_id', 'menu_type', 'menu_target', 'menu_target_view', 'uri', 'menu_name', 'title', 'icon'] as $checkKey) {
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
        if ($model->menu_type === 'parent_node') {
            $this->assertNull($model->uri, 'parent_node menu must have null uri after edit');
        }
    }


    /**
     * @return Menu
     */
    protected function getParentMenuTestModel(): Menu
    {
        return $this->getMenuTestModel('parent_menu_name');
    }


    /**
     * @param string $menu_name
     * @return Menu
     */
    protected function getMenuTestModel(string $menu_name): Menu
    {
        $model = Menu::where('menu_name', $menu_name)->first();
        $this->assertTrue(isset($model), 'menu not found');
        return $model;
    }


    /**
     * @param string $menu_type
     * @return Menu
     */
    protected function getMenuEditTestModel(string $menu_type): Menu
    {
        $parent_menu = $this->getParentMenuTestModel();
        $model = Menu::where('menu_type', $menu_type)->where('parent_id', $parent_menu->id)->first();
        $this->assertTrue(isset($model), 'menu not found');
        return $model;
    }
}
