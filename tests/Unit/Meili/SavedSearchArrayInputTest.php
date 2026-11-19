<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\SavedSearchService;
use PHPUnit\Framework\TestCase;

/**
 * The save modal posts the current query string verbatim
 * (resources/views/search/saved-quickbar.blade.php), so every filter param
 * reaches filtersFromInput() exactly as it sat in the URL: `?tables[][]=x`
 * arrives as a NESTED array.
 *
 * strval()/(string) on an array raises "Array to string conversion", which
 * Laravel's error handler turns into an ErrorException -> POST admin/search/saved
 * answers 500. Where the warning is not fatal it is worse: the literal string
 * "Array" was stored, so applying the saved search filtered on a table, a user
 * or a facet literally named "Array" and returned nothing.
 *
 * sanitizeWith() reads the same values back when the saved search is applied,
 * so it needs the same guard for rows written before this fix - there
 * `(int) ['1']` is 1, i.e. a filter on a user id nobody ever chose.
 *
 * Compare RequestFilters::strList(), which has filtered non-scalars all along;
 * this is the same contract, on the save path.
 */
class SavedSearchArrayInputTest extends TestCase
{
    /**
     * Run $fn with PHP diagnostics collected, the way Laravel's error handler
     * sees them at runtime (there they become an ErrorException = HTTP 500).
     *
     * @return mixed
     */
    private function withoutPhpWarnings(callable $fn)
    {
        $raised = [];
        set_error_handler(function ($errno, $message) use (&$raised) {
            $raised[] = $message;

            return true;
        });

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            [],
            $raised,
            'PHP raised a diagnostic; under Laravel that is an ErrorException, i.e. a 500 on this endpoint.'
        );

        return $result;
    }

    public function testNestedArrayParamsAreDroppedInsteadOfBecomingTheStringArray(): void
    {
        $out = $this->withoutPhpWarnings(fn () => SavedSearchService::filtersFromInput([
            'tables' => [['x']],
            'users' => [['1']],
            'facets' => [['t::status=open']],
            'date_from' => ['2026-01-01'],
            'date_to' => ['2026-01-31'],
            'range' => ['n_t::price' => ['from' => ['10'], 'to' => ['20']]],
        ]));

        $this->assertSame([], $out, 'nothing in that payload is a usable filter, so nothing may be stored');
    }

    public function testScalarsStillSurviveNextToTheDroppedArrays(): void
    {
        $out = $this->withoutPhpWarnings(fn () => SavedSearchService::filtersFromInput([
            'tables' => ['contract', ['nested']],
            'users' => [['nested'], 7],
            'facets' => ['t::status=open', ['nested']],
            'range' => ['n_t::price' => ['from' => '10', 'to' => ['nested']]],
        ]));

        $this->assertSame(['contract'], $out['tables']);
        $this->assertSame(['7'], $out['users']);
        $this->assertSame(['t::status=open'], $out['facets']);
        $this->assertSame(['from' => '10'], $out['range']['n_t::price']);
    }

    /**
     * range is keyed by field name. `?range[]=x` gives integer keys, which can
     * never match an n_<table>::<column> attribute, so the whole entry goes.
     */
    public function testListShapedRangeParamIsDropped(): void
    {
        $out = $this->withoutPhpWarnings(fn () => SavedSearchService::filtersFromInput([
            'range' => [['from' => '10']],
        ]));

        $this->assertArrayNotHasKey('range', $out);
    }

    public function testApplyIgnoresNonScalarsAlreadyStoredByTheOldSaveCode(): void
    {
        $out = $this->withoutPhpWarnings(fn () => SavedSearchService::sanitizeWith(
            [
                'tables' => [['x'], 'contract'],
                'users' => [['1'], 2],
                'facets' => [['a=b'], 'status=open'],
                'date_from' => ['2026-01-01'],
                'range' => ['n_t::price' => ['from' => ['1'], 'to' => '9']],
            ],
            [
                'tables' => ['contract'],
                'facet_columns' => ['status'],
                'range_fields' => ['n_t::price'],
                'user_ids' => [1, 2],
            ]
        ));

        $this->assertSame(['contract'], $out['params']['tables']);
        // (int) ['1'] is 1: the nested array used to become a filter on user #1.
        $this->assertSame([2], $out['params']['users']);
        $this->assertSame(['status=open'], $out['params']['facets']);
        $this->assertArrayNotHasKey('date_from', $out['params']);
        $this->assertSame(['to' => '9'], $out['params']['range']['n_t::price']);
        $this->assertSame(
            [],
            $out['dropped'],
            'a non-scalar was never a filter, so there is nothing to warn the user about'
        );
    }
}
