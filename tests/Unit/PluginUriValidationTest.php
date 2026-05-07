<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Integration Test: Plugin URI Field Validation Bug
 *
 * ■ Bug Overview
 *   When entering Japanese text, emoji, uppercase, spaces, or special characters
 *   in the URI field on the Basic Settings screen, clicking the Endpoint (Page)
 *   button causes a routing error.
 *
 * ■ Root Cause
 *   The uri field in PluginController::form() only has ->required() and
 *   lacks character type validation.
 *
 * ■ Expected Fix
 *   Add the following rule to the uri field:
 *     ->rules('regex:/^[a-z0-9][a-z0-9_\-]*$/')
 *
 * ■ Test Strategy
 *   - Submit forms via HTTP PUT and verify DB values directly
 *   - "Invalid URI" tests currently FAIL (bug exists) → Will PASS after fix
 *   - "Valid URI" tests PASS before and after fix
 *   - Verify that Plugin::getOptionUri() generates unsafe URLs with invalid input
 *
 * ■ Target Plugins
 *   URI field is only shown for PAGE / API / CRUD types.
 *   Test data: TestPluginPage (page), TestPluginApi (api)
 */
class PluginUriValidationTest extends UnitTestBase
{
    use DatabaseTransactions;

    /** Regex rule that should be added to the URI field */
    private const URI_REGEX = '/^[a-z0-9][a-z0-9_\-]*$/';

    private const PLUGIN_PAGE = 'TestPluginPage';
    private const PLUGIN_API  = 'TestPluginApi';

    // =========================================================================
    // Setup
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // =========================================================================
    // Data Providers
    // =========================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function validUriProvider(): array
    {
        return [
            'lowercase only'     => ['myplugin'],
            'with underscore'    => ['my_plugin'],
            'with hyphen'        => ['my-plugin'],
            'starts with digit'  => ['123plugin'],
            'complex valid'      => ['test_plugin_page'],
            'hyphen and version' => ['plugin-v2'],
            'single char'        => ['a'],
            'alphanumeric mixed' => ['abc123def'],
            'all digits'         => ['1234'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidUriProvider(): array
    {
        return [
            // ── Core bug scenario: Japanese ──────────────────────────────────
            'Japanese katakana'        => ['テスト',           'Japanese katakana'],
            'Japanese plugin label'    => ['プラグイン',        'Japanese plugin label'],
            'Japanese hiragana mixed'  => ['日本語テスト',      'Japanese hiragana mixed'],
            'Japanese + ASCII'         => ['テストPlugin',      'Japanese + ASCII mixed'],

            // ── Emoji ────────────────────────────────────────────────────────
            'emoji prefix'             => ['😀plugin',          'Emoji prefix'],
            'emoji suffix'             => ['plugin🎮',          'Emoji suffix'],
            'emoji only'               => ['🎮',                'Emoji only'],

            // ── Uppercase ────────────────────────────────────────────────────
            'uppercase only'           => ['PLUGIN',            'All uppercase'],
            'PascalCase'               => ['TestPlugin',        'PascalCase'],
            'mixed case'               => ['MyPlugin',          'Mixed case with uppercase'],
            'uppercase and Japanese'   => ['Test プラグイン',   'Uppercase + Japanese mixed'],

            // ── Spaces ───────────────────────────────────────────────────────
            // Note: leading/trailing spaces (' plugin', 'plugin ') are intentionally
            // excluded — Laravel's TrimStrings middleware auto-trims them to 'plugin'
            // (a valid URI), so they are not rejected by validation.
            'space in middle'          => ['my plugin',         'Space in middle'],
            'multiple spaces'          => ['test plugin page',  'Multiple spaces'],
            'tab character'            => ["my\tplugin",        'Tab character'],

            // ── Special characters ───────────────────────────────────────────
            'exclamation mark'         => ['my!plugin',         'Exclamation mark'],
            'at sign'                  => ['test@plugin',       'At sign'],
            'hash'                     => ['plugin#1',          'Hash symbol'],
            'forward slash'            => ['test/plugin',       'Forward slash'],
            'dot'                      => ['test.plugin',       'Dot character'],
            'plus sign'                => ['test+plugin',       'Plus sign'],
            'starts with underscore'   => ['_plugin',           'Starts with underscore'],
            'starts with hyphen'       => ['-plugin',           'Starts with hyphen'],

            // ── Security cases ───────────────────────────────────────────────
            'XSS attempt'              => ['<script>alert(1)</script>', 'XSS attack attempt'],
            'SQL injection'            => ["' OR '1'='1",       'SQL injection attempt'],
            'path traversal'           => ['../etc/passwd',     'Path traversal attempt'],
            'double slash'             => ['test//plugin',      'Double slash'],
            'null-byte'                => ["plugin\0name",      'Null byte character'],
        ];
    }

    // =========================================================================
    // Group 1: Valid URIs — must be accepted (PASS before and after fix)
    // =========================================================================

    /**
     * Valid URIs should be saved to the database. (PAGE plugin)
     *
     * @dataProvider validUriProvider
     */
    public function testValidUriIsSaved_PagePlugin(string $uri): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);

        $this->putPluginUri($plugin, $uri);
        $plugin->refresh();

        $this->assertEquals(
            $uri,
            $plugin->getOption('uri'),
            "Valid URI '{$uri}' should be saved to the database."
        );
    }

    /**
     * Valid URIs should be saved to the database. (API plugin)
     *
     * @dataProvider validUriProvider
     */
    public function testValidUriIsSaved_ApiPlugin(string $uri): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_API);

        $this->putPluginUri($plugin, $uri);
        $plugin->refresh();

        $this->assertEquals(
            $uri,
            $plugin->getOption('uri'),
            "Valid URI '{$uri}' should be saved to the database."
        );
    }

    /**
     * Updating with the same URI should save successfully (idempotency).
     */
    public function testSameUriUpdateIsIdempotent(): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);
        $currentUri = $plugin->getOption('uri') ?? 'testpluginpage';

        $this->putPluginUri($plugin, $currentUri);
        $plugin->refresh();

        $this->assertEquals($currentUri, $plugin->getOption('uri'));
    }

    // =========================================================================
    // Group 2: Invalid URIs — must be REJECTED
    //   ★ CURRENTLY FAIL (BUG) → Will PASS after validation fix ★
    // =========================================================================

    /**
     * Invalid URIs should NOT be saved to DB after form submission. (PAGE plugin)
     *
     * [Current Status: FAIL = Bug exists]
     *   PluginController::form() lacks regex validation, so invalid URIs
     *   are saved to DB as-is.
     *
     * [After Fix: PASS]
     *   Adding ->rules('regex:/^[a-z0-9][a-z0-9_\-]*$/') to the uri field
     *   will cause a validation error, preventing DB updates.
     *
     * @dataProvider invalidUriProvider
     */
    public function testInvalidUriIsRejected_PagePlugin(string $uri, string $description): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);

        // Set a known valid baseline first so the assertion is meaningful
        // regardless of what prior test runs may have left in the DB.
        $baseline = 'valid_baseline_uri';
        $this->putPluginUri($plugin, $baseline);
        $plugin->refresh();
        $this->assertEquals($baseline, $plugin->getOption('uri'), 'Baseline setup failed — valid URI was not saved.');

        $this->putPluginUri($plugin, $uri);
        $plugin->refresh();

        $this->assertEquals(
            $baseline,
            $plugin->getOption('uri'),
            "BUG: Invalid URI '{$uri}' ({$description}) was saved to DB without validation. "
                . "Fix: add ->rules('regex:" . self::URI_REGEX . "') "
                . "to the 'uri' field in PluginController::form()."
        );
    }

    /**
     * Invalid URIs should NOT be saved to DB after form submission. (API plugin)
     *
     * [Current Status: FAIL = Bug exists] [After Fix: PASS]
     *
     * @dataProvider invalidUriProvider
     */
    public function testInvalidUriIsRejected_ApiPlugin(string $uri, string $description): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_API);

        // Set a known valid baseline first.
        $baseline = 'valid_baseline_uri';
        $this->putPluginUri($plugin, $baseline);
        $plugin->refresh();
        $this->assertEquals($baseline, $plugin->getOption('uri'), 'Baseline setup failed — valid URI was not saved.');

        $this->putPluginUri($plugin, $uri);
        $plugin->refresh();

        $this->assertEquals(
            $baseline,
            $plugin->getOption('uri'),
            "BUG: Invalid URI '{$uri}' ({$description}) was saved to DB (API plugin). "
                . "Fix: add ->rules('regex:" . self::URI_REGEX . "') to PluginController::form()."
        );
    }

    /**
     * After submitting an invalid URI, the original URI should remain unchanged. (Rollback verification)
     *
     * [Current Status: FAIL = Bug exists] [After Fix: PASS]
     *
     * @dataProvider invalidUriProvider
     */
    public function testOriginalUriIsPreservedAfterInvalidSubmit(string $uri, string $description): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);

        // Establish a known valid baseline to be independent of prior test state
        // (avoids bleed caused by laravel-admin committing DB transactions internally).
        $baseline = 'valid_baseline_uri';
        $this->putPluginUri($plugin, $baseline);
        $plugin->refresh();
        $this->assertEquals($baseline, $plugin->getOption('uri'), 'Baseline setup failed — valid URI was not saved.');

        // Submit the invalid URI
        $this->putPluginUri($plugin, $uri);
        $plugin->refresh();

        // After fix: baseline must be unchanged
        $this->assertEquals(
            $baseline,
            $plugin->getOption('uri'),
            "BUG: Original URI '{$baseline}' was overwritten by invalid input '{$uri}' ({$description}). "
                . "Validation should have prevented the save."
        );
    }

    // =========================================================================
    // Group 3: Edge cases
    // =========================================================================

    /**
     * Empty URI should be rejected by ->required() rule. (PASS before and after fix)
     */
    public function testEmptyUriIsRejectedByRequired(): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);
        $originalUri = $plugin->getOption('uri');

        $this->putPluginUri($plugin, '');
        $plugin->refresh();

        $this->assertNotEquals(
            '',
            $plugin->getOption('uri'),
            "Empty URI should be rejected by the existing 'required' rule."
        );
    }

    /**
     * Extremely long URIs should be rejected.
     *
     * [Current Status: FAIL = Bug exists] [After Fix: PASS]
     */
    public function testExtremelyLongUriIsRejected(): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);
        $longUri = str_repeat('a', 256); // 256 chars

        $this->putPluginUri($plugin, $longUri);
        $plugin->refresh();

        $this->assertNotEquals(
            $longUri,
            $plugin->getOption('uri'),
            "Extremely long URI (256 chars) should be rejected. "
                . "Consider adding a max length rule in addition to regex."
        );
    }

    // =========================================================================
    // Group 4: Model-level — getOptionUri() returns unsafe URL string
    //          (These PASS regardless of controller fix — they document the root cause)
    // =========================================================================

    /**
     * When Japanese URI is set, getOptionUri() returns an unsafe URL string.
     *
     * This is the root cause of routing errors when clicking "Endpoint (Page)".
     */
    public function testGetOptionUri_JapaneseInputProducesUnsafeUrl(): void
    {
        $plugin = new Plugin();
        $plugin->setOption('uri', 'テスト');

        $uri = $plugin->getOptionUri();

        $this->assertFalse(
            preg_match(self::URI_REGEX, $uri) === 1,
            "Japanese URI 'テスト' produced '{$uri}' which is not URL-safe. "
                . "This causes routing errors when the endpoint is accessed."
        );
    }

    /**
     * When emoji URI is set, getOptionUri() returns an unsafe URL string.
     */
    public function testGetOptionUri_EmojiInputProducesUnsafeUrl(): void
    {
        $plugin = new Plugin();
        $plugin->setOption('uri', '😀plugin');

        $uri = $plugin->getOptionUri();

        $this->assertFalse(
            preg_match(self::URI_REGEX, $uri) === 1,
            "Emoji URI '😀plugin' produced '{$uri}' which is not URL-safe."
        );
    }

    /**
     * When URI containing spaces is set, getRootUrl() generates an endpoint string
     * that is not URL-safe. (PAGE type)
     */
    public function testGetRootUrl_SpaceInUriProducesUnsafeEndpoint(): void
    {
        $plugin = new Plugin();
        $plugin->setOption('uri', 'my plugin page');

        // snake_case converts spaces to underscores, so this actually produces safe URL.
        // But the point is that input validation should prevent this at form level.
        $routeUri = $plugin->getOptionUri();
        $rootUrl  = $plugin->getRootUrl(PluginType::PAGE);

        $this->assertNotEmpty($rootUrl, 'getRootUrl() should not return empty string.');

        // The resulting URL should not contain raw spaces.
        $this->assertFalse(
            strpos($rootUrl, ' ') !== false,
            "Endpoint URL '{$rootUrl}' contains spaces, which breaks HTTP routing."
        );
    }

    /**
     * Uppercase URIs are converted via snake_case, but should be rejected at input.
     */
    public function testGetOptionUri_UppercaseIsConvertedButShouldBeValidatedEarlier(): void
    {
        $plugin = new Plugin();
        $plugin->setOption('uri', 'TestPluginPage');

        $uri = $plugin->getOptionUri(); // snake_case => 'test_plugin_page'

        // After snake_case the result is valid...
        $this->assertFalse(preg_match('/[A-Z]/', $uri) === 1,
            "snake_case should remove uppercase, got: '{$uri}'"
        );

        // ...but the input 'TestPluginPage' itself does NOT pass the regex,
        // so it must be validated BEFORE snake_case conversion.
        $this->assertFalse(
            preg_match(self::URI_REGEX, 'TestPluginPage') === 1,
            "'TestPluginPage' should fail the URI regex before any conversion."
        );
    }

    // =========================================================================
    // Group 5: Regex spec — document the expected pattern behavior
    //          (Pure unit assertions, always PASS)
    // =========================================================================

    /**
     * @dataProvider validUriProvider
     */
    public function testUriRegexAcceptsValidPatterns(string $uri): void
    {
        $this->assertTrue(
            preg_match(self::URI_REGEX, $uri) === 1,
            "URI '{$uri}' should match the expected regex pattern."
        );
    }

    /**
     * @dataProvider invalidUriProvider
     */
    public function testUriRegexRejectsInvalidPatterns(string $uri, string $description): void
    {
        $this->assertFalse(
            preg_match(self::URI_REGEX, $uri) === 1,
            "URI '{$uri}' ({$description}) should NOT match the expected regex pattern."
        );
    }

    // =========================================================================
    // Group 6: Response format — invalid URI must return JSON field error
    //          ★ CURRENTLY FAIL → Will PASS after fix ★
    // =========================================================================

    /**
     * Invalid URI must return HTTP 400 JSON with error mapped to the options.uri
     * field key — NOT a 302 redirect.
     *
     * [Current Status: FAIL]
     *   back()->withErrors() returns HTTP 302, which laravel-admin JS cannot
     *   translate into an inline field error.
     *
     * [After Fix: PASS]
     *   getAjaxResponse(['result' => false, 'errors' => ['options.uri' => ...]]) returns
     *   HTTP 400 JSON, which laravel-admin displays at the uri input field.
     */
    public function testInvalidUriResponseIsJsonWithFieldError(): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);

        $response = $this->put(
            admin_urls('plugin', $plugin->id),
            [
                'active_flg' => $plugin->active_flg ?? '1',
                'options'    => ['uri' => 'テスト'],
            ]
        );

        $response->assertStatus(400);
        $response->assertJson(['result' => false]);
        $this->assertArrayHasKey(
            'options.uri',
            $response->json('errors') ?? [],
            "Validation error must be mapped to 'options.uri' field key so laravel-admin displays it inline at the input field."
        );
    }

    // =========================================================================
    // Group 7: Pjax request — invalid URI must not crash (HTTP 500 TypeError)
    //          ★ CURRENTLY FAIL → Will PASS after fix ★
    // =========================================================================

    /**
     * PUT with X-PJAX header and invalid URI must NOT return HTTP 500.
     *
     * [Current Status: FAIL]
     *   getAjaxResponse() returns HTTP 400 JSON. Pjax middleware's handleErrorResponse()
     *   calls get_class($response->exception) where $exception is null (JSON responses
     *   have no exception attached) → TypeError → HTTP 500.
     *
     * [After Fix: PASS]
     *   When $request->pjax() === true, update() returns back()->withErrors() (302).
     *   Pjax middleware's isRedirection() guard passes it through untouched.
     */
    public function testUpdateWithPjaxHeaderDoesNotCrash(): void
    {
        $plugin = $this->getUriPlugin(self::PLUGIN_PAGE);

        $response = $this->withHeaders([
            'X-PJAX'           => 'true',
            'X-PJAX-Container' => '#pjax-container',
        ])->put(
            admin_urls('plugin', $plugin->id),
            [
                'active_flg' => $plugin->active_flg ?? '1',
                'options'    => ['uri' => 'テスト'],
            ]
        );

        $this->assertNotEquals(
            500,
            $response->getStatusCode(),
            "Pjax form submit with invalid URI must not crash with TypeError (HTTP 500). "
                . "Fix: return back()->withErrors() for pjax requests instead of getAjaxResponse()."
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get the target plugin. If not found in DB, mark test as skipped with warning.
     */
    private function getUriPlugin(string $pluginName): Plugin
    {
        $plugin = Plugin::where('plugin_name', $pluginName)->first();

        if (!$plugin) {
            $this->markTestSkipped(
                "Plugin '{$pluginName}' was not found in the database. "
                    . "Ensure test data is seeded before running this test. "
                    . "Skipping."
            );
        }

        return $plugin;
    }

    /**
     * Update a plugin's URI via HTTP PUT request.
     * Due to DatabaseTransactions trait, changes are rolled back after test completion.
     *
     * @param Plugin $plugin
     * @param string $uri
     * @return void
     */
    private function putPluginUri(Plugin $plugin, string $uri): void
    {
        $this->put(
            admin_urls('plugin', $plugin->id),
            [
                'active_flg' => $plugin->active_flg ?? '1',
                'options'    => [
                    'uri' => $uri,
                ],
            ]
        );
    }
}
