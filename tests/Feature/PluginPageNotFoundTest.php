<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * A plugin page that is not there answers 404 with the error page of Exment, as a table that
 * is not there does. The files of a plugin (public/) stay a plain 404.
 */
class PluginPageNotFoundTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'no such plugin' => ['plugins/no_such_plugin'],
            'a page of it' => ['plugins/no_such_plugin/page'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function testTheErrorPageOfExment(string $uri): void
    {
        $html = (string) $this->get(admin_url($uri))->assertNotFound()->getContent();
        $this->assertStringContainsString(e(exmtrans('error.header')), $html, "$uri: the error page of Exment");
    }

    public function testAFileOfAPluginIsAPlain404(): void
    {
        $html = (string) $this->get(admin_url('plugins/no_such_plugin/public/x.css'))->assertNotFound()->getContent();
        $this->assertStringNotContainsString(e(exmtrans('error.header')), $html);
    }
}
