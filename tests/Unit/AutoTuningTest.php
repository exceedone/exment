<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;

/**
 * The tuning Exment can do by itself (the setup and the tuning page): only what is
 * chosen, only what is needed, and one failure does not stop the others.
 */
class AutoTuningTest extends UnitTestBase
{
    /** @var array<string> */
    protected $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testOnlyTheChosenItemsThatAreNeeded(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => true, 'assets' => true, 'file_permission' => false];

        $this->assertSame(['done' => [], 'failed' => []], $auto->apply([]));
        $this->assertSame([], $auto->calls);

        $result = $auto->apply(['exment_cache', 'file_permission', 'unknown']);
        $this->assertSame(['exment_cache'], $result['done']);
        $this->assertSame(['exment_cache'], $auto->calls);
    }

    public function testOneFailureDoesNotStopTheOthers(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => true, 'assets' => true, 'file_permission' => true];
        $auto->fail = ['exment_cache'];

        $result = $auto->apply(AutoTuning::ITEMS);
        $this->assertSame(['exment_cache'], $result['failed']);
        $this->assertSame(['assets', 'file_permission'], $result['done']);
    }

    public function testTheListShowsWhatIsNeeded(): void
    {
        $auto = new AutoTuningRecorder();
        $auto->need = ['exment_cache' => false, 'assets' => true, 'file_permission' => false];
        $this->assertSame(['assets'], array_keys($auto->available()));
        $this->assertNotSame('', $auto->available()['assets']);
    }

    public function testTheExmentCacheIsNeededOnlyWhenOff(): void
    {
        $auto = new AutoTuning();
        config(['exment.use_cache' => true]);
        $this->assertArrayNotHasKey('exment_cache', $auto->available());
    }

    public function testOnlyTheFilesOfThisUserThatOthersMayRead(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_geteuid')) {
            $this->markTestSkipped('no POSIX permissions here');
        }
        $open = $this->tempFile(0644);
        $closed = $this->tempFile(0640);

        $auto = new AutoTuning();
        $auto->files = [$open, $closed, '/no/such/file'];
        $this->assertSame([$open], $auto->ownFiles());

        $result = $auto->apply(['file_permission']);
        $this->assertSame(['file_permission'], $result['done']);
        clearstatcache(true, $open);
        $this->assertSame('640', substr(sprintf('%o', fileperms($open)), -3));
        $this->assertSame([], $auto->ownFiles());
    }

    protected function tempFile(int $mode): string
    {
        $file = sys_get_temp_dir() . '/exment_auto_tuning_' . uniqid('', true);
        file_put_contents($file, "X=1\n");
        chmod($file, $mode);
        return $this->tempFiles[] = $file;
    }
}

/**
 * AutoTuning with the needs set by the test and the changes only recorded.
 */
class AutoTuningRecorder extends AutoTuning
{
    /** @var array<string, bool> */
    public $need = [];

    /** @var array<string> */
    public $fail = [];

    /** @var array<string> */
    public $calls = [];

    protected function needs(string $key): bool
    {
        return $this->need[$key] ?? false;
    }

    protected function applyExmentCache(): void
    {
        $this->record('exment_cache');
    }

    protected function applyAssets(): void
    {
        $this->record('assets');
    }

    protected function applyFilePermission(): void
    {
        $this->record('file_permission');
    }

    protected function record(string $key): void
    {
        if (in_array($key, $this->fail, true)) {
            throw new \RuntimeException('failed: ' . $key);
        }
        $this->calls[] = $key;
    }
}
