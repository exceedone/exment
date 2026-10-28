<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Base class for "guard tests" (fitness functions) that STATICALLY scan the whole package src/.
 *
 * Purpose: prevent developers from re-introducing the bug classes fixed in commit b26a6dd:
 *   - SQLi by interpolating a VALUE into raw SQL          -> GuardRawSqlValueInterpolationTest
 *   - array_splice on the array being foreach-iterated     -> GuardArraySpliceInLoopTest
 *   - anonymous state-changing route without an authz check -> GuardAnonymousApiRouteAllowlistTest
 *
 * No DB / no app boot (extends PHPUnit TestCase) => fast, stable, CI-friendly.
 * Comments are stripped before scanning (token_get_all) so only executable code is matched.
 *
 * Run:
 *   vendor/bin/phpunit exment/tests/Unit/Security/GuardRawSqlValueInterpolationTest.php
 *   vendor/bin/phpunit exment/tests/Unit/Security/GuardArraySpliceInLoopTest.php
 *   vendor/bin/phpunit exment/tests/Unit/Security/GuardAnonymousApiRouteAllowlistTest.php
 */
abstract class CodebaseGuardTestCase extends TestCase
{
    /** Real src/ directory of the package (independent of the ./exment or vendor/... symlink). */
    public static function srcDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
    }

    /** @return string[] absolute paths of every .php file under src/. */
    public static function phpFiles(): array
    {
        $dir = self::srcDir();
        $files = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Remove comment contents BUT keep line numbers (replace each comment with the same number of '\n').
     * => only executable code is scanned, never examples inside comments/docblocks.
     */
    public static function stripComments(string $code): string
    {
        // token_get_all only recognizes comments when a PHP open tag is present.
        if (strpos($code, '<?php') === false) {
            $code = "<?php\n" . $code;
        }
        $out = '';
        foreach (token_get_all($code) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                } else {
                    $out .= $tok[1];
                }
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    /** Short display path (relative to the package root). */
    public static function rel(string $absPath): string
    {
        $root = dirname(self::srcDir()); // package root
        return ltrim(str_replace($root, '', $absPath), DIRECTORY_SEPARATOR);
    }
}
