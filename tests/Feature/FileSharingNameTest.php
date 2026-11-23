<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * The file server is "ファイル共有" / "File sharing" for users (snoro,
 * 2026-11-23): the menu of a new site, the name in Feature management and the
 * page title, and the menu an older 7.2 made (only while it is still that
 * default). The URL and the key stay file_server.
 */
class FileSharingNameTest extends FeatureTestBase
{
    use DatabaseTransactions;

    public function testTheTemplateAndTheWordsCallItFileSharing(): void
    {
        $config = json_decode((string) file_get_contents(exment_package_path('system_template/config.json')), true);
        $lang = json_decode((string) file_get_contents(exment_package_path('system_template/lang/ja/lang.json')), true);
        $title = function (array $menus): ?string {
            foreach ($menus as $menu) {
                if (($menu['menu_name'] ?? null) === 'file_server') {
                    return $menu['title'] ?? null;
                }
            }
            return null;
        };
        $this->assertSame('File sharing', $title($config['admin_menu']));
        $this->assertSame('ファイル共有', $title($lang['admin_menu']));

        $current = \App::getLocale();
        try {
            \App::setLocale('ja');
            $this->assertSame('ファイル共有', exmtrans('menu.system_definitions.file_server'));
            $this->assertSame('ファイル共有', exmtrans('file_server.header'));
            \App::setLocale('en');
            $this->assertSame('File sharing', exmtrans('menu.system_definitions.file_server'));
        } finally {
            \App::setLocale($current);
        }
    }

    public function testAnOldDefaultTitleGoesAndAnotherStays(): void
    {
        $migration = include exment_package_path('database/migrations/2026_11_28_000000_file_sharing_menu_title.php');
        $this->assertIsObject($migration);
        $up = [$migration, 'up'];
        $this->assertIsCallable($up);

        $row = DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'file_server')->first();
        $id = $row ? $row->id : DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 99, 'title' => 'x', 'icon' => 'fa-folder', 'uri' => 'file_server', 'menu_type' => 'system', 'menu_name' => 'file_server', 'menu_target' => 'file_server']);

        foreach (['ファイルサーバー' => 'ファイル共有', '文書管理' => 'ファイル共有', 'File Server' => 'File sharing', 'Documents' => 'File sharing', '社内の書類' => '社内の書類'] as $before => $after) {
            DB::table('admin_menu')->where('id', $id)->update(['title' => $before]);
            $up();
            $this->assertSame($after, DB::table('admin_menu')->where('id', $id)->value('title'), $before);
        }
    }
}
