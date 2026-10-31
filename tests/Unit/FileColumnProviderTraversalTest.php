<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\DataImportExport\Providers\Import\FileColumnProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression test for JVN#17048635 (directory traversal).
 *
 * FileColumnProvider::getFileFullPath() builds a path from the "file_name"
 * column of an imported CSV/xlsx row. That value must never be able to escape
 * the configured import directory (e.g. "../../.env").
 *
 * This test only needs the application booted so the \File facade resolves;
 * it does not touch the database.
 */
class FileColumnProviderTraversalTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var string */
    private $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root    = path_join_os(sys_get_temp_dir(), 'exment_jvn17048635_' . getmypid());
        $this->baseDir = path_join_os($this->root, 'file-import', 'files');

        @mkdir($this->baseDir, 0777, true);
        file_put_contents(path_join_os($this->baseDir, 'legit.txt'), 'legit content');
        // A "secret" one level above the import dir, and two levels above.
        file_put_contents(path_join_os($this->root, 'file-import', 'secret_parent.txt'), 'SECRET');
        file_put_contents(path_join_os($this->root, 'secret_root.txt'), 'TOP SECRET');
    }

    protected function tearDown(): void
    {
        @unlink(path_join_os($this->baseDir, 'legit.txt'));
        @unlink(path_join_os($this->root, 'file-import', 'secret_parent.txt'));
        @unlink(path_join_os($this->root, 'secret_root.txt'));
        @rmdir($this->baseDir);
        @rmdir(path_join_os($this->root, 'file-import'));
        @rmdir($this->root);

        parent::tearDown();
    }

    /**
     * @param string $fileName
     * @return string|null
     */
    private function resolve(string $fileName)
    {
        $provider = new FileColumnProvider(['fileDirFullPath' => $this->baseDir]);
        $method = new ReflectionMethod(FileColumnProvider::class, 'getFileFullPath');
        $method->setAccessible(true);
        return $method->invoke($provider, ['file_name' => $fileName]);
    }

    /**
     * A legitimate file inside the import directory still resolves.
     *
     * @return void
     */
    public function testLegitimateFileResolves()
    {
        $this->assertNotNull($this->resolve('legit.txt'));
        $this->assertNotNull($this->resolve('./legit.txt'));
    }

    /**
     * Traversal attempts must be rejected (return null), regardless of the
     * separator style or nesting depth used.
     *
     * @return void
     */
    public function testTraversalIsBlocked()
    {
        $payloads = [
            '../secret_parent.txt',
            '../../secret_root.txt',
            '..\\secret_parent.txt',
            'subdir/../../secret_parent.txt',
        ];

        foreach ($payloads as $payload) {
            $this->assertNull(
                $this->resolve($payload),
                "Traversal payload should be blocked: {$payload}"
            );
        }
    }
}
