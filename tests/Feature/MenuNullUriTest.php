<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * A "parent node" menu that has no children yet is rendered as a leaf with uri = null.
 * Both the sidebar (admin::partials.menu) and the menu setting tree (MenuController)
 * used to pass that null into url()->isValidUrl() -> preg_match(null) (E_DEPRECATED on
 * PHP 8.1+, TypeError on PHP 9).
 *
 * The menu row is created inside the test transaction and rolled back.
 */
class MenuNullUriTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * Sidebar: the childless parent node links to the admin top page, without deprecation.
     *
     * @return void
     */
    public function testSidebarRendersParentNodeWithoutChildren()
    {
        $this->insertParentNode('Null Uri Folder');

        [$response, $deprecations] = $this->captureDeprecations(function () {
            return $this->get(admin_url(''));
        });

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/<a href="' . preg_quote(admin_url(''), '/') . '">\s*<i class="fa fa-folder"><\/i>\s*<span>Null Uri Folder<\/span>/',
            $response->getContent()
        );
        $this->assertSame([], $deprecations);
    }

    /**
     * Menu setting tree (MenuController::index): renders the childless parent node, without deprecation.
     *
     * @return void
     */
    public function testMenuSettingTreeRendersParentNodeWithoutChildren()
    {
        $this->insertParentNode('Null Uri Folder');

        [$response, $deprecations] = $this->captureDeprecations(function () {
            return $this->get(admin_urls('auth', 'menu'));
        });

        $response->assertStatus(200);
        $response->assertSee('Null Uri Folder');
        $this->assertSame([], $deprecations);
    }

    /**
     * @param string $title
     * @return int
     */
    protected function insertParentNode(string $title): int
    {
        return DB::table('admin_menu')->insertGetId([
            'parent_id' => 0,
            'order' => 9999,
            'title' => $title,
            'icon' => 'fa-folder',
            'uri' => null,
            'menu_type' => MenuType::PARENT_NODE,
            'menu_name' => 'null_uri_folder',
        ]);
    }

    /**
     * Run $callback and return [its result, every "Passing null to parameter" deprecation it raised]
     * (PHP 8.1 null-to-internal-function, a TypeError on PHP 9).
     * Unrelated framework deprecations are ignored so the test stays focused.
     *
     * @param callable $callback
     * @return array{0: mixed, 1: array<string>}
     */
    protected function captureDeprecations(callable $callback): array
    {
        $captured = [];
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$captured) {
            if (str_contains($errstr, 'Passing null to parameter')) {
                $captured[] = $errstr . ' @ ' . basename($errfile) . ':' . $errline;
            }
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return [$result, $captured];
    }
}
