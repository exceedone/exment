<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Model\Menu;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        return (new Menu())->toTree();
    }
}
