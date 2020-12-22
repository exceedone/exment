<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\Browser\ExmentKitTestCase;
use Exceedone\Exment\Model\CustomTable;

class PluginPageTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        // precondition : login success
        $this->login();
    }

    /**
     * display custom table list.
     */
    public function testDisplayPluginPage()
    {
        $this->visit(admin_url('plugins/test_plugin_demo_page'))
                ->seeInElement('h1', '独自ページテスト')
                ->seeInElement('div', 'Laravel')
        ;
    }
}
