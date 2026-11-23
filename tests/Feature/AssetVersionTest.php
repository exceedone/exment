<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginPublicBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The URLs of the JS and CSS change with an update, so that the browser does not keep the
 * old ones (no Ctrl+F5 after an update).
 */
class AssetVersionTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testExmentFilesCarryTheVersionAndTheTimeOfTheFile(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $content = (string) $this->get(admin_url(''))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#vendor/exment/js/common\.js\?ver=[^"&]+&(amp;)?v=\d+"#', $content);
        $this->assertMatchesRegularExpression('#vendor/exment/js/customscript\.js\?ver=[^"&]+(&(amp;)?v=\d+)?"#', $content);
    }

    public function testTheFilesOfAPluginCarryItsVersion(): void
    {
        $plugin = new Plugin();
        $plugin->plugin_name = 'TestAssetVersion';
        $plugin->version = '1.2.3';
        $plugin->options = ['uri' => 'test_asset_version'];
        $public = new class ($plugin) extends PluginPublicBase {
            public function __construct(Plugin $plugin)
            {
                $this->plugin = $plugin;
            }
        };
        $url = strval($public->getCssJsUrl('js/app.js'));
        $this->assertStringEndsWith('public/js/app.js?ver=1.2.3', $url);
    }
}
