<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\ExmentIndexer;
use PHPUnit\Framework\TestCase;

/**
 * ExmentIndexer::isIndexable() is the single rule deciding whether a table's
 * records exist in the index. Four callers depend on it agreeing:
 *
 *   ExmentIndexer::searchableTables()        what exment:meili-index writes
 *   MeiliSync::shouldSync()                  what realtime sync pushes
 *   ReindexMeiliTableJob::shouldIndex()      what a definition change rebuilds
 *   ApiDataTrait::searchSelectByMeilisearch  whether autocomplete may trust Meili
 *
 * The last one is why the rule had to be shared: it used to query Meili for
 * every table, and a table outside these criteria answered "no results" - which
 * the caller took as authoritative, leaving select_table dropdowns empty while
 * MySQL had matches.
 *
 * Duck-typed, so these stubs stand in for a CustomTable.
 */
class IsIndexableTest extends TestCase
{
    /**
     * @param  array<int,mixed>  $freewordColumns
     */
    private function table(mixed $searchEnabled, array $freewordColumns): object
    {
        return new class ($searchEnabled, $freewordColumns) {
            /** @param array<int,mixed> $freewordColumns */
            public function __construct(private mixed $searchEnabled, private array $freewordColumns)
            {
            }

            public function getOption(string $key): mixed
            {
                return $key === 'search_enabled' ? $this->searchEnabled : null;
            }

            /** @return \Illuminate\Support\Collection<int,mixed> */
            public function getFreewordSearchColumns()
            {
                return collect($this->freewordColumns);
            }
        };
    }

    public function testTableWithSearchEnabledAndAFreewordColumnIsIndexable(): void
    {
        $this->assertTrue(ExmentIndexer::isIndexable($this->table(true, ['title'])));
    }

    public function testSearchDisabledTableIsNotIndexable(): void
    {
        $this->assertFalse(ExmentIndexer::isIndexable($this->table(false, ['title'])));
    }

    public function testTableWithoutFreewordColumnIsNotIndexable(): void
    {
        $this->assertFalse(ExmentIndexer::isIndexable($this->table(true, [])));
    }

    /**
     * search_enabled comes out of a json options blob, so it arrives as "1"/"0"
     * or null just as often as a real bool.
     */
    public function testOptionIsReadAsABooleanNotAsTruthiness(): void
    {
        $this->assertTrue(ExmentIndexer::isIndexable($this->table('1', ['title'])));
        $this->assertTrue(ExmentIndexer::isIndexable($this->table(1, ['title'])));
        $this->assertFalse(ExmentIndexer::isIndexable($this->table('0', ['title'])));
        $this->assertFalse(ExmentIndexer::isIndexable($this->table(null, ['title'])));
    }

    /**
     * Callers pass CustomTable::getEloquent(...) straight in; a deleted or
     * unknown table must fail closed rather than fatal on a null.
     */
    public function testNullTableIsNotIndexable(): void
    {
        $this->assertFalse(ExmentIndexer::isIndexable(null));
    }
}
