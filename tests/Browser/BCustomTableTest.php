<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomTable;

class BCustomTableTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        // precondition : login success
        $this->login();
    }

    /**
     * display custom table list.
     *
     * @return void
     */
    public function testDisplayInstalledTable()
    {
        $this->visit(admin_url('table'))
                ->seeInElement('th', 'テーブル名(英数字)')
                ->seeInElement('th', 'テーブル表示名')
                ->seeInElement('th', '操作')
                ->seeInElement('td', 'information')
                ->seeInElement('td', 'お知らせ')
                ->seeInElement('td', 'user')
                ->seeInElement('td', 'ユーザー')
                ->seeInElement('td', 'organization')
                ->seeInElement('td', '組織')
        ;
    }

    /**
     * display custom table create page.
     * @return void
     */
    public function testDisplayCustomTableCreate()
    {
        $this->visit(admin_url('table/create'))
                ->seePageIs(admin_url('table/create'))
                ->seeInElement('h1', 'カスタムテーブル設定')
                ->seeInElement('.box-title', '作成')
                ->seeInElement('label', 'テーブル名(英数字)')
                ->seeInElement('label', 'テーブル表示名')
                ->seeInElement('label', '説明')
                ->seeInElement('.field-header', exmtrans('common.detail_setting'))
                ->seeInElement('label', '色')
                ->seeInElement('label', 'アイコン')
                ->seeInElement('label', '検索可能')
                ->seeInElement('label', '1件のみ登録可能')
                ->seeInElement('label', '添付ファイル使用')
                ->seeInElement('label', 'データ変更履歴使用')
                ->seeInElement('label', '変更履歴バージョン数')
                ->seeInElement('label', '全ユーザーが編集可能')
                ->seeInElement('label', '全ユーザーが閲覧可能')
                ->seeInElement('label', '全ユーザーが参照可能')
                ->seeInElement('label', 'メニューに追加する')
        ;
    }

    /**
     * create custom table.
     * @return void
     */
    public function testCreateCustomTableSuccess()
    {
        $pre_cnt = CustomTable::count();

        // Create custom table
        $this->visit(admin_url('table'))
                ->seePageIs(admin_url('table'))
                ->visit(admin_url('table/create'))
                ->type('test', 'table_name')
                ->type('test table', 'table_view_name')
                ->type('test description', 'description')
                ->type('#ff0000', 'options[color]')
                ->type('fa-automobile', 'options[icon]')
            /** @phpstan-ignore-next-line  */
                ->type(50, 'options[revision_count]')
                ->press('admin-submit')
                ->seePageIs(admin_url('column/test'))
                ->assertEquals($pre_cnt + 1, CustomTable::count())
        ;
    }

    /**
     * edit custom table.
     * @return void
     */
    public function testEditCustomTableSuccess()
    {
        $this->login(); // Đảm bảo đã đăng nhập trước khi test

        $row = CustomTable::orderBy('id', 'desc')->first();
        $id = $row->id;

        $this->put(admin_url('table/' . $id), [
            'table_view_name' => 'test table update',
            'description'     => 'test description update',
            'options' => [
                'color' => '#00ff00',
            ],
        ])->matchStatusCode(200);

        $this->assertNotNull(CustomTable::find($id));

        $this->put(admin_url('table/' . $id), [
            'table_view_name' => 'test table checked',
            'options' => [
                'search_enabled'         => 0,
                'one_record_flg'         => 1,
                'attachment_flg'         => 0,
                'revision_flg'           => 0,
                'all_user_editable_flg'  => 1,
                'all_user_viewable_flg'  => 1,
                'all_user_accessable_flg'=> 1,
            ],
        ])->matchStatusCode(200);

        $this->assertNotNull(CustomTable::find($id));
    }
}
