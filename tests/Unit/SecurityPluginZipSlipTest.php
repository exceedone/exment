<?php

namespace Exceedone\Exment\Tests\Unit;

use ZipArchive;
use ReflectionMethod;
use Exceedone\Exment\Services\Plugin\PluginInstaller;

/**
 * Security tests for PluginInstaller — ZIP Slip (Path Traversal) vulnerability.
 *
 * The vulnerability: uploadPlugin() called $zip->extractTo($tmpfolderfullpath)
 * without first validating ZIP entry names. A crafted ZIP containing entries
 * like '../../../evil.php' would write files outside the extraction directory,
 * allowing an attacker to overwrite arbitrary files in the project (e.g., .env,
 * config files, or even PHP source files leading to RCE).
 *
 * The fix: add a protected static validateZipEntries(ZipArchive $zip): void
 * method that iterates all entries before extraction and throws RuntimeException
 * if any entry contains '..' (path traversal) or starts with an absolute path.
 * uploadPlugin() calls this method before $zip->extractTo().
 *
 * BEFORE FIX: tests in this file fail with ReflectionException
 *             (validateZipEntries method does not exist).
 * AFTER FIX:  tests pass — traversal entries are rejected, normal entries allowed.
 */
class SecurityPluginZipSlipTest extends UnitTestBase
{
    /** @var string[] Temp ZIP files to clean up after each test. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    /**
     * Creates a real temp ZIP file with the given entries.
     *
     * @param array<string, string> $entries  filename => file content
     * @return string  path to the created ZIP file
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zipslip_test_') . '.zip';
        $this->tmpFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * Opens the ZIP at $zipPath and calls PluginInstaller::validateZipEntries()
     * via Reflection, leaving the ZipArchive object open for the caller.
     *
     * BEFORE FIX: throws ReflectionException — method does not exist yet.
     * AFTER FIX:  correctly invokes the method.
     */
    private function callValidateZipEntries(ZipArchive $zip): void
    {
        // ReflectionException here means the method hasn't been added yet (BEFORE FIX).
        $ref = new ReflectionMethod(PluginInstaller::class, 'validateZipEntries');
        $ref->setAccessible(true);
        $ref->invoke(null, $zip);
    }

    // -----------------------------------------------------------------------
    // Rejection tests — malicious entries must throw RuntimeException
    // -----------------------------------------------------------------------

    /**
     * An entry with '../' at the start must be rejected.
     *
     * BEFORE FIX: FAILS with ReflectionException (method not found).
     * AFTER FIX:  PASSES — RuntimeException with "path traversal" message is thrown.
     */
    public function testRejectsEntryWithLeadingDotDotSlash(): void
    {
        $zipPath = $this->makeZip([
            'config.json'       => '{}',
            '../../../evil.php' => '<?php system("id");',
        ]);

        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/path traversal/i');

        try {
            $this->callValidateZipEntries($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * An entry with '..' embedded in a subdirectory path must be rejected.
     *
     * BEFORE FIX: FAILS with ReflectionException.
     * AFTER FIX:  PASSES — RuntimeException is thrown.
     */
    public function testRejectsEntryWithEmbeddedDotDot(): void
    {
        $zipPath = $this->makeZip([
            'config.json'             => '{}',
            'subdir/../../etc/passwd' => 'root:x:0:0',
        ]);

        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->expectException(\RuntimeException::class);

        try {
            $this->callValidateZipEntries($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * An entry with an absolute Unix path must be rejected.
     *
     * BEFORE FIX: FAILS with ReflectionException.
     * AFTER FIX:  PASSES — RuntimeException with "absolute path" message is thrown.
     */
    public function testRejectsAbsoluteUnixPath(): void
    {
        $zipPath = $this->makeZip([
            'config.json' => '{}',
            '/etc/passwd' => 'injected',
        ]);

        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/absolute path/i');

        try {
            $this->callValidateZipEntries($zip);
        } finally {
            $zip->close();
        }
    }

    // -----------------------------------------------------------------------
    // Acceptance test — well-formed plugin ZIP must pass without error
    // -----------------------------------------------------------------------

    /**
     * A normal plugin ZIP with no traversal sequences must pass validation.
     *
     * BEFORE FIX: FAILS with ReflectionException.
     * AFTER FIX:  PASSES — no exception is thrown.
     */
    public function testAllowsNormalPluginZip(): void
    {
        $zipPath = $this->makeZip([
            'config.json'       => json_encode(['plugin_name' => 'TestPlugin']),
            'src/Plugin.php'    => '<?php // plugin code',
            'resources/en.php'  => '<?php return [];',
            'deep/sub/file.txt' => 'some content',
        ]);

        $zip = new ZipArchive();
        $zip->open($zipPath);

        // Must not throw:
        $this->callValidateZipEntries($zip);
        $zip->close();

        $this->assertTrue(true, 'Normal plugin ZIP must pass validateZipEntries() without throwing');
    }
}
