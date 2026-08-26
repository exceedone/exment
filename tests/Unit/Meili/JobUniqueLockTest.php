<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ApplyMeiliSettingsJob;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use PHPUnit\Framework\TestCase;

/**
 * Both Meili "collapse the burst" jobs must release their unique lock when
 * processing STARTS, not when it finishes.
 *
 * Laravel releases a plain ShouldBeUnique lock only after handle() returns
 * (Illuminate\Queue\CallQueuedHandler::call), while
 * ShouldBeUniqueUntilProcessing releases it just before dispatchNow. Both of
 * these jobs block on waitForTask(..., 60000), so a plain unique lock is held
 * for as long as Meilisearch takes - up to a minute.
 *
 * Anything dispatched inside that window is DROPPED, not queued. For
 * ApplyMeiliSettingsJob that is silent and harmful: MeiliFilterSetting::saved
 * dispatches it so a new range filter gets its n_<table>::<col> added to
 * filterableAttributes. Lose that apply and the sidebar still renders the
 * min/max boxes, but Meilisearch rejects the filter as not filterable ->
 * searchTablePaginated throws -> SearchController falls back to MySQL and shows
 * UNFILTERED results as if the filter had been applied.
 *
 * ReindexMeiliTableJob already had this right (see its class docblock); this
 * pins both so neither drifts back.
 */
class JobUniqueLockTest extends TestCase
{
    /**
     * @return array<int,object>
     */
    private function uniqueJobs(): array
    {
        return [
            new ApplyMeiliSettingsJob(),
            new ReindexMeiliTableJob('invoices'),
        ];
    }

    public function testTheUniqueLockIsReleasedWhenProcessingStarts(): void
    {
        foreach ($this->uniqueJobs() as $job) {
            $class = \get_class($job);

            $this->assertInstanceOf(
                ShouldBeUnique::class,
                $job,
                "{$class} must be unique, or a burst of saves queues one job per save."
            );
            $this->assertInstanceOf(
                ShouldBeUniqueUntilProcessing::class,
                $job,
                "{$class} holds its unique lock until handle() returns, and handle()"
                . ' waits on Meilisearch for up to 60s. Every dispatch made in that'
                . ' window is dropped, so the change it carried never reaches the index.'
            );
        }
    }

    public function testTheLockHasAnIdAndAnExpiry(): void
    {
        foreach ($this->uniqueJobs() as $job) {
            $class = \get_class($job);

            $this->assertNotSame(
                '',
                (string) $job->uniqueId(),
                "{$class}: an empty uniqueId() would collapse unrelated jobs into one lock."
            );
            $this->assertGreaterThan(
                0,
                $job->uniqueFor,
                "{$class}: uniqueFor must expire, or a job killed mid-flight blocks the next one forever."
            );
        }
    }
}
