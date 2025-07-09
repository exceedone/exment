<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Illuminate\Support\Str;

class MenuService
{
    /**
     * Create or get a parent menu (parent_node)
     *
     * @param string $menuName
     * @param string $title
     * @param string $icon
     * @return Menu
     */
    public function createOrGetParentMenu(string $menuName, string $title, string $icon = 'fa-folder')
    {
        // Check if the parent menu already exists
        $existingMenu = Menu::where('menu_name', $menuName)
            ->where('menu_type', MenuType::PARENT_NODE)
            ->first();
        if ($existingMenu) {
            return $existingMenu;
        }
        // Create a new parent menu using new Menu() and save()
        $menu = new Menu();
        $menu->parent_id = 0;
        $menu->menu_type = 'parent_node';
        $menu->menu_name = $menuName;
        $menu->menu_target = null;
        $menu->uri = '#';
        $menu->title = $title;
        $menu->icon = $icon;
        $menu->order = $this->getNextParentOrder(); // null = root level
        $menu->save();
        return $menu;
    }

    /**
     * Create a child menu
     *
     * @param int $parentId ID of the parent menu
     * @param array $childMenuData Data of the child menu
     * @return Menu
     */
    public function createChildMenu(int $parentId, array $childMenuData)
    {
        // Validate required data
        $this->validateChildMenuData($childMenuData);
        // Create a new child menu using new Menu() and save()
        $menu = new Menu();
        $menu->parent_id = $parentId;
        $menu->menu_type = $childMenuData['menu_type'] ?? MenuType::CUSTOM;
        $menu->menu_target = $childMenuData['menu_target'] ?? null;
        $menu->uri = $childMenuData['uri'] ?? null;
        $menu->menu_name = $childMenuData['menu_name'];
        $menu->title = $childMenuData['title'];
        $menu->icon = $childMenuData['icon'] ?? 'fa-circle';
        $menu->order = $this->getNextOrder($parentId);
        $menu->save();
        return $menu;
    }

    /**
     * Create a simple parent menu
     *
     * @param string $menuName
     * @param string $title
     * @param string $icon
     * @return Menu
     */
    public function createParentMenu(string $menuName, string $title, string $icon = 'fa-folder')
    {
        return $this->createOrGetParentMenu($menuName, $title, $icon);
    }

    /**
     * Create a child menu for a table
     *
     * @param int $parentId
     * @param string $tableName
     * @param string $title
     * @param string $icon
     * @return Menu
     */
    public function createTableChildMenu(int $parentId, string $tableName, string $title, string $icon = 'fa-table')
    {
        $table = CustomTable::getEloquent($tableName) ?? null;
        return $this->createChildMenu($parentId, [
            'menu_name' => $tableName,
            'title' => $title,
            'uri' => $tableName,
            'icon' => $icon,
            'options' => '{"menu_target_view":null}',
            'menu_type' => MenuType::TABLE,
            'menu_target' => $table ? $table->id : null,
        ]);
    }

    /**
     * Get the next order for a menu
     *
     * @param int|null $parentId
     * @return int
     */
    protected function getNextOrder($parentId = null)
    {
        return Menu::where('parent_id', $parentId)->count() + 1;
    }

    /**
     * Validate child menu data
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateChildMenuData(array $data)
    {
        $requiredFields = ['menu_name', 'title'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Field '{$field}' is required for child menu");
            }
        }

        // Kiểm tra menu_name không được trùng
        if (isset($data['menu_name'])) {
            $existingMenu = Menu::where('menu_name', $data['menu_name'])->first();
            if ($existingMenu) {
                throw new \InvalidArgumentException("Menu with name '{$data['menu_name']}' already exists");
            }
        }
    }

    /**
     * Delete a parent menu and all its child menus
     *
     * @param int $parentId
     * @return bool
     */
    public function deleteParentAndChildren(int $parentId)
    {
        Menu::where('parent_id', $parentId)->delete();
        return Menu::where('id', $parentId)->delete();
    }

    /**
     * Get a parent menu by name
     *
     * @param string $menuName
     * @return Menu|null
     */
    public function getParentMenuByName(string $menuName)
    {
        return Menu::where('menu_name', $menuName)
            ->where('menu_type', MenuType::PARENT_NODE)
            ->first();
    }

    /**
     * Get all child menus of a parent menu
     *
     * @param int $parentId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getChildMenus(int $parentId)
    {
        return Menu::where('parent_id', $parentId)
            ->orderBy('order')
            ->get();
    }

    /**
     * Get the next order value for a parent menu (parent_id = 0)
     *
     * @return int
     */
    public function getNextParentOrder()
    {
        $maxOrder = Menu::where('parent_id', 0)->max('order');
        return $maxOrder ? $maxOrder + 1 : 1;
    }
    // /**
    //  * Delete the admin menu for chatbot if it exists.
    //  *
    //  * @return void
    //  */
    // private function deleteChatbotAdminMenu()
    // {
    //     $menuService = new MenuService();
    //     $parentMenu = $menuService->getParentMenuByName('system_chatbot_menu');
    //     if ($parentMenu) {
    //         $menuService->deleteParentAndChildren($parentMenu->id);
    //     }
    // }

    // /**
    //  * Create the admin menu for chatbot if it does not exist.
    //  *
    //  * @return mixed Parent menu object if created, null otherwise
    //  */
    // private function createChatbotAdminMenu()
    // {
    //     // Create admin menu for chatbot if it does not exist
    //     $menuService = new MenuService();
    //     $menuName = 'system_chatbot_menu';

    //     $existingParent = $menuService->getParentMenuByName($menuName);

    //     if (!$existingParent) {
    //         $parentMenu = $menuService->createParentMenu(
    //             $menuName,
    //             'Chatbot',
    //             'fa-robot'
    //         );

    //         $menuService->createTableChildMenu(
    //             $parentMenu->id,
    //             'system_chatbot_faq',
    //             'FAQ Chatbot',
    //             'fa-question-circle'
    //         );

    //         $menuService->createTableChildMenu(
    //             $parentMenu->id,
    //             'system_chatbot_UI_text',
    //             'UI Text Chatbot',
    //             'fa-comments'
    //         );

    //         return $parentMenu;
    //     }
    // }


}
