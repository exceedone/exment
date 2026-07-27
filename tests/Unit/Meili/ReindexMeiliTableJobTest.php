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
}
