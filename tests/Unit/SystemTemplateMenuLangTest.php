<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * system_template: the menus of lang/<locale>/lang.json give the titles of the menus of config.json.
 *
 * In v7.1.0 they were laid over config.json by their position, menu_type and menu_name included,
 * and lang/ja/lang.json had one more line ("mail_send_log"). A site newly installed in Japanese got
 * a table menu "mail_send_log" where "Login Setting" should be, "Login Setting" opening the API
 * setting, and no "API Setting".
 */
class SystemTemplateMenuLangTest extends UnitTestBase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function menus(string $path): array
    {
        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json, "$path is not valid json");
        return array_get($json, 'admin_menu', []);
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @return array<int, string>
     */
    protected function keys(array $menus): array
    {
        return array_map(function ($menu) {
            return array_get($menu, 'menu_type') . ':' . array_get($menu, 'menu_name') . ':' . array_get($menu, 'menu_target_name');
        }, $menus);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mergedMenus(string $testLocale, ?string $jsonString = null, ?string $basePath = null): array
    {
        $locale = \Illuminate\Support\Facades\App::getLocale();
        try {
            \Illuminate\Support\Facades\App::setLocale($testLocale);
            $merged = (new TemplateImporter())->getMergeJson($jsonString, ['basePath' => $basePath]);
        } finally {
            \Illuminate\Support\Facades\App::setLocale($locale);
        }
        return array_get($merged, 'admin_menu', []);
    }

    /**
     * In every language the merged template has the menus of config.json: same type, name and target.
     */
    public function testEveryMenuOfTheTemplateKeepsItsTypeNameAndTarget(): void
    {
        $base = exment_package_path('system_template');
        $expected = $this->keys($this->menus("$base/config.json"));
        $this->assertContains('system:login_setting:login_setting', $expected);
        $this->assertContains('system:api_setting:api_setting', $expected);

        foreach (['ja', 'en'] as $testLocale) {
            $this->assertSame($expected, $this->keys($this->mergedMenus($testLocale)), "locale $testLocale");
        }
    }

    public function testJapaneseTitlesOfLoginAndApiSetting(): void
    {
        $titles = [];
        foreach ($this->mergedMenus('ja') as $menu) {
            $titles[array_get($menu, 'menu_name')] = array_get($menu, 'title');
        }

        $this->assertSame('ログイン設定', array_get($titles, 'login_setting'));
        $this->assertSame('APIアプリ設定', array_get($titles, 'api_setting'));
        $this->assertSame('通知テンプレート', array_get($titles, 'mail_template'));
        $this->assertArrayNotHasKey('mail_send_log', $titles);
    }

    /**
     * The menus of a language file are found by menu_type and menu_name, not by their position:
     * a line more, a line less or another order in lang.json does not rename the menus.
     */
    public function testMenusOfALangFileAreFoundByTheirKeys(): void
    {
        $dir = sys_get_temp_dir() . '/exment_template_lang_' . uniqid();
        mkdir("$dir/lang/ja", 0777, true);
        try {
            $config = (string) json_encode(['admin_menu' => [
                ['order' => '1', 'menu_type' => 'system', 'menu_name' => 'system', 'title' => 'System Setting', 'menu_target_name' => 'system'],
                ['order' => '2', 'menu_type' => 'system', 'menu_name' => 'login_setting', 'title' => 'Login Setting', 'menu_target_name' => 'login_setting'],
                ['order' => '3', 'menu_type' => 'system', 'menu_name' => 'api_setting', 'title' => 'API Setting', 'menu_target_name' => 'api_setting'],
            ]]);
            file_put_contents("$dir/lang/ja/lang.json", json_encode(['admin_menu' => [
                ['menu_type' => 'system', 'menu_name' => 'system', 'title' => 'システム設定'],
                ['menu_type' => 'table', 'menu_name' => 'mail_send_log', 'title' => 'メール送信履歴'],
                ['menu_type' => 'system', 'menu_name' => 'api_setting', 'title' => 'APIアプリ設定'],
                ['menu_type' => 'system', 'menu_name' => 'login_setting', 'title' => 'ログイン設定'],
            ]]));

            $menus = $this->mergedMenus('ja', $config, $dir);

            $this->assertSame(
                ['system:system:system', 'system:login_setting:login_setting', 'system:api_setting:api_setting'],
                $this->keys($menus)
            );
            $this->assertSame(['システム設定', 'ログイン設定', 'APIアプリ設定'], array_column($menus, 'title'));
            $this->assertSame(['1', '2', '3'], array_column($menus, 'order'));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }
}
