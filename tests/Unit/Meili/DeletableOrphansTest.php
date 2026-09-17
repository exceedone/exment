<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use PHPUnit\Framework\TestCase;

/**
 * The reindex job deletes indexed documents whose record is gone. Candidates are
 * re-checked against the database right before deleting, so a record created
 * while the job scanned is kept.
 */
class DeletableOrphansTest extends TestCase
{
    public function testDeletedRecordsAreRemoved(): void
    {
        $this->assertSame([3, 7], ReindexMeiliTableJob::deletableOrphans([3, 7], []));
    }

    public function testARecordCreatedDuringTheScanIsKept(): void
    {
        // 11 was indexed after the id scan and exists now.
        $this->assertSame([3], ReindexMeiliTableJob::deletableOrphans([3, 11], [11]));
    }

    public function testTheNewestDeletedRecordIsRemovedToo(): void
    {
        // Used to be kept because its id was above the highest remaining id.
        $this->assertSame([11, 12, 13], ReindexMeiliTableJob::deletableOrphans([11, 12, 13], []));
    }

    public function testStringIdsAreComparedAsNumbers(): void
    {
        // value_id may come back from Meilisearch as a string.
        $this->assertSame(['3'], ReindexMeiliTableJob::deletableOrphans(['3', '11'], [11]));
    }

    public function testNothingToDeleteStaysEmpty(): void
    {
        $this->assertSame([], ReindexMeiliTableJob::deletableOrphans([], [1, 2, 3]));
    }
}
