<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * attributesToSearchOn is a permission decision, and the paths come from the
 * table's configuration - not from the index. A freeword column no document
 * holds a value for is not a searchable attribute, and Meilisearch answers 400
 * for it: unguarded that is a broken results page and a 500 on export, for a
 * path that could never have matched anything.
 */
class SearchAttributeGuardTest extends TestCase
{
    private const MESSAGE = 'Attribute `fields.url` is not searchable.'
        . ' Available searchable attributes are: `fields, fields.title, label, table_label`';

    public function testTheAvailableAttributesAreReadBackOutOfTheError(): void
    {
        $this->assertSame(
            ['fields', 'fields.title', 'label', 'table_label'],
            MeiliSearchService::parseSearchableAttributes(self::MESSAGE)
        );
    }

    /** Any other failure must be rethrown untouched, not swallowed as this one. */
    public function testOtherErrorsAreNotTreatedAsAnAttributeProblem(): void
    {
        $this->assertNull(MeiliSearchService::parseSearchableAttributes('Index `exment_global` not found.'));
        $this->assertNull(MeiliSearchService::parseSearchableAttributes(''));
        // Right error, unknown wording -> still "not ours", so the caller rethrows.
        $this->assertNull(MeiliSearchService::parseSearchableAttributes('x is not searchable.'));
    }

    public function testOnlyTheAttributesTheIndexAcceptsAreKept(): void
    {
        $narrowed = MeiliSearchService::withoutUnknownSearchAttributes(
            [
                'attributesToSearchOn' => ['table_label', 'label', 'fields.title', 'fields.url'],
                'hitsPerPage' => 25,
            ],
            new \RuntimeException(self::MESSAGE)
        );

        $this->assertSame(['table_label', 'label', 'fields.title'], $narrowed['attributesToSearchOn']);
        $this->assertSame(25, $narrowed['hitsPerPage'], 'The rest of the query must be left alone.');
    }

    /**
     * The retry must never widen the search. With nothing left it sends an empty
     * list, which Meilisearch answers with zero hits - the same fail-closed
     * result as a table with nothing readable in it.
     */
    public function testNothingLeftStaysEmptyInsteadOfSearchingEverything(): void
    {
        $narrowed = MeiliSearchService::withoutUnknownSearchAttributes(
            ['attributesToSearchOn' => ['fields.url', 'fields.gone']],
            new \RuntimeException(self::MESSAGE)
        );

        $this->assertSame([], $narrowed['attributesToSearchOn']);
        $this->assertArrayHasKey('attributesToSearchOn', $narrowed);
    }

    /** No restriction asked for -> this guard has nothing to do with the failure. */
    public function testAQueryWithoutTheParameterIsLeftToItsOwnErrorHandling(): void
    {
        $this->assertNull(MeiliSearchService::withoutUnknownSearchAttributes(
            ['hitsPerPage' => 10],
            new \RuntimeException(self::MESSAGE)
        ));
    }
}
