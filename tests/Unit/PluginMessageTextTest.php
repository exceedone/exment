<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\ClassLoader;
use Illuminate\Support\Facades\View;

/**
 * The plugin messages show the plugin's name, a file path and an error as text:
 * the alerts print their message as html.
 */
class PluginMessageTextTest extends UnitTestBase
{
    public function testTheCannotReadMessageShowsThePluginNameAsText(): void
    {
        $plugin = new Plugin();
        $plugin->plugin_view_name = '<b onclick="x()">Sample</b>';

        $message = strval($plugin->getCannotReadMessage());

        $this->assertStringContainsString('&lt;b onclick=&quot;x()&quot;&gt;Sample&lt;/b&gt;', $message);
        $this->assertStringNotContainsString('<b', $message);
    }

    public function testTheCannotReadMessageWithoutAName(): void
    {
        $message = strval((new Plugin())->getCannotReadMessage());
        $this->assertNotSame('', $message);
    }

    public function testTheClassLoadErrorShowsTheErrorAsText(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exment_plugin_message_' . uniqid();
        mkdir($dir);
        $file = $dir . DIRECTORY_SEPARATOR . 'Broken.php';
        file_put_contents($file, "<?php\nthrow new \\RuntimeException('<i>broken</i>');\n");

        try {
            $loader = new ClassLoader();
            $loader->registerDir($dir, 'PluginMessageTextTest');
            $loader->loadClass('PluginMessageTextTest\\Broken');

            $bag = View::shared('alert_error');
            $this->assertNotNull($bag);
            $message = strval(array_get($bag->get('message'), 0));
            $this->assertStringContainsString('&lt;i&gt;broken&lt;/i&gt;', $message);
            $this->assertStringNotContainsString('<i>', $message);
            // the message's own line breaks stay
            $this->assertStringContainsString('<br />', $message);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
