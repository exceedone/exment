<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The on/off switch of the Excel-like keyboard mode in the data list toolbar
 * (Grid\Tools\GridKeyboard).
 *
 * The mode itself runs in the browser (grid_tools.js) and is remembered in
 * localStorage, so the server's whole part is to render the switch: one
 * button per grid, tied to that grid's table, off until the browser says
 * otherwise.
 */
class GridKeyboardToggleTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * The data list carries the switch once, pointing at the grid's own table.
     */
    public function testToggleRenderedOnDataList(): void
    {
        $content = $this->pageContent(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST));

        $this->assertSame(1, preg_match_all('/class="[^"]*\bexm-grid-kbd\b[^"]*"\s+data-grid="([^"]+)"/', $content, $matches), 'one keyboard switch per grid');
        $gridId = $matches[1][0];
        $this->assertMatchesRegularExpression('/<table[^>]*\bid="' . preg_quote($gridId, '/') . '"/', $content, 'the switch must point at the grid table');
        $this->assertSame(1, preg_match_all('/<button[^>]*\bexm-kbd-toggle\b[^>]*>/', $content, $buttons), 'the switch is a single button');
        $this->assertStringContainsString('type="button"', $buttons[0][0], 'a real button, so the keyboard can press it');
    }

    /**
     * Off by default: the server never turns the mode on, and says so to
     * assistive technology. Turning it on is the browser's stored choice.
     */
    public function testToggleStartsOff(): void
    {
        $content = $this->pageContent(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST));

        preg_match('/<button[^>]*\bexm-kbd-toggle\b[^>]*>/', $content, $button);
        $tag = $button[0] ?? '';
        $this->assertNotSame('', $tag, 'the switch must be on the data list');
        $this->assertStringContainsString('aria-pressed="false"', $tag);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bactive\b/', $tag, 'the switch must not be rendered pressed');
        $this->assertStringNotContainsString('exm-kbd-on', $content, 'the grid must not be rendered in keyboard mode');
    }

    /**
     * The button has no text, so its name and the keys it enables are in the
     * title and the accessible label.
     */
    public function testToggleNamesTheModeAndItsKeys(): void
    {
        $content = html_entity_decode($this->pageContent(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)));

        $label = exmtrans('common.grid_keyboard') . ' (' . exmtrans('common.grid_keyboard_hint') . ')';
        $this->assertStringContainsString('title="' . $label . '"', $content);
        $this->assertStringContainsString('aria-label="' . $label . '"', $content);
    }

    /**
     * Both languages name the switch and its keys.
     */
    public function testLabelsExistInBothLanguages(): void
    {
        foreach (['ja', 'en'] as $locale) {
            foreach (['grid_keyboard', 'grid_keyboard_hint'] as $key) {
                $text = trans('exment::exment.common.' . $key, [], $locale);
                $this->assertIsString($text);
                $this->assertNotSame('exment::exment.common.' . $key, $text, "{$key} is missing in {$locale}");
            }
        }
        $this->assertSame('Excel 風に編集', trans('exment::exment.common.grid_keyboard', [], 'ja'));
        $this->assertSame('Excel-like editing', trans('exment::exment.common.grid_keyboard', [], 'en'));
    }

    protected function pageContent(string $url): string
    {
        $response = $this->get($url);
        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertIsString($content);

        return $content;
    }
}
