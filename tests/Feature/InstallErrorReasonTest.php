<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Services\Installer\InstallService;
use Illuminate\Support\ViewErrorBag;

/**
 * The setup says why it failed (2026-11-23): the reason of a failed database
 * connection is shown and logged without the password, and the last page
 * tells what to do when a long install ends in an error page.
 */
class InstallErrorReasonTest extends FeatureTestBase
{
    public function testTheReasonOfAFailedConnectionHasNoPassword(): void
    {
        $ex = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'exment'@'10.0.0.5' (using password: YES) s3cret-pass\n#0 /var/www/vendor/...");
        $reason = InstallService::connectionErrorReason($ex, 's3cret-pass');
        $this->assertStringContainsString('SQLSTATE[HY000] [1045] Access denied', $reason);
        $this->assertStringNotContainsString('s3cret-pass', $reason);
        $this->assertStringNotContainsString('#0', $reason, 'only the first line');

        $long = InstallService::connectionErrorReason(new \Exception(str_repeat('x', 1000)));
        $this->assertLessThanOrEqual(300, mb_strwidth($long));

        $this->assertSame('SQLSTATE[HY000] [2002] Connection refused', InstallService::connectionErrorReason(new \Exception('SQLSTATE[HY000] [2002] Connection refused'), ''));
    }

    public function testTheLastPageSaysWhatToDoAfterATimeout(): void
    {
        $html = view('exment::install.installing')->with('errors', new ViewErrorBag())->render();
        $this->assertStringContainsString(e(exmtrans('install.help.installing_timeout')), $html);
    }
}
