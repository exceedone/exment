<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Exceedone\Exment\Services\Plugin\PluginPageController;

/**
 * JVN #98252843 (FIXED): Directory traversal in PluginPageController::_readPublicFile().
 *
 * The vulnerability: the public plugin-file route
 *   Route::get('public/{arg1?}/.../{arg5?}', 'PluginPageController@_readPublicFile')
 * joined the URL segments straight onto the plugin path and served the file:
 *   $path = implode('/', $args);
 *   $filePath = path_join($base_path, 'public', $path);
 *   $file = \File::get($filePath);
 * A request such as /public/..%2f..%2f..%2fconfig%2fapp.php (or nested '..' segments)
 * could escape the plugin's public/ directory and read arbitrary files (High).
 *
 * The fix (two independent layers):
 *   1) Reject every segment that is empty, '.', '..', or contains '/', '\' or a NUL byte.
 *   2) After path_join, realpath() the target and require it to stay within the
 *      realpath of the plugin's public/ directory; otherwise abort(404).
 *
 * The per-segment rejection (layer 1) runs BEFORE $this->plugin is touched, so we can
 * drive it directly with a null plugin: a traversal segment must abort(404)
 * (NotFoundHttpException) rather than reach the filesystem.
 *
 * BEFORE FIX: traversal segments were joined and read from disk (no exception).
 * AFTER FIX:  traversal/separator/NUL segments raise NotFoundHttpException.
 */
class JvnPluginPublicFileTraversalFixedTest extends SecurityRegressionTestCase
{
    /** @var string[] plugin temp dirs created by the happy-path tests; removed in tearDown. */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->rrmdir($root);
        }
        $this->tempRoots = [];
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function maliciousSegmentProvider(): array
    {
        return [
            'parent dir'    => ['..'],
            'current dir'   => ['.'],
            'forward slash' => ['../etc'],
            'backslash'     => ['..\\windows'],
            'nul byte'      => ["shell\0.php"],
            'empty segment' => [''],
        ];
    }

    #[DataProvider('maliciousSegmentProvider')]
    public function test_traversal_and_separator_segments_are_rejected(string $segment): void
    {
        // A traversal/separator/NUL/empty segment must abort(404) before any disk access.
        $controller = new PluginPageController(null);

        $this->expectException(NotFoundHttpException::class);
        $controller->_readPublicFile(new Request(), $segment);
    }

    public function test_dotdot_segment_aborts_before_touching_plugin(): void
    {
        // Explicit, unambiguous case: '..' must be rejected even though the plugin is null,
        // proving the guard runs before $this->plugin->getFullPath().
        $controller = new PluginPageController(null);

        $this->expectException(NotFoundHttpException::class);
        $controller->_readPublicFile(new Request(), 'legit', '..', 'secret.php');
    }

    public function test_source_has_segment_rejection_and_realpath_containment(): void
    {
        $src = $this->exmentSource('Services/Plugin/PluginPageController.php');

        // Layer 1: per-segment rejection.
        $this->assertMatchesRegularExpression('/\$arg\s*===\s*\'\.\.\'/', $src, 'Must reject the ".." segment.');
        $this->assertMatchesRegularExpression("/strpos\\(\\\$arg,\\s*'\\/'\\)/", $src, 'Must reject segments containing "/".');
        $this->assertMatchesRegularExpression('/strpos\(\$arg,\s*[\'"]\\\\\\\\[\'"]\)/', $src, 'Must reject segments containing a backslash.');
        $this->assertStringContainsString('"\0"', $src, 'Must reject NUL bytes in a segment.');

        // Layer 2: realpath containment.
        $this->assertStringContainsString('realpath($filePath)', $src, 'Must realpath the resolved target.');
        $this->assertStringContainsString('realpath($publicDir)', $src, 'Must realpath the plugin public dir.');
        $this->assertStringContainsString('$realFile', $src, 'Must serve the containment-checked $realFile, not the raw joined path.');

        // Old unsafe shape must be gone.
        $this->assertStringNotContainsString(
            "\\File::get(\$filePath)",
            $src,
            'The pre-fix raw read (\\File::get($filePath) without containment) must be gone.'
        );
    }

    // -----------------------------------------------------------------------
    // Happy path: the fix must NOT over-reject legitimate plugin public files.
    // -----------------------------------------------------------------------

    public function test_legitimate_single_segment_file_is_served(): void
    {
        $controller = $this->controllerWithPluginRoot($this->makePluginTree());

        $response = $controller->_readPublicFile(new Request(), 'style.css');

        $this->assertSame(200, $response->getStatusCode(), 'A legitimate top-level public file must be served.');
        $this->assertSame('body{color:red}', $response->getContent());
        $this->assertSame('text/css', $response->headers->get('Content-Type'), 'CSS keeps the text/css content type.');
    }

    public function test_legitimate_nested_file_is_served(): void
    {
        // A nested path arrives as SEPARATE route segments (css, app.css): the per-segment guard
        // rejects '/' or '\' INSIDE a segment but must allow multiple clean segments, and the
        // realpath-containment check must accept a file that stays inside public/.
        $controller = $this->controllerWithPluginRoot($this->makePluginTree());

        $response = $controller->_readPublicFile(new Request(), 'css', 'app.css');

        $this->assertSame(200, $response->getStatusCode(), 'A legitimate nested public file must still be served.');
        $this->assertSame('.app{display:block}', $response->getContent());
    }

    // --- helpers ------------------------------------------------------------

    /** Build a throwaway plugin tree with public/style.css and public/css/app.css; returns its root. */
    private function makePluginTree(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'exment_pf_' . uniqid('', true);
        @mkdir($root . '/public/css', 0777, true);
        file_put_contents($root . '/public/style.css', 'body{color:red}');
        file_put_contents($root . '/public/css/app.css', '.app{display:block}');
        $this->tempRoots[] = $root;
        return $root;
    }

    /** A controller whose plugin->getFullPath() points at $root (the ctor derives $plugin from the page). */
    private function controllerWithPluginRoot(string $root): PluginPageController
    {
        $controller = new PluginPageController(null);

        $plugin = new class ($root) {
            public function __construct(private string $root)
            {
            }
            // @phpstan-ignore-next-line - stub of Plugin::getFullPath(...$pass_array)
            public function getFullPath(...$args)
            {
                return $this->root;
            }
        };

        // PHP 8.1+: reflected properties are accessible without setAccessible().
        (new \ReflectionProperty(PluginPageController::class, 'plugin'))->setValue($controller, $plugin);

        return $controller;
    }

    /** Recursively remove a temp directory. */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
