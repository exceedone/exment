<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Tests\ExmentKitTestCase;
use Exceedone\Exment\Model\CustomTable;

class BCustomTableTest extends ExmentKitTestCase
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
    public function testDisplayInstalledTable()
    {
        $this->visit('/admin/table')
                ->seeInElement('th', 'テーブル名(英数字)')
                ->seeInElement('th', 'テーブル表示名')
                ->seeInElement('th', '操作')
                ->seeInElement('td', 'base_info')
                ->seeInElement('td', '基本情報')
                ->seeInElement('td', 'user')
                ->seeInElement('td', 'ユーザー')
                ->seeInElement('td', 'organization')
                ->seeInElement('td', '組織')
        ;
    }

    /**
     * display custom table create page.
     */
    public function testDisplayCustomTableCreate()
    {
        $this->visit('/admin/table/create')
                ->seePageIs('/admin/table/create')
                ->seeInElement('h1', 'カスタムテーブル設定')
                ->seeInElement('h3[class=box-title]', '作成')
                ->seeInElement('label', 'テーブル名(英数字)')
                ->seeInElement('label', 'テーブル表示名')
                ->seeInElement('label', '説明')
                ->seeInElement('h4[class=pull-right]', '詳細設定')
                ->seeInElement('label', '色')
                ->seeInElement('label', 'アイコン')
                ->seeInElement('label', '検索可能')
                ->seeInElement('label', '1件のみ登録可能')
                ->seeInElement('label', '添付ファイル使用')
                ->seeInElement('label', 'データ変更履歴使用')
                ->seeInElement('label', '変更履歴バージョン数')
                ->seeInElement('label', 'すべてのユーザーが編集可能')
                ->seeInElement('label', 'すべてのユーザーが閲覧可能')
                ->seeInElement('label', 'すべてのユーザーが参照可能')
                ->seeInElement('label', 'メニューに追加する')
        ;
    }

    /**
     * create custom table.
     */
    public function testCreateCustomTableSuccess()
    {
        $pre_cnt = CustomTable::count();

        // Create custom table
        $this->visit('/admin/table')
                ->seePageIs('/admin/table')
                ->visit('/admin/table/create')
                ->type('test', 'table_name')
                ->type('test table', 'table_view_name')
                ->type('test description', 'description')
                ->type('#ff0000', 'options[color]')
                ->type('fa-automobile', 'options[icon]')
                ->type(50, 'options[revision_count]')
                ->press('送信')
                ->seePageIs('/admin/table')
                ->seeInElement('td', 'test')
                ->seeInElement('td', 'test table')
                ->assertEquals($pre_cnt + 1, CustomTable::count())
        ;
    }

    /**
     * edit custom table.
     */
    public function testEditCustomTableSuccess()
    {
        $row = CustomTable::orderBy('created_at', 'desc')->first();
        $id = array_get($row, 'id');

        // Update custom table
        $this->visit('/admin/table/'. $id . '/edit')
                ->seeInField('options[search_enabled]', '1')
                ->seeInField('options[attachment_flg]', '1')
                ->seeInField('options[revision_flg]', '1')
                ->type('test table update', 'table_view_name')
                ->type('test description update', 'description')
                ->type('#00ff00', 'options[color]')
                ->press('送信')
                ->seePageIs('/admin/table')
                ->seeInElement('td', 'test table update')
        ;

        // Update custom table(checkbox field)
        $data = [
                'table_view_name' => 'test table checked',
                'options[search_enabled]' => 0,
                'options[one_record_flg]' => 1,
                'options[attachment_flg]' => 0,
                'options[revision_flg]' => 0,
                'options[all_user_editable_flg]' => 1,
                'options[all_user_viewable_flg]' => 1,
                'options[all_user_accessable_flg]' => 1,
        ];
        // Update custom table
        $this->visit('/admin/table/'. $id . '/edit')
                ->submitForm('送信', $data)
                ->seePageIs('/admin/table')
        ;
    
    }

    // // precondition : login success
    // public function testLoginSuccessWithTrueUsername()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->visit('/admin/auth/logout')
    //             ->visit('/admin/auth/login')
    //             ->type('username', 'testuser')
    //             ->type('password', 'test123456')
    //             ->press('Login')
    //             ->waitForText('Login successful')
    //             ->assertPathIs('/admin')
    //             ;
    //     });
    // }

    // // AutoTest_Table_01
    // public function testDisplaySettingCustomTable()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->visit('/admin/table')
    //             ->assertPathIs('/admin/table')
    //             ;
    //     });

    // }

    // // AutoTest_Table_02
    // public function testDisplayInstalledTable()
    // {
    //     $this->browse(function ($browser) {
    //         $browser->assertSee('base_info')
    //             ->assertSee('Base Info')
    //             ->assertSee('user')
    //             ->assertSee('User')
    //             ->assertSee('organization')
    //             ->assertSee('Organization');
    //     });

    // }

    // // AutoTest_Table_04
    // public function testDisplayCreateScreen()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->waitForText('New')
    //             ->clickLink('New')
    //             ->pause(2000)
    //             ->assertPathIs('/admin/table/create')
    //             ;
    //     });
    // }

    // // AutoTest_Table_05
    // public function testCreateCustomTableSuccess()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->type('table_name', 'test')
    //             ->type('table_view_name', 'test table')
    //             ->type('description', 'test table')
    //             ->type('options[color]', '#ff0000')
    //             ->type('options[icon]', 'fa-automobile');
    //         $browser->script('document.querySelector(".options_search_enabled.la_checkbox").click();');
    //         $browser->script('document.querySelector(".options_one_record_flg.la_checkbox").click();');
    //         $browser->press('Submit')
    //             ->pause(3000)
    //             ->assertMissing('.has-error')
    //             ->assertPathIs('/admin/table')
    //             ->assertSee('test')
    //             ->assertSee('test table');
    //     });
    // }

    // public function testDisplayEditScreen()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->visit('/admin/table')
    //             ->waitForText('New')
    //             ->clickLink('New')
    //             ->pause(2000)
    //             ->assertPathIs('/admin/table/create')
    //             ;
    //     });
    // }

    // public function testEditCustomTableSuccess()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->visit('/admin/table')
    //             ->type('table_name', 'test')
    //             ->type('table_view_name', 'test table')
    //             ->type('description', 'test table')
    //             ->type('options[color]', '#ff0000')
    //             ->type('options[icon]', 'fa-automobile');
    //         $browser->script('document.querySelector(".options_search_enabled.la_checkbox").click();');
    //         $browser->script('document.querySelector(".options_one_record_flg.la_checkbox").click();');
    //         $browser->press('Submit')
    //             ->pause(3000)
    //             ->assertMissing('.has-error')
    //             ->assertPathIs('/admin/table')
    //             ->assertSee('test')
    //             ->assertSee('test table');
    //     });
    // }
    // // AutoTest_Table_06
    // public function testDisplayColummSetting()
    // {
    //     $this->browse(function ($browser) {
    //         $browser
    //             ->click('table tr:last-child .iCheck-helper')
    //             ->press('Change Page')
    //             ->clickLink('Column Detail Setting')
    //             ->pause(3000);
    //         $browser->assertPathIs('/admin/column/test');
    //     });
    // }

}
