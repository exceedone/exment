<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use PHPUnit\Framework\TestCase;

/**
 * `range[n_<table>::<col>][from|to]` is read back out of the request twice for
 * DISPLAY - to re-fill the sidebar min/max boxes (FilterSidebar::rangeInputs)
 * and to label the applied chip (AppliedChips::build) - and both did it with a
 * bare `(string) ($r['from'] ?? '')`.
 *
 * The param is attacker-controlled. `?range[n_t::c][from][]=1` makes that side
 * an ARRAY, and casting an array to string raises "Array to string conversion",
 * which Laravel's error handler turns into an ErrorException.
 *
 * On the search page it never surfaces as a 500, which is what hid it:
 * SearchController::getFreeWord wraps the whole Meili branch in
 * catch (\Throwable) and falls back to the plain view. The page answers 200
 * with the ENTIRE Meilisearch UI missing - filter sidebar, applied chips,
 * export buttons, saved-search quickbar and the sort selector - and nothing on
 * screen says why.
 *
 * Every other param on this page has been guarded since day one
 * (RequestFilters::str/strList, AppliedChips::stringList,
 * SavedSearchService::scalarList); the two range sides were the gap.
 * See tests/Feature/MeiliSearchFilterTest for the HTTP-level regression.
 */
class RangeSideGuardTest extends TestCase
{
    /**
     * Run $fn with PHP diagnostics collected, the way Laravel's error handler
     * sees them at runtime (there they become an ErrorException).
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
            'PHP raised a diagnostic; under Laravel that is an ErrorException, which silently drops the Meili search UI.'
        );

        return $result;
    }

    public function testANestedArraySideBecomesAnEmptyStringInsteadOfWarning(): void
    {
        $range = ['from' => ['1'], 'to' => ['9']];

        $this->assertSame('', $this->withoutPhpWarnings(fn () => RequestFilters::rangeSide($range, 'from')));
        $this->assertSame('', $this->withoutPhpWarnings(fn () => RequestFilters::rangeSide($range, 'to')));
    }

    public function testTheOldExpressionReallyDidWarnOnTheSameInput(): void
    {
        // Pins WHY the guard exists: without it the very same value warns.
        $range = ['from' => ['1']];

        $raised = [];
        set_error_handler(function ($errno, $message) use (&$raised) {
            $raised[] = $message;

            return true;
        });

        try {
            $unguarded = (string) ($range['from'] ?? '');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('Array', $unguarded, 'the unguarded cast produced the literal string "Array"');
        $this->assertNotSame([], $raised, 'the unguarded cast must raise the diagnostic this guard prevents');
    }

    public function testScalarSidesStillPassThroughUnchanged(): void
    {
        $range = ['from' => '100', 'to' => '2025-01-31'];

        $this->assertSame('100', RequestFilters::rangeSide($range, 'from'));
        $this->assertSame('2025-01-31', RequestFilters::rangeSide($range, 'to'));
    }

    public function testNumericAndBooleanSidesAreStringified(): void
    {
        $this->assertSame('100', RequestFilters::rangeSide(['from' => 100], 'from'));
        $this->assertSame('1.5', RequestFilters::rangeSide(['from' => 1.5], 'from'));
        // No box ever posts a bool, but is_scalar() accepts it - pin the result.
        $this->assertSame('1', RequestFilters::rangeSide(['from' => true], 'from'));
    }

    public function testAMissingSideIsAnEmptyString(): void
    {
        $this->assertSame('', RequestFilters::rangeSide(['to' => '9'], 'from'));
        $this->assertSame('', RequestFilters::rangeSide([], 'from'));
    }

    public function testANonArrayRangeIsAnEmptyString(): void
    {
        // ?range[n_t::c]=junk -> the whole field is a string, not a from/to pair.
        $this->assertSame('', $this->withoutPhpWarnings(fn () => RequestFilters::rangeSide('junk', 'from')));
        $this->assertSame('', $this->withoutPhpWarnings(fn () => RequestFilters::rangeSide(null, 'from')));
        $this->assertSame('', $this->withoutPhpWarnings(fn () => RequestFilters::rangeSide(5, 'from')));
    }

    /**
     * The guard is only worth anything if both display paths actually use it.
     * Neither may cast a raw request value to string on its own again.
     */
    public function testBothDisplayPathsGoThroughTheGuard(): void
    {
        $paths = [
            'GlobalSearch/AppliedChips.php',
            'GlobalSearch/FilterSidebar.php',
        ];

        foreach ($paths as $path) {
            $source = (string) file_get_contents(
                __DIR__ . '/../../../src/Services/Meili/' . $path
            );

            $this->assertMatchesRegularExpression(
                "/RequestFilters::rangeSide\([^)]*, 'from'\)/",
                $source,
                "{$path} must read the 'from' box through RequestFilters::rangeSide()."
            );
            $this->assertMatchesRegularExpression(
                "/RequestFilters::rangeSide\([^)]*, 'to'\)/",
                $source,
                "{$path} must read the 'to' box through RequestFilters::rangeSide()."
            );
            $this->assertDoesNotMatchRegularExpression(
                "/\(string\) \(\\\$[A-Za-z_]+(\[[^]]*\])*\['(from|to)'\] \?\? ''\)/",
                $source,
                "{$path} still casts a raw range value to string; that is the bug this guards."
            );
        }
    }
}
