<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * GET admin/auth/menu/{menu} (MenuController::show) only sends the user on to the edit screen.
 * It used to name the route parameter "id" instead of "menu", so every request answered 500
 * (UrlGenerationException for route menu.edit).
 */
class MenuShowRedirectTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testShowRedirectsToTheEditScreen(): void
    {
        $menu = Menu::query()->firstOrFail();

        $this->get(admin_url("auth/menu/{$menu->id}"))
            ->assertRedirect(admin_url("auth/menu/{$menu->id}/edit"));
    }
}
