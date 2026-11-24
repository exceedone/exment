<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Validator\PluginNamespaceRule;

class CoreFixes3Test extends UnitTestBase
{
    public function testTheRedirectRoutesAreControllers(): void
    {
        foreach (['template/import', 'template/export', 'role'] as $uri) {
            $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(function ($route) use ($uri) {
                return in_array('GET', $route->methods(), true) && str_ends_with($route->uri(), $uri);
            });
            $this->assertNotNull($route, $uri);
            $this->assertIsString($route->getAction('uses'), "$uri: a controller, so that route:cache works");
        }
    }

    public function testAnEncodedPluginFileIsNotReadForItsNamespace(): void
    {
        $rule = (new \ReflectionClass(PluginNamespaceRule::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PluginNamespaceRule::class, 'isEncodedPhpFile');
        $method->setAccessible(true);

        $line = str_repeat('QUJDREVGR0g', 7) . "\n";
        $encoded = "<?php if(!extension_loaded('ionCube Loader')){die('loader');} ?>\n" . $line . $line . $line;
        $this->assertTrue($method->invoke($rule, $encoded));
        $this->assertFalse($method->invoke($rule, "<?php\nnamespace App\\Plugins\\Sample;\nclass Plugin {}\n"));
        $this->assertFalse($method->invoke($rule, ''));
    }

    public function testPluginUploadCanBeTurnedOff(): void
    {
        $this->assertTrue(config('exment.plugin_upload_enabled'));
        foreach (['src/Controllers/PluginController.php', 'src/Controllers/PluginCodeController.php'] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            $this->assertStringContainsString("if (!config('exment.plugin_upload_enabled', true)) {", $source, $path);
        }
    }
}
