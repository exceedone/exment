<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use PHPUnit\Framework\TestCase;

class ReindexMeiliTableJobTest extends TestCase
{
    public function testTableFilterBuildsEqualityClause(): void
    {
        $this->assertSame(
            'table_name = "soft_furniture"',
            ReindexMeiliTableJob::tableFilter('soft_furniture')
        );
    }

    public function testTableFilterEscapesDoubleQuotes(): void
    {
        $this->assertSame(
            'table_name = "ab\\"cd"',
            ReindexMeiliTableJob::tableFilter('ab"cd')
        );
    }

    public function testUniqueIdIsTableName(): void
    {
        $job = new ReindexMeiliTableJob('invoices');

        $this->assertSame('invoices', $job->uniqueId());
    }

    /**
     * The wait must leave the job time to queue its next slice. Waiting the full
     * timeout gets the job killed mid-wait, and the killed job is the one that
     * would have queued the continuation - the chain stops at the first slice.
     */
    public function testTaskWaitLeavesRoomInsideTheJobTimeout(): void
    {
        $job = new ReindexMeiliTableJob('invoices');

        $this->assertLessThan(
            $job->timeout,
            ReindexMeiliTableJob::TASK_WAIT_MS / 1000,
            'the task wait must be shorter than the job timeout'
        );
    }

    /**
     * A slow Meilisearch is not a failure of this job: it keeps the task and
     * applies writes in order, so the slice chain must carry on.
     */
    public function testAwaitTaskSwallowsAWaitThatTimesOut(): void
    {
        $logger = $this->captureLog();
        $client = new class () {
            /** @return array<string, mixed> */
            public function waitForTask(int $uid, int $timeoutMs): array
            {
                throw new \Meilisearch\Exceptions\TimeOutException();
            }
        };

        $this->awaitTask(new ReindexMeiliTableJob('invoices'), $client);

        $this->assertCount(1, $logger->messages, 'the timeout is logged, not thrown');
    }

    /** A task Meilisearch rejected is reported, and still does not fail the job. */
    public function testAwaitTaskReportsAFailedTask(): void
    {
        $logger = $this->captureLog();
        $client = new class () {
            /** @return array<string, mixed> */
            public function waitForTask(int $uid, int $timeoutMs): array
            {
                return ['status' => 'failed', 'error' => ['message' => 'bad document']];
            }
        };

        $this->awaitTask(new ReindexMeiliTableJob('invoices'), $client);

        $this->assertStringContainsString('bad document', $logger->messages[0] ?? '');
    }

    /**
     * @param mixed $client
     */
    private function awaitTask(ReindexMeiliTableJob $job, $client): void
    {
        $await = (new \ReflectionClass(ReindexMeiliTableJob::class))->getMethod('awaitTask');
        $await->setAccessible(true);
        $await->invoke($job, $client, ['taskUid' => 1], 'slice index');
    }

    /**
     * Stand-in for the 'log' facade: these jobs log through it, and a bare unit
     * test has no application to resolve it from. The returned object collects
     * every message logged through it.
     *
     * @return object{messages: array<int, string>}
     */
    private function captureLog(): object
    {
        $logger = new class () {
            /** @var array<int,string> */
            public array $messages = [];

            /** @param array<int,mixed> $arguments */
            public function __call(string $method, array $arguments): void
            {
                $this->messages[] = (string) ($arguments[0] ?? '');
            }
        };

        // swap() serves the facade without an application, as JobSyncDriverGuardTest does.
        \Illuminate\Support\Facades\Log::swap($logger);

        return $logger;
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        parent::tearDown();
    }
}
