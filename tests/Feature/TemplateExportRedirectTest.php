<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * GET admin/template/export is a bare closure (RouteServiceProvider.php) that only
 * redirects to the template screen - the actual export runs through
 * TemplateController::export() (POST), which this route never calls.
 */
class TemplateExportRedirectTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testExportRedirectsToTheTemplateScreen(): void
    {
        $this->get(admin_url('template/export'))->assertRedirect(admin_url('template'));
    }
}
