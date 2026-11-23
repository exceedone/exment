<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomColumns\Url;
use Exceedone\Exment\Services\WebhookUrlGuard;

class CoreHardening3Test extends UnitTestBase
{
    protected function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testUrlSchemes(): void
    {
        $item = (new \ReflectionClass(Url::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Url::class, 'isValidUrlScheme');
        $method->setAccessible(true);

        foreach (['https://exment.net/', 'http://example.com/a', '/admin/data', '#top', '', null] as $url) {
            $this->assertTrue($method->invoke($item, $url), strval($url));
        }
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,a', 'vbscript:x'] as $url) {
            $this->assertFalse($method->invoke($item, $url), $url);
        }
    }

    public function testThePrivateRangesCanBeRefused(): void
    {
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://127.0.0.1/a.png', true));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://10.0.0.1/a.png', true));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://169.254.169.254/latest', true));
    }

    public function testTheAvatarIsNotFetchedFromAPrivateAddress(): void
    {
        $source = $this->read('src/Services/Login/LoginService.php');
        $this->assertStringContainsString('WebhookUrlGuard::isAllowed($custom_login_user->avatar, true)', $source);
    }

    public function testTheComposerPathIsQuotedWhenItIsAFile(): void
    {
        $source = $this->read('src/Services/Update/UpdateHelper.php');
        $this->assertStringContainsString('$composer = escapeshellarg($composer);', $source);
    }

    public function testATemplateNameIsOneFolder(): void
    {
        $source = $this->read('src/Services/TemplateImportExport/TemplateImporter.php');
        $this->assertStringContainsString("in_array(\$teplate_name, \$disk->directories(), true)", $source);
    }

    public function testAWebRequestWithoutAUserSeesNoUserTableRows(): void
    {
        $source = $this->read('src/Model/CustomValueModelScope.php');
        $this->assertStringContainsString("\$builder->whereRaw('1 = 0');", $source);
    }
}
