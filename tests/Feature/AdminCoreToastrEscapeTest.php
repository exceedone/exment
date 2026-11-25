<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

/**
 * A toast message (admin_toastr) is text: the toast partial of exment-admin-core writes it as
 * JSON, so a quote or "</script>" in it does not end the script. (Needs exment-admin-core 1.0.5
 * or later.)
 */
class AdminCoreToastrEscapeTest extends FeatureTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testTheMessageDoesNotBreakOutOfTheScript(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();

        $message = "x');document.title='broken';('</script><b class=\"escape-check\">";
        admin_toastr($message, 'error');
        $html = (string)$this->get(admin_url(''))->getContent();

        $this->assertStringNotContainsString("('x');document.title='broken'", $html);
        $this->assertStringNotContainsString('<b class="escape-check">', $html);
        $this->assertStringContainsString('toastr["error"]', $html);
    }
}
