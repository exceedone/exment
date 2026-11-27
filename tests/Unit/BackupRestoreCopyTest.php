<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\BackupRestore\Restore;
use Tests\TestCase;

/**
 * A restore copies the folders of the backup over the folders of the server without writing again
 * the files that are the same already (ISS02943), and still as File::copyDirectory: the new files,
 * the changed files and the empty folders.
 */
class BackupRestoreCopyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'claude_restore_copy_' . substr(md5(uniqid('', true)), 0, 8);
        mkdir($this->root . '/from/sub/empty', 0777, true);
        mkdir($this->root . '/to', 0777, true);
        file_put_contents($this->root . '/from/same.txt', 'same content');
        file_put_contents($this->root . '/to/same.txt', 'same content');
        file_put_contents($this->root . '/from/changed.txt', 'new content');
        file_put_contents($this->root . '/to/changed.txt', 'old content');
        file_put_contents($this->root . '/from/sub/new.txt', 'a new file');
        file_put_contents($this->root . '/from/.hidden', 'hidden');
    }

    protected function tearDown(): void
    {
        \File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function testTheSameFilesAreNotWrittenAgain(): void
    {
        config(['exment.backup.skip_unchanged' => true]);
        touch($this->root . '/to/same.txt', time() - 3600);
        clearstatcache();
        $before = filemtime($this->root . '/to/same.txt');

        [$done, $copied, $same] = $this->copy();

        $this->assertTrue($done);
        $this->assertSame(3, $copied, 'changed.txt, sub/new.txt and .hidden');
        $this->assertSame(1, $same);
        clearstatcache();
        $this->assertSame($before, filemtime($this->root . '/to/same.txt'), 'the same file is not written again');
        $this->assertSame('new content', file_get_contents($this->root . '/to/changed.txt'));
        $this->assertSame('a new file', file_get_contents($this->root . '/to/sub/new.txt'));
        $this->assertSame('hidden', file_get_contents($this->root . '/to/.hidden'));
        $this->assertDirectoryExists($this->root . '/to/sub/empty');
    }

    public function testEveryFileIsWrittenWhenItIsTurnedOff(): void
    {
        config(['exment.backup.skip_unchanged' => false]);

        [$done, $copied, $same] = $this->copy();

        $this->assertTrue($done);
        $this->assertSame(4, $copied);
        $this->assertSame(0, $same);
    }

    public function testTheSameFileIsTheSameSizeAndContent(): void
    {
        $a = $this->root . '/a';
        $b = $this->root . '/b';
        file_put_contents($a, 'abcd');
        file_put_contents($b, 'abce');
        $this->assertFalse(Restore::isSameFile($a, $b), 'the same size, another content');
        file_put_contents($b, 'abcd');
        $this->assertTrue(Restore::isSameFile($a, $b));
        $this->assertFalse(Restore::isSameFile($a, $this->root . '/none'));
    }

    /**
     * @return array{0: bool, 1: int, 2: int}
     */
    private function copy(): array
    {
        $method = new \ReflectionMethod(Restore::class, 'copyLocalDirectory');
        /** @var array{0: bool, 1: int, 2: int} $result */
        $result = $method->invoke(new Restore(), $this->root . '/from', $this->root . '/to');
        return $result;
    }
}
