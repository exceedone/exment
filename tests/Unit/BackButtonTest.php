<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\BackButton;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * ISS00363: where the "back" button of a page goes.
 * (a) a page of a resource => its list, (b) a screen under another one => the one above it,
 * (c) any other page => the page before it (of this site, not the same page),
 * and the pages with no button.
 */
class BackButtonTest extends UnitTestBase
{
    public function testAResourcePageGoesToItsList(): void
    {
        $this->assertSame(admin_url('table'), BackButton::destination('table/create'));
        $this->assertSame(admin_url('table'), BackButton::destination('table/5/edit'));
        $this->assertSame(admin_url('table'), BackButton::destination('table/5'));
        $this->assertSame(admin_url('plugin'), BackButton::destination('plugin/3/edit'));
        $this->assertSame(admin_url('loginuser'), BackButton::destination('loginuser/12/edit'));
        $this->assertSame(admin_url('role_group'), BackButton::destination('role_group/create'));
        $this->assertSame(admin_url('auth/menu'), BackButton::destination('auth/menu/4/edit'));

        // the list of a table
        $this->assertSame(admin_url('column/book'), BackButton::destination('column/book/create'));
        $this->assertSame(admin_url('column/book'), BackButton::destination('column/book/7/edit'));
        $this->assertSame(admin_url('form/book'), BackButton::destination('form/book/2/edit'));
        $this->assertSame(admin_url('view/book'), BackButton::destination('view/book/create'));
        $this->assertSame(admin_url('workflow/3/notify'), BackButton::destination('workflow/3/notify/create'));
    }

    public function testTheDataPagesAreLeftToTheDataController(): void
    {
        // CustomValueController draws them, with the remembered list
        $this->assertNull(BackButton::destination('data/book/create'));
        $this->assertNull(BackButton::destination('data/book/5'));
        $this->assertNull(BackButton::destination('data/book/5/edit'));
    }

    public function testAScreenUnderAnotherOneGoesToIt(): void
    {
        foreach (['column', 'form', 'formpriority', 'formpublic', 'view', 'relation', 'copy', 'operation', 'notify', 'data_maintenance'] as $endpoint) {
            $this->assertSame(admin_url('table'), BackButton::destination("{$endpoint}/book"), $endpoint);
        }
        $this->assertSame(admin_url('plugin'), BackButton::destination('plugin/edit_code/3'));
        $this->assertSame(admin_url('workflow'), BackButton::destination('workflow/3/notify'));
        $this->assertSame(admin_url('workflow'), BackButton::destination('workflow/3/design'));
        $this->assertSame(admin_url('workflow'), BackButton::destination('workflow/design/create'));
        $this->assertSame(admin_url('auth/menu'), BackButton::destination('auth/menu_arrange'));
        $this->assertSame(admin_url('studio'), BackButton::destination('studio/screens/4/edit'));
        $this->assertSame(admin_url('/'), BackButton::destination('dashboard/create'));
        $this->assertSame(admin_url('/'), BackButton::destination('dashboard/2/edit'));
    }

    public function testAnyOtherPageGoesToThePageBefore(): void
    {
        $current = admin_url('data/book/5/compare');
        $previous = admin_url('data/book/5') . '?a=1';
        $this->assertSame($previous, BackButton::destination('data/book/5/compare', $previous, $current));

        // not of this site
        $this->assertNull(BackButton::destination('data/book/5/compare', 'https://other.example.com/admin/table', $current));
        $this->assertNull(BackButton::destination('data/book/5/compare', 'javascript:alert(1)', $current));
        // the same page
        $this->assertNull(BackButton::destination('data/book/5/compare', $current . '?page=2', $current));
        // nothing before
        $this->assertNull(BackButton::destination('data/book/5/compare', null, $current));
        $this->assertNull(BackButton::destination('data/book/5/compare', '', $current));
    }

    public function testNoButtonOnTheTopPagesAndTheMenuLists(): void
    {
        $previous = admin_url('table');
        foreach (['', '/', 'dashboard/3', 'data/book', 'table', 'loginuser', 'plugin', 'system', 'workflow', 'notify', 'auth/menu', 'auth/logs', 'auth/setting', 'plugins/my_page'] as $path) {
            $this->assertNull(BackButton::destination($path, $previous, admin_url('x/y/z')), "'{$path}'");
        }
    }

    public function testNoButtonOnLoginPagesAndWhatIsNotAPage(): void
    {
        $previous = admin_url('table');
        foreach ([
            'auth/login', 'auth/login/google', 'auth/logout', 'auth/forget', 'auth/reset/abc', 'auth/change', 'auth-2factor/google/register',
            'saml/login/x', 'initialize', 'install', 'api/data/book/5', 'webapi/data/book/5', 'files/abc', 'tmpfiles/abc', 'favicon',
            'vendor/exment/js/common.js',
        ] as $path) {
            $this->assertNull(BackButton::destination($path, $previous, admin_url($path)), "'{$path}'");
        }
    }

    public function testTheRequestsWithNoButton(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $url = admin_url('table/5/edit');
        $this->assertSame(admin_url('table'), BackButton::destinationForRequest(Request::create($url, 'GET')));

        // pjax is a page
        $this->assertSame(admin_url('table'), BackButton::destinationForRequest(Request::create($url, 'GET', [], [], [], [
            'HTTP_X_PJAX' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ])));

        // not GET, ajax, json, modal and frame
        $this->assertNull(BackButton::destinationForRequest(Request::create($url, 'POST')));
        $this->assertNull(BackButton::destinationForRequest(Request::create($url, 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])));
        $this->assertNull(BackButton::destinationForRequest(Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json'])));
        foreach (BackButton::FRAME_KEYS as $key) {
            $this->assertNull(BackButton::destinationForRequest(Request::create($url, 'GET', [$key => 1])), $key);
        }

        // the page before comes from the referer
        $previous = admin_url('table/5');
        $this->assertSame($previous, BackButton::destinationForRequest(Request::create(admin_url('data/book/5/compare'), 'GET', [], [], [], [
            'HTTP_REFERER' => $previous,
        ])));
    }

    public function testTheScriptKeepsTheLookAndTheGuard(): void
    {
        $script = BackButton::script('https://example.com/admin/data/a?x=1&y=2', '戻る</script>');
        $this->assertStringContainsString('exm-back-to-list', $script);
        $this->assertStringContainsString("h1.find('.exm-back-to-list').length", $script);
        $this->assertStringContainsString(BackButton::jsString('https://example.com/admin/data/a?x=1&y=2'), $script);
        $this->assertStringNotContainsString('</script>', $script);
    }
}
