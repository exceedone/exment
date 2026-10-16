<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ApplyMeiliSettingsJob;
use Exceedone\Exment\Jobs\Middleware\LogFailureOnSyncDriver;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Jobs\SyncMeiliReferencesJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;

/**
 * The Meili jobs call afterCommit (see JobAfterCommitTest), so on the 'sync'
 * driver they run from the commit callback - after the data is written and
 * outside the try/catch around the dispatch. An exception there escapes
 * DB::commit() and the user gets a 500 on a save that succeeded.
 */
class JobSyncDriverGuardTest extends TestCase
{
    /** @var array<int,string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $warnings = &$this->warnings;
        Log::swap(new class ($warnings) {
            /** @param array<int,string> $sink public: the test reads it through the reference */
            public function __construct(public array &$sink)
            {
            }

            /** @param array<string,mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->sink[] = $message;
            }
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function testEveryMeiliJobCarriesTheSyncDriverGuard(): void
    {
        /** @var list<object> $jobs typed as any job: the guard must hold for Meili jobs added later too */
        $jobs = [
            new SyncMeiliDocumentJob('invoices', 1, 'upsert'),
            new SyncMeiliReferencesJob('invoices', 1),
            new ReindexMeiliTableJob('invoices'),
            new ApplyMeiliSettingsJob(),
        ];

        foreach ($jobs as $job) {
            $class = \get_class($job);

            if (!method_exists($job, 'middleware')) {
                $this->fail("{$class} declares no middleware(), so a Meili outage breaks the caller's save.");
            }

            $guards = array_filter($job->middleware(), fn ($m) => $m instanceof LogFailureOnSyncDriver);
            $this->assertNotEmpty($guards, "{$class} is missing LogFailureOnSyncDriver.");
        }
    }

    public function testFailureOnTheSyncDriverIsLoggedInsteadOfThrown(): void
    {
        $job = new class () {
            public ?SyncJob $job = null;
        };
        $job->job = $this->createMock(SyncJob::class);

        (new LogFailureOnSyncDriver())->handle($job, function (): void {
            throw new \RuntimeException('Meilisearch down');
        });

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('Meilisearch down', $this->warnings[0]);
    }

    public function testFailureOnARealWorkerIsStillThrownSoTheJobRetries(): void
    {
        // $job stays null: not a SyncJob, i.e. a queue worker is running this.
        $job = new class () {
            public ?SyncJob $job = null;
        };

        $this->expectException(\RuntimeException::class);

        (new LogFailureOnSyncDriver())->handle($job, function (): void {
            throw new \RuntimeException('Meilisearch down');
        });
    }

    public function testASuccessfulJobIsPassedThroughUntouched(): void
    {
        $job = new class () {
            public ?SyncJob $job = null;
        };
        $job->job = $this->createMock(SyncJob::class);

        $result = (new LogFailureOnSyncDriver())->handle($job, fn () => 'done');

        $this->assertSame('done', $result);
        $this->assertSame([], $this->warnings);
    }
}
