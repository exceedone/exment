<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use PHPUnit\Framework\TestCase;

/**
 * The reindex job used to delete the table's documents and only then refill
 * them. Its queue timeout is 60s, so any table too large to refill in that
 * window was left permanently empty - and all three retries repeated the wipe.
 *
 * These tests pin the shape of the fix rather than the wording: the delete must
 * not run before the writes, and a burst of saves must collapse into one job
 * that is still allowed to schedule a successor.
 */
class ReindexStrategyTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Jobs/ReindexMeiliTableJob.php'
        );
    }

    /**
     * Offsets, not wording: the only deleteDocuments left is the one guarded by
     * "table no longer indexable", and it returns before any write.
     */
    public function testDocumentsAreWrittenBeforeAnythingIsDeleted(): void
    {
        $src = $this->source();
        $handle = substr($src, (int) strpos($src, 'public function handle()'));

        $add = strpos($handle, 'addDocuments');
        $delete = strpos($handle, 'deleteDocuments');

        $this->assertNotFalse($add, 'the job no longer writes any document');
        $this->assertNotFalse($delete, 'orphan cleanup disappeared');
        $this->assertLessThan(
            $add,
            $delete,
            'deleteDocuments now runs before addDocuments again: a timeout in between empties the table.'
        );
    }

    /**
     * The wipe is still needed for a table that dropped out of the index, but
     * only behind the shouldIndex() guard.
     */
    public function testTheOnlyBulkDeleteSitsBehindTheNotIndexableGuard(): void
    {
        $src = $this->source();
        $handle = substr($src, (int) strpos($src, 'public function handle()'));

        $guard = strpos($handle, '!$this->shouldIndex($table)');
        $delete = strpos($handle, 'deleteDocuments');

        $this->assertNotFalse($guard);
        $this->assertLessThan($delete, $guard);
    }

    /**
     * Releasing the lock at the start (not at the end) is what lets a save made
     * during a long reindex schedule the next one instead of being dropped.
     */
    public function testTheUniqueLockIsReleasedWhenTheJobStarts(): void
    {
        $job = new ReindexMeiliTableJob('invoices');

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job, 'UntilProcessing must still be a unique job');
    }

    /**
     * A burst of saves in one request must not each schedule a reindex that
     * reads the half-saved state.
     */
    public function testDispatchIsHeldBackSoTheBurstSettlesFirst(): void
    {
        $this->assertGreaterThan(0, ReindexMeiliTableJob::DISPATCH_DELAY);
    }

    /**
     * The dedup used to be a static array on the class, which made it
     * per-process: two web workers handling the same burst both dispatched.
     */
    public function testTheOldInProcessDedupIsGone(): void
    {
        foreach ([
            dirname(__DIR__, 3) . '/src/Services/Meili/MeiliDefinitionSync.php',
            dirname(__DIR__, 3) . '/src/Model/MeiliFilterSetting.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'microtime(true)',
                (string) file_get_contents($file),
                basename($file) . ' still dedups by wall clock inside the process.'
            );
        }
    }
}
