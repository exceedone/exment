<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Form\Field\Tinymce;

/**
 * The editor (HTML) is TinyMCE 8 (ISS00404: 5.10.9 was out of support, with XSS fixes only
 * in 6.8 and later): the files Exment ships, its settings (no plugin or name of TinyMCE 5,
 * the GPL key) and the language of the editor of each locale.
 */
class TinymceEditorTest extends FeatureTestBase
{
    protected function path(string $file = ''): string
    {
        return exment_package_path('public/vendor/exment/tinymce' . ($file === '' ? '' : '/' . $file));
    }

    public function testTheFilesOfTinymce8(): void
    {
        $this->assertFileExists($this->path('tinymce.min.js'));
        $this->assertMatchesRegularExpression('/majorVersion:"8"/', (string)file_get_contents($this->path('tinymce.min.js')));
        // the license and the notices of TinyMCE, and of the language packs
        $this->assertStringContainsString('GNU General Public License Version 2 or later', (string)file_get_contents($this->path('license.md')));
        $this->assertFileExists($this->path('notices.txt'));
        $this->assertStringContainsString('MIT License', (string)file_get_contents($this->path('langs/LICENSE.md')));
        // what TinyMCE 6+ loads: the model, the theme, the icons, the skins, our plugins
        foreach ([
            'models/dom/model.min.js',
            'themes/silver/theme.min.js',
            'icons/default/icons.min.js',
            'skins/ui/oxide/skin.min.css',
            'skins/ui/oxide/content.min.css',
            'skins/content/default/content.min.css',
            'plugins/link/plugin.min.js',
            'plugins/lists/plugin.min.js',
            'plugins/code/plugin.min.js',
            'plugins/image/plugin.min.js',
        ] as $file) {
            $this->assertFileExists($this->path($file));
        }
        // the plugins of TinyMCE 5 that are no more (in the core, or gone)
        foreach (['paste', 'hr', 'textcolor', 'colorpicker', 'contextmenu', 'imagetools', 'template', 'print'] as $plugin) {
            $this->assertDirectoryDoesNotExist($this->path('plugins/' . $plugin));
        }
        $this->assertFileDoesNotExist($this->path('jquery.tinymce.min.js'));
    }

    public function testTheLanguageOfEachLocale(): void
    {
        $this->assertSame('ja', Tinymce::tinymceLanguage('ja'));
        $this->assertSame('de', Tinymce::tinymceLanguage('de'));
        $this->assertSame('es', Tinymce::tinymceLanguage('es'));
        $this->assertSame('vi', Tinymce::tinymceLanguage('vi'));
        $this->assertSame('ko-KR', Tinymce::tinymceLanguage('ko'));
        $this->assertSame('zh-CN', Tinymce::tinymceLanguage('zh_CN'));
        // English is in TinyMCE itself
        $this->assertSame('en', Tinymce::tinymceLanguage('en'));
        $this->assertSame('en', Tinymce::tinymceLanguage(null));
        $this->assertSame('en', Tinymce::tinymceLanguage('xx'));

        foreach (['ja', 'de', 'es', 'vi', 'ko-KR', 'zh-CN'] as $language) {
            // the name in the file is the name TinyMCE is given
            $this->assertStringStartsWith('tinymce.addI18n("' . $language . '"', (string)file_get_contents($this->path('langs/' . $language . '.js')));
        }
    }

    public function testTheSettings(): void
    {
        \App::setLocale('ja');
        $configs = (new Tinymce('content', ['content']))->tinymceConfigs();

        $this->assertSame('gpl', $configs['license_key']);
        $this->assertSame('ja', $configs['language']);
        $this->assertFalse($configs['readonly']);
        $this->assertFalse($configs['promotion']);
        $this->assertTrue($configs['paste_data_images']);
        $this->assertTrue($configs['automatic_uploads']);
        $this->assertSame('link lists code image', $configs['plugins']);
        $this->assertArrayNotHasKey('paste_enable_default_filters', $configs);

        $toolbar = implode(' ', $configs['toolbar']);
        foreach (['blocks', 'fontfamily', 'fontsize', 'forecolor', 'hr', 'image', 'code'] as $button) {
            $this->assertMatchesRegularExpression('/(^| )' . $button . '( |$)/', $toolbar);
        }
        // the names of TinyMCE 5
        foreach (['formatselect', 'fontselect', 'fontsizeselect'] as $button) {
            $this->assertStringNotContainsString($button, $toolbar);
        }
    }

    public function testWithoutImagesAndReadOnly(): void
    {
        $configs = (new Tinymce('content', ['content']))->disableImage()->tinymceConfigs();
        $this->assertFalse($configs['paste_data_images']);
        $this->assertArrayNotHasKey('automatic_uploads', $configs);
        $this->assertStringNotContainsString(' image ', ' ' . implode(' ', $configs['toolbar']) . ' ');

        $configs = (new Tinymce('content', ['content']))->readonly()->tinymceConfigs();
        $this->assertTrue($configs['readonly']);
        $this->assertFalse($configs['toolbar']);
    }

    public function testTheScriptUploadsWithAPromise(): void
    {
        $field = new Tinymce('content', ['content']);
        $field->render();
        $script = (string)(new \ReflectionProperty($field, 'script'))->getValue($field);
        $this->assertStringContainsString('"license_key":"gpl"', $script);
        $this->assertStringContainsString("config['images_upload_handler'] = function(blobInfo, progress){", $script);
        $this->assertStringContainsString('return new Promise(', $script);
        $this->assertStringContainsString('tinymce.init(config);', $script);
    }
}
