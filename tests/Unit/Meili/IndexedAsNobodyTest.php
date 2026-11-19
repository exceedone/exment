<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every path that builds documents must build them as nobody. A document is
 * read by every user, so a linked record's label has to be the real one, not
 * the "no permission" text the scope would hand whoever happens to be logged
 * in while the mapping runs.
 *
 * Each site wraps its whole mapping loop, not each record: the context drops
 * what the mapping cached, and per record that would throw away the batched
 * lookup setCustomValueModels() does for the chunk.
 */
class IndexedAsNobodyTest extends TestCase
{
    use MeiliSourceTrait;

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function mappingSites(): array
    {
        return [
            'realtime sync' => ['Jobs/SyncMeiliDocumentJob.php', 'public function handle('],
            'table reindex' => ['Jobs/ReindexMeiliTableJob.php', 'public function handle('],
            'reference sync' => ['Jobs/SyncMeiliReferencesJob.php', 'public function handle('],
            'full index' => ['Services/Meili/ExmentIndexer.php', 'public function indexAll('],
            'partial reindex' => ['Services/Meili/ExmentIndexer.php', 'public function reindexIds('],
        ];
    }

    #[DataProvider('mappingSites')]
    public function testTheMappingRunsWithNoViewer(string $path, string $signature): void
    {
        $this->assertCallOrder(
            $this->methodBody($path, $signature),
            'IndexingContext::run',
            '->map($record',
            "{$path} {$signature} must build its documents inside IndexingContext::run()"
        );
    }

    /** A new indexer must not be able to slip in without the context. */
    public function testNoOtherFileBuildsDocuments(): void
    {
        $known = array_unique(array_column(self::mappingSites(), 0));
        $found = [];

        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'));
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (strpos((string) file_get_contents($file->getPathname()), '->map($record') !== false) {
                $found[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(dirname(__DIR__, 3) . '/src/')));
            }
        }

        sort($known);
        sort($found);
        $this->assertSame($known, $found, 'a new document-building site must be added to mappingSites()');
    }
}
