<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The record id in an edit URL must not break out of the HTML it is reflected into.
 *
 * Bug: the form action, the list/view/delete tool links and the delete ajax url are built from the
 * raw request path (ExmentAdminCore\Admin\Traits\Resource::getResource -> getPathInfo). admin-core
 * wrote them into HTML without escaping, so an id such as `1"><img src=1 onerror=alert(1)>` closed
 * the attribute and injected a live tag (reflected XSS reachable by a crafted link). MySQL casts the
 * id to 1, so the record still loads and the page renders with the payload.
 */
class FormActionXssTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    private const PAYLOAD = '1"><img src=1 onerror=alert(31)>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testEditPageEscapesACraftedIdInThePath(): void
    {
        $url = admin_url('data/' . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL . '/' . rawurlencode(self::PAYLOAD) . '/edit');

        $html = (string) $this->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=1 onerror=alert(31)>', $html);
        // the payload is still present, but as escaped text
        $this->assertStringContainsString('&lt;img src=1 onerror=alert(31)&gt;', $html);
    }

    public function testANormalEditPageStillTargetsItsRecord(): void
    {
        $id = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel()->query()->value('id');

        $html = (string) $this->get(admin_url('data/' . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL . '/' . $id . '/edit'))
            ->assertOk()->getContent();

        $action = admin_url('data/' . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL . '/' . $id);
        $this->assertStringContainsString('action="' . $action . '"', $html);
    }
}
