<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * The menu of the file server is "ファイルサーバー" / "File server" (snoro,
 * 2026-11-23): in the template of a new site, and on a site that got
 * "文書管理" / "Documents" from an early 7.2 (only while it is still that
 * default; a title an administrator wrote stays).
 */
class FileServerMenuTitleTest extends FeatureTestBase
{
    use DatabaseTransactions;

    public function testTheTemplateCallsItTheFileServer(): void
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
        $this->assertSame('File server', $title($config['admin_menu']));
        $this->assertSame('ファイルサーバー', $title($lang['admin_menu']));
    }

    public function testADefaultTitleGoesBackAndAnotherStays(): void
    {
        $migration = include exment_package_path('database/migrations/2026_11_28_000000_file_server_menu_title.php');
        $this->assertIsObject($migration);
        $up = [$migration, 'up'];
        $this->assertIsCallable($up);
        $row = DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'file_server')->first();
        if (!$row) {
            $id = DB::table('admin_menu')->insertGetId(['parent_id' => 0, 'order' => 99, 'title' => '文書管理', 'icon' => 'fa-folder', 'uri' => 'file_server', 'menu_type' => 'system', 'menu_name' => 'file_server', 'menu_target' => 'file_server']);
        } else {
            $id = $row->id;
        }

        foreach (['文書管理' => 'ファイルサーバー', 'Documents' => 'File server', '社内の書類' => '社内の書類'] as $before => $after) {
            DB::table('admin_menu')->where('id', $id)->update(['title' => $before]);
            $up();
            $this->assertSame($after, DB::table('admin_menu')->where('id', $id)->value('title'), $before);
        }
    }
}
