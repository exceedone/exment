<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use PHPUnit\Framework\TestCase;

/**
 * The orphan scan reads the index, and the slice before it only creates the
 * index when it has documents to write. A search-enabled table with no rows
 * therefore reached the scan with no index at all, and reading a missing index
 * throws straight out of handle(): on a real worker that is three tries and an
 * entry in failed_jobs, with a log line blaming the client version (the SDK
 * rewrites the 404 message in getDocuments()).
 *
 * Checked against the source, because reaching the branch needs a Meilisearch.
 */
class OrphanScanIndexGuardTest extends TestCase
{
    private function jobSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Jobs/ReindexMeiliTableJob.php');
    }

    /**
     * The body of one method, by brace matching from its signature.
     */
    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "method not found: {$signature}");

        $open = strpos($source, '{', $start);
        $this->assertNotFalse($open);

        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("unterminated method: {$signature}");
    }

    public function testTheScanEnsuresTheIndexBeforeReadingIt(): void
    {
        $body = $this->methodBody($this->jobSource(), 'private function removeOrphans(');

        $ensure = strpos($body, 'ensureIndexExists');
        $read = strpos($body, 'indexedValueIdsPage');

        $this->assertNotFalse($ensure, 'removeOrphans() must make sure the index exists');
        $this->assertNotFalse($read);
        $this->assertLessThan($read, $ensure, 'the index must be ensured before the scan reads it');
    }

    /**
     * The same precondition reaches the de-index branch: a table that stopped
     * being search-enabled before the index was ever built has no documents to
     * delete, which is the result asked for - not an error.
     */
    public function testDeIndexingTreatsAMissingIndexAsNothingToDelete(): void
    {
        $body = $this->methodBody($this->jobSource(), 'public function handle(): void');

        $this->assertStringContainsString('isNotFound', $body);
    }
}
