<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use PHPUnit\Framework\TestCase;

/**
 * A chunked reindex restarts when the table configuration changes between slices.
 */
class ReindexConfigChangeTest extends TestCase
{
    private static function col(string $name, string $type = 'text', array $options = []): object
    {
        return (object) ['column_name' => $name, 'column_type' => $type, 'options' => $options];
    }

    private static function hash(array $facets = [], array $ranges = []): string
    {
        return ReindexMeiliTableJob::configHash('Orders', [self::col('title')], $facets, $ranges, []);
    }

    public function testSameConfigurationSameHash(): void
    {
        $this->assertSame(self::hash(), self::hash());
    }

    public function testAddingARangeFilterChangesTheHash(): void
    {
        $this->assertNotSame(self::hash(), self::hash([], [self::col('amount', 'integer')]));
    }

    public function testEditingSelectChoicesChangesTheHash(): void
    {
        $this->assertNotSame(
            self::hash([self::col('status', 'select', ['select_item' => "open\nclosed"])]),
            self::hash([self::col('status', 'select', ['select_item' => "open\nclosed\nhold"])])
        );
    }

    public function testRestartOnlyWhenAContinuationSeesAChangedHash(): void
    {
        $this->assertTrue(ReindexMeiliTableJob::configChangedMidChain(500, 'abc', 'def'));
        $this->assertFalse(ReindexMeiliTableJob::configChangedMidChain(500, 'abc', 'abc'));
        // First slice, or a job queued before configHash existed.
        $this->assertFalse(ReindexMeiliTableJob::configChangedMidChain(null, 'abc', 'def'));
        $this->assertFalse(ReindexMeiliTableJob::configChangedMidChain(500, null, 'def'));
    }
}
