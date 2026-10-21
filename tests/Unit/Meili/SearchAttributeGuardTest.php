<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * attributesToSearchOn narrows a search to the attribute paths a viewer may be
 * matched on. A freeword column no document holds a value for is not an
 * attribute of the index at all - buildDocument() drops null and empty values
 * - and Meilisearch answers 400 for it:
 *
 *   Attribute `fields.url` is not searchable.
 *   Available searchable attributes are: `fields, fields.title, label, table_label`.
 *
 * Unguarded that breaks the results page, drops the sidebar counts and makes
 * the export answer a download with a 500 - over a path that could not have
 * matched anything anyway.
 *
 * The retry never WIDENS the search: the paths are a permission decision, so
 * only the ones the index accepts are kept.
 */
class SearchAttributeGuardTest extends TestCase
{
    use MeiliSourceTrait;

    private const MESSAGE = 'Attribute `fields.url` is not searchable. '
        . 'Available searchable attributes are: `fields, fields.title, label, table_label`.';

    public function testTheAvailableAttributesAreReadOutOfTheError(): void
    {
        $this->assertSame(
            ['fields', 'fields.title', 'label', 'table_label'],
            MeiliSearchService::parseSearchableAttributes(self::MESSAGE)
        );
    }

    public function testAnotherErrorIsNotParsed(): void
    {
        $this->assertNull(MeiliSearchService::parseSearchableAttributes('Index `exment_global` not found.'));
    }

    public function testTheRetryKeepsOnlyTheAttributesTheIndexAccepts(): void
    {
        $options = [
            'filter' => 'table_name = "x"',
            'attributesToSearchOn' => ['table_label', 'label', 'fields.title', 'fields.url'],
        ];

        $narrowed = MeiliSearchService::withoutUnknownSearchAttributes($options, new \Exception(self::MESSAGE));

        $this->assertSame(['table_label', 'label', 'fields.title'], $narrowed['attributesToSearchOn']);
        $this->assertSame('table_name = "x"', $narrowed['filter'], 'the rest of the query is untouched');
    }

    /**
     * Nothing left is a real answer - "this viewer may be matched on nothing in
     * this table" - and Meilisearch returns zero hits for it. Falling back to
     * searching everything would hand them the content the paths withheld.
     */
    public function testTheRetryNeverWidensTheSearch(): void
    {
        $narrowed = MeiliSearchService::withoutUnknownSearchAttributes(
            ['attributesToSearchOn' => ['fields.url']],
            new \Exception(self::MESSAGE)
        );

        $this->assertSame([], $narrowed['attributesToSearchOn']);
    }

    public function testASearchWithoutNarrowedAttributesIsRethrown(): void
    {
        $this->assertNull(MeiliSearchService::withoutUnknownSearchAttributes(['limit' => 10], new \Exception(self::MESSAGE)));
    }

    // ---- multi-search (the filter sidebar) ----------------------------------

    private const BATCH_MESSAGE = 'Inside `.queries[1]`: ' . self::MESSAGE;

    public function testTheFailingQueryOfABatchIsNamed(): void
    {
        $this->assertSame(1, MeiliSearchService::failedBatchQueryIndex(self::BATCH_MESSAGE));
        $this->assertNull(MeiliSearchService::failedBatchQueryIndex(self::MESSAGE));
    }

    /**
     * Multi-search is all or nothing: one unusable query fails the whole
     * request, so the sidebar loses every count, not just that group's.
     */
    public function testOnlyTheFailingQueryOfABatchIsNarrowed(): void
    {
        $queries = [
            ['filters' => [], 'attributes' => ['table_name'], 'searchOn' => null],
            ['filters' => [], 'attributes' => ['table_name'], 'searchOn' => ['label', 'fields.url']],
        ];

        $narrowed = MeiliSearchService::narrowBatchQuery($queries, new \Exception(self::BATCH_MESSAGE));

        $this->assertNull($narrowed[0]['searchOn'], 'the queries that were fine stay as they were');
        $this->assertSame(['label'], $narrowed[1]['searchOn']);
    }

    public function testABatchErrorAboutSomethingElseIsRethrown(): void
    {
        $queries = [['filters' => [], 'attributes' => ['table_name'], 'searchOn' => ['label']]];

        $this->assertNull(MeiliSearchService::narrowBatchQuery($queries, new \Exception('Index `x` not found.')));
    }

    // ---- the export ---------------------------------------------------------

    /**
     * The results page falls back to MySQL; a download has nothing to fall back
     * to, and the exporter normally never returns at all (it sends the file and
     * exits), so an unhandled search error answers the download with a 500.
     */
    public function testTheExportAnswersASearchFailureOnScreen(): void
    {
        $body = $this->methodBody('Controllers/MeiliGlobalSearchTrait.php', 'protected function exportByMeili(');

        $this->assertStringContainsString('catch (\Throwable $e)', $body);
        $this->assertStringContainsString("exmtrans('search.export_failed')", $body);
    }

    public function testTheExportFailureMessageIsTranslated(): void
    {
        foreach (['en', 'ja'] as $locale) {
            $lang = (array) include dirname(__DIR__, 3) . "/resources/lang/{$locale}/exment.php";
            $this->assertArrayHasKey('export_failed', $lang['search'], "{$locale} is missing search.export_failed");
        }
    }
}
