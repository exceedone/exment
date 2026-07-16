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
}
