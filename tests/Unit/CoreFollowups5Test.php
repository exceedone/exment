<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\PluginController;
use Exceedone\Exment\Services\WebhookUrlGuard;

class CoreFollowups5Test extends UnitTestBase
{
    public function testMulticastIsNeverATarget(): void
    {
        $this->assertFalse(WebhookUrlGuard::isAllowed('https://[ff02::1]/x', true));
        $this->assertFalse(WebhookUrlGuard::isAllowed('https://[ff02::1]/x'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://224.0.0.1/x'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://239.255.255.250/x'));
    }

    public function testTheComposerPathIsOneQuotedArgument(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Update/UpdateHelper.php');
        $this->assertStringContainsString('escapeshellarg(\Exment::getComposerPath())', $source);
    }

    public function testAPluginUriIsLowerCase(): void
    {
        foreach (['my_plugin', 'plugin-1', 'a', '0abc'] as $uri) {
            $this->assertSame(1, preg_match(PluginController::URI_PATTERN, $uri), $uri);
        }
        foreach (['PLUGIN', 'MyPlugin', '-a', '_a', 'a b', 'a/b', 'あ', ''] as $uri) {
            $this->assertSame(0, preg_match(PluginController::URI_PATTERN, $uri), $uri);
        }
    }
}
