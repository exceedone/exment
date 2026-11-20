<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The phone app of the Studio (ISS00116): the page, its manifest and its service worker.
 */
class StudioAppTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testTheAppPageOpensForAnyUser(): void
    {
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER2] as $id) {
            $this->be(LoginUser::findOrFail($id));
            $page = $this->get(admin_url('app'));
            $page->assertOk();
            $page->assertSee(exmtrans('studio.app_title'));
            // the screens come from the JSON of the Studio, the data from the data API of the session
            $page->assertSee('/studio/screens', false);
            $page->assertSee(strval(json_encode(admin_url('webapi'))), false);
            // the manifest is fetched with the login
            $page->assertSee('rel="manifest" href="' . admin_url('app/manifest.webmanifest') . '" crossorigin="use-credentials"', false);
        }
    }

    public function testTheManifestAndTheServiceWorker(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $manifest = $this->get(admin_url('app/manifest.webmanifest'));
        $manifest->assertOk();
        $this->assertStringStartsWith('application/manifest+json', strval($manifest->headers->get('Content-Type')));
        $manifest->assertJsonPath('display', 'standalone');
        $manifest->assertJsonPath('start_url', admin_url('app'));

        $worker = $this->get(admin_url('app/sw.js'));
        $worker->assertOk();
        $this->assertStringStartsWith('application/javascript', strval($worker->headers->get('Content-Type')));
        $worker->assertSee("addEventListener('fetch'", false);
    }

    public function testTheAppNeedsTheLogin(): void
    {
        $this->get(admin_url('app'))->assertRedirect();
    }
}
