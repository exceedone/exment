<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ApplyMeiliSettingsJob;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use PHPUnit\Framework\TestCase;

/**
 * Every Meili job is dispatched from a model event, and Exment saves records
 * inside a transaction. Without afterCommit a worker can pick the job up before
 * the commit and read a database that does not have the row yet.
 */
class JobAfterCommitTest extends TestCase
{
    public function testEveryMeiliJobIsDispatchedOnlyAfterTheTransactionCommits(): void
    {
        $jobs = [
            new SyncMeiliDocumentJob('invoices', 1, 'upsert'),
            new ReindexMeiliTableJob('invoices'),
            new ApplyMeiliSettingsJob(),
        ];

        foreach ($jobs as $job) {
            $class = \get_class($job);

            $this->assertTrue(
                isset($job->afterCommit),
                "{$class} leaves \$afterCommit null, so the queue dispatches it"
                . ' immediately - inside the caller transaction.'
            );
            $this->assertTrue($job->afterCommit, "{$class}: \$afterCommit must be true.");
        }
    }
}
