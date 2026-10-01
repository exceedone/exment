<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use PHPUnit\Framework\TestCase;

/**
 * A full index must converge with the source database, not merely upsert the
 * records that still qualify. These source-level checks guard the two cleanup
 * paths without requiring a destructive database/Meilisearch fixture.
 */
class FullIndexPruneContractTest extends TestCase
{
    public function testFullIndexPrunesOrphanedRecordsAndDeindexedTables(): void
    {
        $source = $this->source('src/Services/Meili/ExmentIndexer.php');

        $this->assertStringContainsString("'pruned' => \$this->pruneStaleDocuments()", $source);
        $this->assertStringContainsString('indexedValueIds($tableName)', $source);
        $this->assertStringContainsString('deleteByValueIds($tableName, $orphan, $this->mapper)', $source);
        $this->assertStringContainsString("'facets' => ['table_name']", $source);
        $this->assertStringContainsString("'filter' => 'table_name = ' . MeiliSearchService::quoteFilterValue", $source);
    }

    public function testIndexCommandStillRunsWhenNoTableCurrentlyQualifies(): void
    {
        $source = $this->source('src/Console/MeiliIndexCommand.php');

        $this->assertStringContainsString('Continuing to remove stale indexed documents.', $source);
        $this->assertStringContainsString("\$result['pruned']['orphaned_records']", $source);
        $this->assertStringContainsString("\$result['pruned']['deindexed_table_documents']", $source);
    }

    public function testIndexCommandAlwaysUsesDefaultPrune(): void
    {
        $command = $this->source('src/Console/MeiliIndexCommand.php');
        $indexer = $this->source('src/Services/Meili/ExmentIndexer.php');

        $this->assertStringNotContainsString('skip-prune', $command);
        $this->assertStringNotContainsString('skipPrune', $indexer);
        $this->assertStringContainsString("\$indexer->indexAll((bool) \$this->option('fresh'))", $command);
        $this->assertStringContainsString("'pruned' => \$this->pruneStaleDocuments()", $indexer);
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $relativePath);
    }
}
