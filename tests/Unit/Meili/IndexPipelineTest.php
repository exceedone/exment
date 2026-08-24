<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Four separate code paths feed documents into the index, each with its own
 * copy of the "gather column metadata -> map -> push" loop:
 *
 *   ExmentIndexer::indexAll()        exment:meili-index
 *   ExmentIndexer::reindexIds()      exment:meili-reconcile
 *   ReindexMeiliTableJob::handle()   table/column config changed
 *   SyncMeiliDocumentJob::handle()   a record was saved
 *
 * DocumentMapper::map()'s trailing parameters all have defaults, so a site that
 * misses one still runs: no warning, no exception - it just writes documents
 * with no facets and no range fields. Those records stay findable by keyword
 * but silently vanish from every filter, and only for the path that produced
 * them. Two real bugs already came from exactly this (the max(1) clamp and the
 * withoutGlobalScope fix were each applied to some sites and not others).
 *
 * These tests fail the moment the sites drift apart again.
 */
class IndexPipelineTest extends TestCase
{
    /** Every place that turns records into documents. */
    private const CALL_SITES = [
        'src/Services/Meili/ExmentIndexer.php',
        'src/Jobs/ReindexMeiliTableJob.php',
        'src/Jobs/SyncMeiliDocumentJob.php',
    ];

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Count the arguments of every `->map(...)` call in a file, ignoring commas
     * nested inside the arguments themselves.
     *
     * @return array<int,int> one entry per call site, the argument count
     */
    private function mapCallArgCounts(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $counts = [];

        for ($i = 0; $i < count($tokens); $i++) {
            // looking for: -> map (
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || $name[1] !== 'map') {
                continue;
            }
            $open = $i + 2;
            while (isset($tokens[$open]) && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
                $open++;
            }
            if (($tokens[$open] ?? null) !== '(') {
                continue;
            }

            $depth = 0;
            $args = 1;
            for ($j = $open; $j < count($tokens); $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $args++;
                }
            }
            $counts[] = $args;
        }

        return $counts;
    }

    public function testEveryMapCallSitePassesTheFullArgumentList(): void
    {
        $expected = (new ReflectionMethod(DocumentMapper::class, 'map'))->getNumberOfParameters();
        $root = $this->packageRoot();
        $found = 0;

        foreach (self::CALL_SITES as $relative) {
            $path = $root . '/' . $relative;
            $this->assertFileExists($path, "call site moved: {$relative}");

            foreach ($this->mapCallArgCounts($path) as $index => $args) {
                $found++;
                $this->assertSame(
                    $expected,
                    $args,
                    "{$relative}: map() call #" . ($index + 1) . " passes {$args} of {$expected} arguments."
                    . ' A site left behind writes documents with no facets and no range fields,'
                    . ' which disappear from every filter without any error.'
                );
            }
        }

        // Guards against a site being renamed/moved out of CALL_SITES unnoticed.
        $this->assertGreaterThanOrEqual(4, $found, 'expected at least 4 map() call sites, found ' . $found);
    }

    /**
     * The trailing parameters are what a drifting site drops. If they ever stop
     * being optional the risk disappears - and this test should be removed.
     */
    public function testTrailingMapParametersAreStillOptional(): void
    {
        $params = (new ReflectionMethod(DocumentMapper::class, 'map'))->getParameters();
        $optional = array_values(array_filter($params, fn ($p) => $p->isOptional()));

        $this->assertNotEmpty(
            $optional,
            'map() has no optional parameters any more: a missing argument would now be a TypeError,'
            . ' so IndexPipelineTest is obsolete.'
        );
        $this->assertSame(
            ['facetColumns', 'rangeColumns', 'aliases'],
            array_map(fn ($p) => $p->getName(), $optional)
        );
    }

    /**
     * A document built with the full argument list carries the two filter
     * surfaces; one built without them does not. This is the difference a
     * drifting call site produces - silently.
     */
    public function testOmittingTheOptionalArgumentsDropsFacetsAndRangeFields(): void
    {
        $mapper = new DocumentMapper();

        $full = $mapper->buildDocument('contract', 'Contract', 1, 'row', ['title' => 'x'], [
            'facets' => ['contract::status=signed'],
            DocumentMapper::rangeField('contract', 'amount') => 100,
        ]);
        $bare = $mapper->buildDocument('contract', 'Contract', 1, 'row', ['title' => 'x']);

        $this->assertArrayHasKey('facets', $full);
        $this->assertArrayHasKey('n_contract::amount', $full);

        $this->assertArrayNotHasKey('facets', $bare);
        $this->assertArrayNotHasKey('n_contract::amount', $bare);
        // Still findable by keyword either way - that is why it goes unnoticed.
        $this->assertSame(['title' => 'x'], $bare['fields']);
    }
}
