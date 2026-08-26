<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use PHPUnit\Framework\TestCase;

/**
 * The reindex job collects the record ids while it scans, then removes every
 * indexed document whose id is not in that list. A record created after the
 * scan - and indexed immediately by the realtime sync - is absent from the
 * snapshot and would be deleted as an orphan, silently dropping a live record
 * from search until the nightly reconcile.
 *
 * Ids are auto-increment, so anything above the highest id seen is newer than
 * the snapshot and must be left alone.
 */
class DeletableOrphansTest extends TestCase
{
    public function testRecordsDeletedBeforeTheScanAreStillRemoved(): void
    {
        $this->assertSame(
            [3, 7],
            ReindexMeiliTableJob::deletableOrphans([3, 7], [1, 2, 4, 5, 10])
        );
    }

    public function testARecordCreatedDuringTheScanIsNotTreatedAsAnOrphan(): void
    {
        // 11 arrived after the scan stopped at 10 and was indexed by the sync job.
        $this->assertSame(
            [3],
            ReindexMeiliTableJob::deletableOrphans([3, 11], [1, 2, 10]),
            'id 11 is newer than the snapshot; deleting it loses a live record.'
        );
    }

    /**
     * Known trade-off, not an oversight: a real orphan above the snapshot (every
     * newer row deleted in one go) survives this pass. Keeping a stale document
     * beats deleting a live one, and exment:meili-reconcile clears the rest.
     */
    public function testAGenuineOrphanAboveTheSnapshotIsKeptOnPurpose(): void
    {
        $this->assertSame([], ReindexMeiliTableJob::deletableOrphans([11, 12, 13], [1, 10]));
    }

    /**
     * An empty scan means the table has no rows. Deleting "everything" here
     * would also catch rows inserted while the job ran, so leave it to
     * exment:meili-reconcile.
     */
    public function testAnEmptySnapshotDeletesNothing(): void
    {
        $this->assertSame([], ReindexMeiliTableJob::deletableOrphans([1, 2, 3], []));
    }

    /**
     * value_id comes back from Meilisearch, which may hand ids over as strings.
     */
    public function testStringIdsAreComparedAsNumbers(): void
    {
        $this->assertSame(['3'], ReindexMeiliTableJob::deletableOrphans(['3', '11'], ['1', '10']));
    }

    public function testNothingToDeleteStaysEmpty(): void
    {
        $this->assertSame([], ReindexMeiliTableJob::deletableOrphans([], [1, 2, 3]));
    }
}
