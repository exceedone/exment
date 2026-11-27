<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The messages of admin_error() and its siblings that have HTML are given as admin_html_message():
 * exment-admin-core shows them as HTML, and the other messages as text. The HTML is the same with
 * the versions of exment-admin-core before (they show every message as it is).
 */
class AdminHtmlMessageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /** A column type that does not exist: its name is escaped once, the message is not escaped again. */
    public function testTheMessageOfAnUnknownColumnTypeIsShownOnTheColumnPage(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();

        $type = '<b class="html-message-check">bad</b>';
        $response = $this->get(admin_urls('column', 'custom_value_edit_all', 'create') . '?column_type=' . rawurlencode($type));
        $html = (string)$response->getContent();

        $this->assertTrue($response->getStatusCode() < 500, 'status ' . $response->getStatusCode());
        $this->assertStringContainsString('Field type [' . e($type) . '] does not exist.', $html);
        $this->assertStringNotContainsString('Field type [' . e(e($type)) . ']', $html);
        $this->assertStringNotContainsString('<b class="html-message-check">', $html);
    }

    public function testTheAlertShowsTheHtmlOfTheMessage(): void
    {
        $column = new CustomColumn(['column_type' => 'no<i>such</i>type']);
        $this->assertNull(CustomItem::getItem($column));

        $alerts = view('admin::partials.alerts')->render();

        $this->assertStringContainsString('<p>Field type [no&lt;i&gt;such&lt;/i&gt;type] does not exist.</p>', $alerts);
    }

    public function testAdminHtmlMessageIsAnHtmlStringInAnArray(): void
    {
        $message = admin_html_message('a <b>bold</b> word');

        $this->assertIsArray($message);
        $this->assertCount(1, $message);
        $this->assertInstanceOf(\Illuminate\Support\HtmlString::class, $message[0]);
        $this->assertSame('a <b>bold</b> word', $message[0]->toHtml());

        admin_info('title', admin_html_message('a <b>bold</b> word'));
        $this->assertStringContainsString('a <b>bold</b> word', view('admin::partials.alerts')->render());

        admin_toastr(admin_html_message('<b>saved</b>'));
        $this->assertStringContainsString(strval(json_encode('<b>saved</b>', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)), view('admin::partials.toastr')->render());
    }
}
