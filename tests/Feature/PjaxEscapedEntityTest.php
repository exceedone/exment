<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use ExmentAdminCore\Admin\Middleware\Pjax;
use Illuminate\Http\Response;

/**
 * A page opened through PJAX must keep user input escaped.
 *
 * Bug: the Pjax middleware of exment-admin-core decoded every numeric entity (&#NN;) of the
 * raw page HTML it cuts out between the pjax markers, so a value stored as "&#60;img ...&#62;"
 * (printed as is by Blade, which does not double-encode) became a live <img> tag.
 * v6 (laravel-admin) re-serialized the page with DomCrawler first, so the value stayed escaped.
 */
class PjaxEscapedEntityTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    private const PJAX_HEADERS = ['X-PJAX' => 'true', 'X-PJAX-Container' => '#pjax-container'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testPjaxPageKeepsEntityEncodedUserInputEscaped(): void
    {
        $this->setAdminUserName('evd&#60;img src=x onerror=alert(31)&#62;');

        $pjax = $this->get($this->loginUserEditUrl(), self::PJAX_HEADERS);

        $pjax->assertOk();
        $html = (string) $pjax->getContent();
        $this->assertStringNotContainsString('<img src=x onerror=alert(31)>', $html);
        $this->assertStringContainsString('evd&#60;img src=x onerror=alert(31)&#62;', $html);
    }

    public function testPjaxPageStillShowsMultibyteText(): void
    {
        $this->setAdminUserName('管理者テスト');

        $pjax = $this->get($this->loginUserEditUrl(), self::PJAX_HEADERS);

        $pjax->assertOk();
        $this->assertStringContainsString('管理者テスト', (string) $pjax->getContent());
    }

    /**
     * Without the pjax markers the middleware falls back to DomCrawler, like v6: multibyte text
     * the crawler turns into entities is decoded back, an escaped "<" stays escaped.
     */
    public function testCrawlerFallbackDecodesMultibyteTextOnly(): void
    {
        $html = '<html><head><title>t</title></head><body><div id="pjax-container">'
            . '<p>日本語</p><p>&#60;b&#62;x&#60;/b&#62;</p></div></body></html>';
        $response = new Response($html);

        $filter = new \ReflectionMethod(Pjax::class, 'filterResponse');
        $filter->invoke(new Pjax(), $response, '#pjax-container');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('日本語', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    /**
     * DomCrawler leaves <script> text as is, so an entity-encoded "</script>" there reaches the
     * decoder untouched. Only non-ASCII entities may be decoded, or the value breaks out of the script.
     */
    public function testCrawlerFallbackKeepsAsciiEntitiesInScript(): void
    {
        $html = '<html><head><title>t</title></head><body><div id="pjax-container">'
            . '<script>var a = "&#60;/script&#62;&#60;img src=x onerror=alert(31)&#62;&#26085;";</script>'
            . '</div></body></html>';
        $response = new Response($html);

        $filter = new \ReflectionMethod(Pjax::class, 'filterResponse');
        $filter->invoke(new Pjax(), $response, '#pjax-container');

        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('<img src=x onerror=alert(31)>', $html);
        $this->assertStringContainsString('&#60;/script&#62;&#60;img src=x onerror=alert(31)&#62;', $html);
        $this->assertStringContainsString('日"', $html);
    }

    private function setAdminUserName(string $name): void
    {
        $login = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user = CustomTable::getEloquent('user')->getValueModel($login->base_user_id);
        $user->setValue('user_name', $name);
        $user->saveQuietly();
        System::clearRequestSession();
    }

    private function loginUserEditUrl(): string
    {
        return admin_url('loginuser/' . TestDefine::TESTDATA_USER_LOGINID_ADMIN . '/edit');
    }
}
