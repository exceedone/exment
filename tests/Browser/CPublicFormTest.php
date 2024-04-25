<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Carbon\Carbon;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Model\PublicForm;

/**
 */
class CPublicFormTest extends ExmentKitTestCase
{
    // Not use DatabaseTransactions, set for manual.
    // use DatabaseTransactions;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(bool $fake = false): void
    {
        parent::setUp();
        $this->login();

        if ($fake) {
            Notification::fake();
            Notification::assertNothingSent();
        }
    }

    /**
     * Check public form display.
     */
    public function testDisplayPublicForm()
    {
        \DB::beginTransaction();
        // Check public form list and setting
        $this->visit(admin_url('form/custom_value_edit_all'))
                ->seePageIs(admin_url('form/custom_value_edit_all'))
                ->see('公開フォーム設定')
                ->seeInElement('th', '対象フォーム')
                ->seeInElement('th', '公開フォーム表示名')
                ->seeInElement('th', '有効フラグ')
                ->seeInElement('th', '公開有効期限')
                ->seeInElement('th', '操作')
                ->visit(admin_url('formpublic/custom_value_edit_all/create'))
                ->seeInElement('h3[class=box-title]', '作成')
                ->seeInElement('h1', '公開フォーム設定')
                ->seeInElement('a', '基本設定')
                ->seeInElement('a', 'デザイン設定')
                ->seeInElement('a', '回答確認・完了設定')
                ->seeInElement('a', 'エラー設定')
                ->seeInElement('a', 'CSS・Javascript')
                ->seeInElement('a', 'オプション設定')
                ->seeInElement('h4', '基本設定')
                ->seeInElement('label', '対象フォーム')
                ->seeInElement('label', '公開フォーム表示名')
                ->seeInElement('label', '公開有効期限')
                ->seeInElement('h4', 'ヘッダー設定')
                ->seeInElement('label', 'ヘッダー使用')
                ->seeInElement('label', 'ヘッダー-背景色')
                ->seeInElement('label', 'ヘッダーロゴ')
                ->seeInElement('label', 'ヘッダー文字列')
                ->seeInElement('label', 'ヘッダー文字色')
                ->seeInElement('h4', 'フォーム設定')
                ->seeInElement('label', '背景色-外枠')
                ->seeInElement('label', '背景色')
                ->seeInElement('h4', 'フッター設定')
                ->seeInElement('label', 'フッター使用')
                ->seeInElement('label', 'フッター-背景色')
                ->seeInElement('label', 'フッター文字色')
                ->seeInElement('h4', '回答確認設定')
                ->seeInElement('label', '回答確認を使用する')
                ->seeInElement('label', '確認タイトル')
                ->seeInElement('label', '確認テキスト')
                ->seeInElement('h4', '完了設定')
                ->seeInElement('label', '完了タイトル')
                ->seeInElement('label', '完了テキスト')
                ->seeInElement('label', '完了リンク先URL')
                ->seeInElement('label', '完了リンク先テキスト')
                ->seeInElement('h4', '完了通知(一般ユーザー)')
                ->seeInElement('label', '完了通知を一般ユーザーに行う')
                ->seeInElement('label', '通知テンプレート')
                ->seeInElement('label', '通知対象')
                ->seeInElement('h4', '完了通知(管理者)')
                ->seeInElement('label', '完了通知を管理者に行う')
                ->seeInElement('label', '通知テンプレート')
                ->seeInElement('h5', '通知対象')
                ->seeInElement('h4', 'エラー設定')
                ->seeInElement('label', 'エラータイトル')
                ->seeInElement('label', 'エラーテキスト')
                ->seeInElement('label', 'エラーリンク先URL')
                ->seeInElement('label', 'エラーリンク先テキスト')
                ->seeInElement('h4', 'エラー通知(管理者)')
                ->seeInElement('label', 'エラー通知を行う')
                ->seeInElement('label', '通知テンプレート')
                ->seeInElement('h5', '通知対象')
                ->seeInElement('h4', 'CSS・Javascript')
                ->seeInElement('label', 'カスタムCSS')
                ->seeInElement('label', 'プラグイン(CSS)')
                ->seeInElement('label', 'カスタムJavascript')
                ->seeInElement('label', 'プラグイン(Javascript)')
                ->seeInElement('h4', 'オプション設定')
                ->seeInElement('label', '初期値をURLから設定可能にする')
                ->seeInElement('label', 'Googleアナリティクス')
                ->seeInElement('label', 'Google reCAPTCHAを使用する')
                ->seeInElement('label', '公開有効期限')
                ->seeInElement('label', '公開有効期限')
                ->seeInElement('label', '公開有効期限')
        ;
        \DB::rollback();
    }
    // Create public form
    public function testAddPublicFormSuccess()
    {
        \DB::beginTransaction();
        $pre_cnt = PublicForm::count();

        /** @var CustomTable $table */
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        $target_form = $table->custom_forms->first();

        /** @var CustomColumn $email */
        $email = CustomColumn::where('custom_table_id', $table->id)->where('column_type', 'email')->first();

        $today = Carbon::today();
        $start = Carbon::createFromDate($today->year, 1, 1)->format('Y-m-d');
        $end = Carbon::createFromDate($today->year, 12, 31)->format('Y-m-d');

        $form = [
            'custom_form_id' => $target_form->id,
            'public_form_view_name' => 'Public Form Unit Test',
            'basic_setting' => [
                'validity_period_start' => "{$start} 01:02:03",
                'validity_period_end' => "{$end} 11:22:33",
            ],
            'design_setting' => [
                'use_header' => '1',
                'header_background_color' => '#FF9999',
                'header_label' => 'ユニットテストのヘッダ',
                'header_text_color' => '#0000FF',
                'background_color_outer' => '#000022',
                'background_color' => '#00CCCC',
                'use_footer' => '1',
                'footer_background_color' => '#0000BB',
                'footer_text_color' => '#FFFFEE',
            ],
            'confirm_complete_setting' => [
                'use_confirm' => '1',
                'confirm_title' => 'テスト確認タイトル',
                'confirm_text' => 'テスト内容を確認します。ユニットテストです。',
                'complete_title' => 'テスト完了タイトル',
                'complete_text' => 'テストが完了しました。ユニットテストです。',
                'complete_link_url' => 'http://www.google.com',
                'complete_link_text' => 'Googleに移動',
                'use_notify_complete_user' => '1',
            ],
            'notify_mail_template_complete_user' => '16',
            'notify_actions_complete_user' => [
                'notify_action_target' => [$email->id]
            ],
            'confirm_complete_setting2' => [
                'use_notify_complete_admin' => '1'
            ],
            'notify_mail_template_complete_admin' => '17',
            'notify_actions_complete_admin' => [
                'new_1' => [
                    'notify_action' => '2',
                    'notify_action_target' => ['has_roles'],
                    '_remove_' => 0,
                ]
            ],
            'notify_mail_template_error' => '1',
            'notify_actions_error' => [
                'new_1' => [
                    'notify_action' => '1',
                    'notify_action_target' => ['administrator', 'fixed_email'],
                    'target_emails' => 'unittest@foobar.co.jp.test',
                    '_remove_' => 0,
                ]
            ],
            'error_setting' => [
                'use_notify_error' => '1',
                'error_title' => 'ユニットテストエラー',
                'error_text' => 'エラーが発生しました。ユニットテストです。',
                'error_link_url' => 'https://exment.net/docs/#/ja/',
                'error_link_text' => 'Exmentのマニュアルページへ',
            ],
            'css_js_setting' => [
                'custom_css' => 'h1 {color:red !important;}',
                'plugin_css' => [$this->getStylePluginId()],
                'custom_js' => 'alert("unit test");',
                'plugin_js' => [$this->getScriptPluginId()],
            ],
            'option_setting' => [
                'use_default_query' => '1'
            ],
       ];

        // Create public form with maxmum parameter
        $this->post(admin_url('formpublic/custom_value_edit_all'), $form);

        $response = $this->visit(admin_url('form/custom_value_edit_all'))
            ->seePageIs(admin_url('form/custom_value_edit_all'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'Public Form Unit Test')
            ->seeInElement('td', "{$start} 01:02:03 ～ {$end} 11:22:33")
            ->assertEquals($pre_cnt + 1, PublicForm::count());

        $pform = $this->getNewestForm();
        $notify_complete_user = $pform->notify_complete_user;
        $notify_complete_admin = $pform->notify_complete_admin;
        $notify_error = $pform->notify_error;

        $this->assertNotNull($notify_complete_user);
        $this->assertNotNull($notify_complete_admin);
        $this->assertNotNull($notify_error);

        $this->assertEquals($notify_complete_user->target_id, $pform->id);
        $this->assertEquals($notify_complete_admin->target_id, $pform->id);
        $this->assertEquals($notify_error->target_id, $pform->id);

        $this->assertNotNull($notify_complete_admin->action_settings);
        $this->assertEquals($notify_complete_admin->action_settings[0]['notify_action'], '2');
        $this->assertEquals($notify_complete_admin->action_settings[0]['notify_action_target'], ['has_roles']);

        $this->assertNotNull($notify_error->action_settings);
        $this->assertEquals($notify_error->action_settings[0]['notify_action'], '1');
        $this->assertEquals($notify_error->action_settings[0]['notify_action_target'], ['administrator', 'fixed_email']);

        // Check Public Form updated value
        $this->visit(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/edit'))
            ->seeInField('public_form_view_name', 'Public Form Unit Test')
            ->seeInField('basic_setting[validity_period_start]', "{$start} 01:02:03")
            ->seeInField('basic_setting[validity_period_end]', "{$end} 11:22:33")
            ->seeInField('design_setting[use_header]', '1')
            ->seeInField('header_background_color', '#FF9999')
            ->seeInField('header_label', 'ユニットテストのヘッダ')
            ->seeInField('header_text_color', '#0000FF')
            ->seeInField('background_color_outer', '#000022')
            ->seeInField('background_color', '#00CCCC')
            ->seeInField('design_setting[use_footer]', '1')
            ->seeInField('footer_background_color', '#0000BB')
            ->seeInField('footer_text_color', '#FFFFEE')
            ->seeInField('confirm_complete_setting[use_confirm]', '1')
            ->seeInField('confirm_title', 'テスト確認タイトル')
            ->seeInField('confirm_complete_setting[confirm_text]', 'テスト内容を確認します。ユニットテストです。')
            ->seeInField('complete_title', 'テスト完了タイトル')
            ->seeInField('confirm_complete_setting[complete_text]', 'テストが完了しました。ユニットテストです。')
            ->seeInField('confirm_complete_setting[complete_link_url]', 'http://www.google.com')
            ->seeInField('confirm_complete_setting[complete_link_text]', 'Googleに移動')
            ->seeInField('confirm_complete_setting[use_notify_complete_user]', '1')
            ->seeIsSelected('notify_mail_template_complete_user', '16')
            ->seeIsSelected('notify_actions_complete_user[notify_action_target][]', $email->id)
            ->seeInField('confirm_complete_setting2[use_notify_complete_admin]', '1')
            ->seeIsSelected('notify_mail_template_complete_admin', '17')
            // cannot get random uuid
            //->seeIsSelected("notify_actions_complete_admin[??][notify_action]", '2')
            //->seeIsSelected("notify_actions_complete_admin[??][notify_action_target]", ['has_roles'])
            ->seeInField('error_title', 'ユニットテストエラー')
            ->seeInField('error_setting[error_text]', 'エラーが発生しました。ユニットテストです。')
            ->seeInField('error_setting[error_link_url]', 'https://exment.net/docs/#/ja/')
            ->seeInField('error_setting[error_link_text]', 'Exmentのマニュアルページへ')
            ->seeInField('error_setting[use_notify_error]', '1')
            ->seeIsSelected('notify_mail_template_error', '1')
            // cannot get random uuid
            // ->seeIsSelected('notify_actions_error[??][notify_action]', '1')
            // ->seeIsSelected('notify_actions_error[??][notify_action_target]', ['administrator', 'fixed_email'])
            // ->seeInField('notify_actions_error[??][target_emails]', 'unittest@mail.co.jp')
            ->seeInField('css_js_setting[custom_css]', 'h1 {color:red !important;}')
            ->seeIsSelected('css_js_setting[plugin_css][]', $this->getStylePluginId())
            ->seeInField('css_js_setting[custom_js]', 'alert("unit test");')
            ->seeIsSelected('css_js_setting[plugin_js][]', $this->getScriptPluginId())
            ->seeInField('option_setting[use_default_query]', '1')
        ;

        // Activate public form
        $this->post(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/activate'))
            ->matchStatusCode(200)
        ;

        $share_url = $pform->getUrl();
        // Check activate infomation
        $this->visit(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/edit'))
            ->seeInElement('label', '公開フォームURL')
            ->seeInElement('label', '実行ユーザー')
            ->seeInElement('label', '有効フラグ')
            ->seeInField('share_url', $share_url)
            ->seeOuterElement('span.proxy_user_id', 'admin')
            ->seeOuterElement('span.active_flg', '有効')
        ;

        // Check public form view
        $this->visit($share_url)
            ->seePageIs($share_url)
            ->seeOuterElement('header.main-header', 'ユニットテストのヘッダ')
            ->type('unit test text', 'value[text]')
            ->select('3', 'value[user]')
            ->type('unit test index text', 'value[index_text]')
            ->type('odd', 'value[odd_even]')
            ->type('1', 'value[multiples_of_3]')
            ->type('2020-07-12', 'value[date]')
            ->type('12345', 'value[integer]')
            ->type('987.65', 'value[decimal]')
            ->type('11111.2', 'value[currency]')
            ->type('unit test init text', 'value[init_text]')
            ->type('unittest@foobar.co.jp.test', 'value[email]')
            ->press('admin-submit')
            ->seePageIs($share_url . '/confirm')
            ->seeOuterElement('div.box-body', 'unit test text')
            ->seeOuterElement('div.box-body', 'user2')
            ->seeOuterElement('div.box-body', 'unit test index text')
            ->seeOuterElement('div.box-body', 'odd')
            ->seeOuterElement('div.box-body', 'YES')
            ->seeOuterElement('div.box-body', '2020-07-12')
            ->seeOuterElement('div.box-body', '12345')
            ->seeOuterElement('div.box-body', '987.65')
            ->seeOuterElement('div.box-body', '¥11111.2')
            ->seeOuterElement('div.box-body', 'unit test init text')
            ->seeOuterElement('div.box-body', 'unittest@foobar.co.jp.test')
            ->press('admin-submit')
            ->seePageIs($share_url . '/create')
            ->seeOuterElement('h2', 'テスト完了タイトル')
            ->seeOuterElement('div.complete_text', 'テストが完了しました。ユニットテストです。')
        ;

        // Get new data row
        $table_name = \getDBTableName($table);
        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('id', 'desc')->first();

        $this->visit(admin_url('data/custom_value_edit_all/'. $row->id . '/edit'))
            ->seeInField('value[text]', 'unit test text')
            ->seeIsSelected('value[user]', '3')
            ->seeInField('value[index_text]', 'unit test index text')
            ->seeInField('value[odd_even]', 'odd')
            ->seeInField('value[multiples_of_3]', '1')
            ->seeInField('value[date]', '2020-07-12')
            ->seeInField('value[integer]', '12345')
            ->seeInField('value[decimal]', '987.65')
            ->seeInField('value[currency]', '11111.2')
            ->seeInField('value[init_text]', 'unit test init text')
            ->seeInField('value[email]', 'unittest@foobar.co.jp.test')
        ;

        // Delete public form
        $this->delete(admin_url('formpublic/custom_value_edit_all/'. $pform->id))
            ->matchStatusCode(200)
        ;

        $response = $this->visit(admin_url('form/custom_value_edit_all'))
            ->seePageIs(admin_url('form/custom_value_edit_all'))
            ->matchStatusCode(200)
            ->dontSeeInElement('td', 'Public Form Unit Test')
        ;

        \DB::rollback();
    }

    // Update public form
    public function testUpdatePublicFormSuccess()
    {
        \DB::beginTransaction();
        $pre_cnt = PublicForm::count();

        /** @var CustomTable $table */
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        /** @var CustomForm $target_form */
        $target_form = $table->custom_forms->first();

        // Create public form with minimum parameter
        $this->post(admin_url('formpublic/custom_value_edit_all'), [
            'custom_form_id' => $target_form->id,
            'public_form_view_name' => 'Public Form Unit Test',
        ]);

        $response = $this->visit(admin_url('form/custom_value_edit_all'))
            ->seePageIs(admin_url('form/custom_value_edit_all'))
            ->matchStatusCode(200)
            ->seeInElement('td', 'Public Form Unit Test')
            ->assertEquals($pre_cnt + 1, PublicForm::count());

        $pform = $this->getNewestForm();
        $id = array_get($pform, 'id');

        $this->assertNull($pform->notify_complete_admin);

        // Check Public Form default save value
        $this->visit(admin_url('formpublic/custom_value_edit_all/'. $id . '/edit'))
            ->seeInElement('h3[class=box-title]', '編集')
            ->seeInElement('span', $target_form->form_view_name)
            ->seeInField('public_form_view_name', 'Public Form Unit Test')
            ->seeInField('header_background_color', '#3c8dbc')
            ->seeInField('design_setting[use_header]', '1')
            ->seeInField('header_text_color', '#FFFFFF')
            ->seeInField('background_color_outer', '#FFFFFF')
            ->seeInField('background_color', '#FFFFFF')
            ->seeInField('design_setting[use_footer]', '1')
            ->seeInField('footer_background_color', '#000000')
            ->seeInField('footer_text_color', '#FFFFFF')
            ->seeInField('confirm_complete_setting[use_confirm]', '1')
            ->seeInField('confirm_title', '入力内容確認')
            ->seeInField('confirm_complete_setting[confirm_text]', exmtrans('custom_form_public.message.confirm_text'))
            ->seeInField('complete_title', '入力完了')
            ->seeInField('confirm_complete_setting[complete_text]', exmtrans('custom_form_public.message.complete_text'))
            ->seeInField('confirm_complete_setting[use_notify_complete_user]', '0')
            ->seeInField('confirm_complete_setting2[use_notify_complete_admin]', '0')
            ->seeInField('error_title', 'エラーが発生しました')
            ->seeInField('error_setting[error_text]', exmtrans('custom_form_public.message.error_text'))
            ->seeInField('error_setting[use_notify_error]', '0')
            ->seeInField('option_setting[use_default_query]', '0')
        ;

        /** @var CustomColumn $email */
        $email = CustomColumn::where('custom_table_id', $table->id)->where('column_type', 'email')->first();

        $today = Carbon::today();
        $start = Carbon::createFromDate($today->year, 1, 1)->format('Y-m-d');
        $end = Carbon::createFromDate($today->year, 12, 31)->format('Y-m-d');

        $form = [
            'public_form_view_name' => 'Public Form Unit Test Update',
            'basic_setting[validity_period_start]' => $start,
            'basic_setting[validity_period_end]' => $end,
            'design_setting[use_header]' => '0',
            'design_setting[background_color_outer]' => '#0066CC',
            'design_setting[background_color]' => '#00CCCC',
            'design_setting[use_footer]' => '0',
            'confirm_complete_setting[use_confirm]' => '0',
            'confirm_complete_setting[complete_title]' => 'テスト完了タイトル',
            'confirm_complete_setting[complete_text]' => 'テストが完了しました。ユニットテストです。',
            'confirm_complete_setting[complete_link_url]' => 'http://www.google.com',
            'confirm_complete_setting[complete_link_text]' => 'Googleに移動',
            'confirm_complete_setting[use_notify_complete_user]' => '1',
            'notify_mail_template_complete_user' => '16',
            'notify_actions_complete_user[notify_action_target]' => [$email->id],
            'error_setting[error_title]' => 'ユニットテストエラー',
            'error_setting[error_text]' => 'エラーが発生しました。ユニットテストです。',
            'error_setting[error_link_url]' => 'https://exment.net/docs/#/ja/',
            'error_setting[error_link_text]' => 'Exmentのマニュアルページへ',
            'css_js_setting[custom_css]' => 'h1 {
                color:red !important;
            }',
            'css_js_setting[plugin_css]' => [$this->getStylePluginId()],
            'css_js_setting[custom_js]' => 'alert("unit test");',
            'css_js_setting[plugin_js]' => [$this->getScriptPluginId()],
            'option_setting[use_default_query]' => '1',
        ];

        // Update Public Form
        $this->visit(admin_url('formpublic/custom_value_edit_all/'. $id . '/edit'))
                ->submitForm('admin-submit', $form)
                ->seePageIs(admin_url('form/custom_value_edit_all'))
                ->seeInElement('td', 'Public Form Unit Test Update')
                ->seeInElement('td', "{$start} ～ {$end}")
        ;

        // Update Public Form Direct
        $this->put(admin_url('formpublic/custom_value_edit_all/'. $id), [
            'confirm_complete_setting2' => [
                'use_notify_complete_admin' => '1',
            ],
            'notify_mail_template_complete_admin' => '17',
            'notify_actions_complete_admin' => [
                'new_1' => [
                    'notify_action' => '2',
                    'notify_action_target' => ['has_roles'],
                    '_remove_' => 0,
                ]
            ],
            'error_setting' => [
                'use_notify_error' => '1',
            ],
            'notify_mail_template_error' => '1',
            'notify_actions_error' => [
                'new_1' => [
                    'notify_action' => '1',
                    'notify_action_target' => ['administrator', 'fixed_email'],
                    'target_emails' => 'unittest@mail.co.jp',
                    '_remove_' => 0,
                ]
            ],
        ]);

        $pform = $this->getNewestForm();
        $notify_complete_user = $pform->notify_complete_user;
        $notify_complete_admin = $pform->notify_complete_admin;
        $notify_error = $pform->notify_error;

        $this->assertNotNull($notify_complete_user);
        $this->assertNotNull($notify_complete_admin);
        $this->assertNotNull($notify_error);

        $this->assertEquals($notify_complete_user->target_id, $pform->id);
        $this->assertEquals($notify_complete_admin->target_id, $pform->id);
        $this->assertEquals($notify_error->target_id, $pform->id);

        $this->assertNotNull($notify_complete_admin->action_settings);
        $this->assertEquals($notify_complete_admin->action_settings[0]['notify_action'], '2');
        $this->assertEquals($notify_complete_admin->action_settings[0]['notify_action_target'], ['has_roles']);

        $this->assertNotNull($notify_error->action_settings);
        $this->assertEquals($notify_error->action_settings[0]['notify_action'], '1');
        $this->assertEquals($notify_error->action_settings[0]['notify_action_target'], ['administrator', 'fixed_email']);

        // Check Public Form updated value
        $this->visit(admin_url('formpublic/custom_value_edit_all/'. $id . '/edit'))
            ->seeInField('public_form_view_name', 'Public Form Unit Test Update')
            ->seeInField('design_setting[use_header]', '0')
            ->seeInField('background_color_outer', '#0066CC')
            ->seeInField('background_color', '#00CCCC')
            ->seeInField('design_setting[use_footer]', '0')
            ->seeInField('confirm_complete_setting[use_confirm]', '0')
            ->seeInField('complete_title', 'テスト完了タイトル')
            ->seeInField('confirm_complete_setting[complete_text]', 'テストが完了しました。ユニットテストです。')
            ->seeInField('confirm_complete_setting[complete_link_url]', 'http://www.google.com')
            ->seeInField('confirm_complete_setting[complete_link_text]', 'Googleに移動')
            ->seeInField('confirm_complete_setting[use_notify_complete_user]', '1')
            ->seeIsSelected('notify_mail_template_complete_user', '16')
            ->seeIsSelected('notify_actions_complete_user[notify_action_target][]', $email->id)
            ->seeInField('confirm_complete_setting2[use_notify_complete_admin]', '1')
            ->seeIsSelected('notify_mail_template_complete_admin', '17')
            // cannot get random uuid
            //->seeIsSelected("notify_actions_complete_admin[??][notify_action]", '2')
            //->seeIsSelected("notify_actions_complete_admin[??][notify_action_target]", ['has_roles'])
            ->seeInField('error_title', 'ユニットテストエラー')
            ->seeInField('error_setting[error_text]', 'エラーが発生しました。ユニットテストです。')
            ->seeInField('error_setting[error_link_url]', 'https://exment.net/docs/#/ja/')
            ->seeInField('error_setting[error_link_text]', 'Exmentのマニュアルページへ')
            ->seeInField('error_setting[use_notify_error]', '1')
            ->seeIsSelected('notify_mail_template_error', '1')
            // cannot get random uuid
            // ->seeIsSelected('notify_actions_error[??][notify_action]', '1')
            // ->seeIsSelected('notify_actions_error[??][notify_action_target]', ['administrator', 'fixed_email'])
            // ->seeInField('notify_actions_error[??][target_emails]', 'unittest@mail.co.jp')
            ->seeInField('css_js_setting[custom_css]', 'h1 { color:red !important; }')
            ->seeIsSelected('css_js_setting[plugin_css][]', $this->getStylePluginId())
            ->seeInField('css_js_setting[custom_js]', 'alert("unit test");')
            ->seeIsSelected('css_js_setting[plugin_js][]', $this->getScriptPluginId())
            ->seeInField('option_setting[use_default_query]', '1')
        ;

        // Activate public form
        $this->post(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/activate'))
            ->matchStatusCode(200)
        ;

        $share_url = $pform->getUrl();

        // Check public form view
        $this->visit($share_url)
            ->seePageIs($share_url)
            ->type('unit test text', 'value[text]')
            ->press('admin-submit')
            ->seePageIs($share_url . '/create')
            ->seeOuterElement('h2', 'テスト完了タイトル')
            ->seeOuterElement('div.complete_text', 'テストが完了しました。ユニットテストです。')
        ;

        // Get new data row
        $table_name = \getDBTableName($table);
        $row = \DB::table($table_name)->whereNull('deleted_at')->orderBy('id', 'desc')->first();

        $this->visit(admin_url('data/custom_value_edit_all/'. $row->id . '/edit'))
            ->seeInField('value[text]', 'unit test text')
        ;
        \DB::rollback();
    }

    // Create Public Form Fail --Nothing Input--
    public function testAddFailWithMissingInfo()
    {
        $this->visit(admin_url('formpublic/custom_value_edit_all/create'))
                ->seePageIs(admin_url('formpublic/custom_value_edit_all/create'))
                ->seeInElement('h3[class=box-title]', '作成')
                ->press('admin-submit')
                ->seePageIs(admin_url('formpublic/custom_value_edit_all/create'))
        ;
    }

    // View Public Form Fail --Out of term--
    public function testAddFailOutOfTerm()
    {
        \DB::beginTransaction();
        /** @var CustomTable $table */
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        /** @var CustomForm $target_form */
        $target_form = $table->custom_forms->first();

        // Create public form with minimum parameter
        $this->post(admin_url('formpublic/custom_value_edit_all'), [
            'custom_form_id' => $target_form->id,
            'public_form_view_name' => 'Public Form Unit Error Test',
            'basic_setting' => [
                'validity_period_start' => '2020-01-01 01:02:03',
                'validity_period_end' => '2020-12-31 11:22:33',
            ],
        ]);

        $pform = $this->getNewestForm();

        // Activate public form
        $this->post(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/activate'))
            ->matchStatusCode(200)
        ;
        $share_url = $pform->getUrl();

        // Check public form view out of term
        $this->visit($share_url)
            ->seePageIs($share_url)
            ->seeOuterElement('body', exmtrans('error.public_form_not_found'))
        ;

        \DB::rollback();
    }

    // View Public Form Fail --Out of term--
    public function testAddFailDeactivate1()
    {
        // Not call database transaction and rollback.
        /** @var CustomTable  $table */
        $table = CustomTable::where('table_name', 'custom_value_edit_all')->first();
        /** @var CustomForm $target_form */
        $target_form = $table->custom_forms->first();

        // Create public form with minimum parameter
        $this->post(admin_url('formpublic/custom_value_edit_all'), [
            'custom_form_id' => $target_form->id,
            'public_form_view_name' => 'Public Form Unit Error Test',
        ]);

        $pform = $this->getNewestForm();

        // Activate public form
        $this->post(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/activate'))
            ->matchStatusCode(200)
        ;
        $share_url = $pform->getUrl();
        $this->visit($share_url)
            ->seePageIs($share_url)
            ->seeElement('input.value_text')
        ;

        // Activate public form
        $this->post(admin_url('formpublic/custom_value_edit_all/'. $pform->id . '/deactivate'))
            ->matchStatusCode(200)
        ;
    }

    // View Public Form Fail --Out of term--
    public function testAddFailDeactivate2()
    {
        // Not call database transaction and rollback.

        $pform = $this->getNewestForm();
        $share_url = $pform->getUrl();

        // Check public form view out of term
        $this->visit($share_url)
            ->seePageIs($share_url)
            ->seeOuterElement('body', exmtrans('error.public_form_not_found'))
        ;
    }

    protected function getNewestForm()
    {
        return PublicForm::orderBy('id', 'desc')->first();
    }

    protected function getStylePluginId()
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::where('plugin_name', 'TestPluginStyle')->first();
        return $plugin->id;
    }

    protected function getScriptPluginId()
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::where('plugin_name', 'TestPluginScript')->first();
        return $plugin->id;
    }
}
