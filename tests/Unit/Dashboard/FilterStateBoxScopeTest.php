<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomTable;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;
use Exceedone\Exment\Services\Dashboard\FilterState;

/**
 * Feature 1 (chart-level filter, `bf_*` + options.chart_filters) and Feature 2 (slicer
 * targeting, filter_bar.dims[].targets) at the QUERY level — FilterState is where both
 * gates live, and every consumer (box query, badge, benchmark, selective
 * refresh) reads them from here.
 *
 * World built in memory: unsaved Dashboard/DashboardBox models + Fake table/columns +
 * a FakeQuery that records the SQL. Assertions are on WHICH columns reach SQL for WHICH
 * box, and that the legacy call shapes stay byte-identical when no new config exists.
 */
class FilterStateBoxScopeTest extends DashboardUnitTestCase
{
    /** @var FakeCustomTable region / subject / score(number) — all indexed */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = new FakeCustomTable([
            new FakeCustomColumn('region', 'select_table', true),
            new FakeCustomColumn('subject', 'select_table', true),
            new FakeCustomColumn('score', 'integer', true),
        ]);
    }

    /** Column names that reached the query, in order (index columns → names). */
    protected function filtered(FakeQuery $q): array
    {
        return array_map(function ($c) {
            $expr = $c[1][0];
            preg_match('/column_suuid_([a-z_]+)/', $expr, $m);
            return $m[1] ?? $expr;
        }, $q->calls);
    }

    protected function apply(array $params, $box, array $except = []): FakeQuery
    {
        $this->swapRequest($params);
        $q = new FakeQuery();
        FilterState::applyTo($q, $this->table, $except, $box);
        return $q;
    }

    // =========================================================================
    // Slicer targeting — targetsAllow()
    // =========================================================================

    public function testNoTargetsKeyMeansEveryBox(): void
    {
        $d = $this->makeDashboard($this->bar(['region', 'subject']));
        $box = $this->makeBox('b1', [], $d);
        $this->assertTrue(FilterState::targetsAllow($box, 'region'));
        $this->assertTrue(FilterState::targetsAllow($box, 'subject'));
    }

    public function testEmptyTargetsListMeansEveryBox(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => []]]));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b1', [], $d), 'region'));
    }

    public function testTargetsWhitelistBySuuid(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => ['b1', 'b3']]]));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b1', [], $d), 'region'));
        $this->assertFalse(FilterState::targetsAllow($this->makeBox('b2', [], $d), 'region'));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b3', [], $d), 'region'));
    }

    /** Targeting is per DIM: an untargeted dim on the same dashboard still reaches every box. */
    public function testTargetingIsPerDim(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => ['b1']], 'subject']));
        $b2 = $this->makeBox('b2', [], $d);
        $this->assertFalse(FilterState::targetsAllow($b2, 'region'));
        $this->assertTrue(FilterState::targetsAllow($b2, 'subject'));
    }

    /** A df_ param that is not a configured dim (deep link / stray) keeps legacy name-match. */
    public function testStrayColumnIsNotGated(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => ['b1']]]));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b2', [], $d), 'score'));
    }

    public function testNoBoxOrNoBarConfigAllows(): void
    {
        $this->assertTrue(FilterState::targetsAllow(null, 'region'));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b1', [], $this->makeDashboard(null)), 'region'));
        // a filter_bar without a dims array is "no config" (same contract as fromDashboard())
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b1', [], $this->makeDashboard(['source_table' => 'x'])), 'region'));
    }

    /** Junk in the targets key can only widen back to legacy, never break. */
    public function testJunkTargetsFallBackToLegacy(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => 'b1']]));  // string, not a list
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('b2', [], $d), 'region'));
        // int suuid-like entries compare as strings
        $d2 = $this->makeDashboard($this->bar(['region' => ['targets' => [123]]]));
        $this->assertTrue(FilterState::targetsAllow($this->makeBox('123', [], $d2), 'region'));
    }

    // =========================================================================
    // columnsOn(): "applied" set of a box (whitelist + targeting)
    // =========================================================================

    public function testColumnsOnLegacyShapeWithoutBox(): void
    {
        $this->swapRequest(['df_region' => '3', 'df_subject' => ['1', '2'], 'df_nope' => '9', 'df_bad-id' => '1', 'bf_region' => '4']);
        $this->assertSame(
            ['region' => '3', 'subject' => ['in' => ['1', '2']]],
            FilterState::columnsOn($this->table),
            'single value stays a plain string; a list is its spec; unknown/junk columns dropped'
        );
    }

    public function testColumnsOnHonorsTargeting(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => ['b1']], 'subject']));
        $this->swapRequest(['df_region' => '3', 'df_subject' => '5']);
        $this->assertSame(['region' => '3', 'subject' => '5'], FilterState::columnsOn($this->table, $this->makeBox('b1', [], $d)));
        $this->assertSame(['subject' => '5'], FilterState::columnsOn($this->table, $this->makeBox('b2', [], $d)));
    }

    public function testColumnsOnNullTable(): void
    {
        $this->swapRequest(['df_region' => '3']);
        $this->assertSame([], FilterState::columnsOn(null));
    }

    // =========================================================================
    // boxFilters(): the bf_ whitelist (declared ∩ identifier; table membership in applyTo)
    // =========================================================================

    public function testBoxFiltersRequireDeclaration(): void
    {
        $this->swapRequest(['bf_region' => '3', 'bf_subject' => '5']);
        $this->assertSame([], FilterState::boxFilters($this->makeBox('b1', [])), 'no chart_filters → nothing');
        $this->assertSame(['region' => '3'], FilterState::boxFilters($this->makeBox('b1', ['chart_filters' => ['region']])));
        $this->assertSame([], FilterState::boxFilters(null));
    }

    public function testBoxFiltersValueShapesAndJunk(): void
    {
        $this->swapRequest([
            'bf_region' => ['3', '4'],
            'bf_score' => ['from' => '80', 'to' => ''],
            'bf_subject' => '',
            'bf_x' => '1',
        ]);
        $box = $this->makeBox('b1', ['chart_filters' => ['region', 'score', 'subject', 'bad name', '', 7, 'x;drop']]);
        $this->assertSame([
            'region' => ['in' => ['3', '4']],
            'score' => ['from' => '80', 'to' => null],
        ], FilterState::boxFilters($box));
    }

    /** With the box table given, a declared column that is not on it is dropped (count / scope honesty). */
    public function testBoxFiltersTableMembership(): void
    {
        $this->swapRequest(['bf_region' => '3', 'bf_ghost' => '9']);
        $box = $this->makeBox('b1', ['chart_filters' => ['region', 'ghost']]);
        $this->assertSame(['region' => '3', 'ghost' => '9'], FilterState::boxFilters($box), 'without a table: declared ∩ identifier only (legacy)');
        $this->assertSame(['region' => '3'], FilterState::boxFilters($box, $this->table));
        $this->assertSame(['region' => '3', 'ghost' => '9'], FilterState::boxFilters($box, null));
    }

    public function testBoxFiltersConfigMustBeAList(): void
    {
        $this->swapRequest(['bf_region' => '3']);
        $this->assertSame([], FilterState::boxFilters($this->makeBox('b1', ['chart_filters' => 'region'])));
        $this->assertSame([], FilterState::boxFilters($this->makeBox('b1', ['chart_filters' => []])));
    }

    // =========================================================================
    // applyTo(): the query — df (targeted) AND bf, $except on both
    // =========================================================================

    /** Backward compat: the 3-arg call and the box-aware call are byte-identical without new config. */
    public function testLegacyAndBoxAwareCallsAgreeWithoutNewConfig(): void
    {
        $params = ['df_region' => '3', 'df_subject' => ['1', '2'], 'df_unknown' => 'x'];
        $this->swapRequest($params);
        $legacy = new FakeQuery();
        FilterState::applyTo($legacy, $this->table);

        $box = $this->makeBox('b1', [], $this->makeDashboard($this->bar(['region', 'subject'])));
        $aware = $this->apply($params, $box);

        $this->assertSame($legacy->calls, $aware->calls);
        $this->assertSame([
            ['where', ['column_suuid_region', '3']],
            ['whereIn', ['column_suuid_subject', ['1', '2']]],
        ], $legacy->calls);
    }

    public function testApplyToSkipsDimForUntargetedBox(): void
    {
        $d = $this->makeDashboard($this->bar(['region' => ['targets' => ['b1']], 'subject']));
        $params = ['df_region' => '3', 'df_subject' => '5'];
        $this->assertSame(['region', 'subject'], $this->filtered($this->apply($params, $this->makeBox('b1', [], $d))));
        $this->assertSame(['subject'], $this->filtered($this->apply($params, $this->makeBox('b2', [], $d))));
    }

    public function testApplyToAndsBoxFiltersAfterDashboardFilters(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['subject']]);
        $q = $this->apply(['df_region' => '3', 'bf_subject' => '5'], $box);
        $this->assertSame([
            ['where', ['column_suuid_region', '3']],
            ['where', ['column_suuid_subject', '5']],
        ], $q->calls, 'df first, then bf — both AND');
    }

    /** Same column on both levels: they intersect (df AND bf), never override each other. */
    public function testSameColumnOnBothLevelsIntersects(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['region']]);
        $q = $this->apply(['df_region' => ['1', '2'], 'bf_region' => '2'], $box);
        $this->assertSame([
            ['whereIn', ['column_suuid_region', ['1', '2']]],
            ['where', ['column_suuid_region', '2']],
        ], $q->calls);
    }

    public function testBfIgnoredWithoutDeclarationOrOnOtherBox(): void
    {
        $declared = $this->makeBox('b1', ['chart_filters' => ['subject']]);
        $other = $this->makeBox('b2', []);
        $params = ['bf_subject' => '5', 'bf_region' => '3'];
        $this->assertSame(['subject'], $this->filtered($this->apply($params, $declared)), 'undeclared bf_region ignored');
        $this->assertSame([], $this->filtered($this->apply($params, $other)), 'a box without chart_filters never reads bf_');
        $this->assertSame([], $this->filtered($this->apply($params, null)), 'legacy call never reads bf_');
    }

    /** A declared column that no longer exists on the table is skipped, not an error. */
    public function testBfDeclaredButMissingOnTableIsSkipped(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['ghost', 'subject']]);
        $this->assertSame(['subject'], $this->filtered($this->apply(['bf_ghost' => '1', 'bf_subject' => '2'], $box)));
    }

    /** $except (ranking selected-group / parent-scope queries) drops the dim on BOTH levels. */
    public function testExceptAppliesToDfAndBf(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['region', 'subject']]);
        $q = $this->apply(['df_region' => '1', 'bf_region' => '2', 'bf_subject' => '3'], $box, ['region']);
        $this->assertSame(['subject'], $this->filtered($q));
    }

    public function testRangeBfOnNumberColumnCasts(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['score']]);
        $q = $this->apply(['bf_score' => ['from' => '80', 'to' => '100']], $box);
        $this->assertSame([
            ['whereRaw', ['`column_suuid_score` IS NOT NULL AND `column_suuid_score` <> \'\'']],
            ['whereRaw', ['CAST(`column_suuid_score` AS DECIMAL(20,4)) >= ?', ['80']]],
            ['whereRaw', ['CAST(`column_suuid_score` AS DECIMAL(20,4)) <= ?', ['100']]],
        ], $q->calls);
    }

    public function testApplyToNoopOnNullTableOrModel(): void
    {
        $this->swapRequest(['df_region' => '3']);
        $q = new FakeQuery();
        FilterState::applyTo($q, null, [], $this->makeBox('b1', []));
        $this->assertSame([], $q->calls);
        // null model must not throw
        FilterState::applyTo(null, $this->table);
        $this->assertTrue(true);
    }

    /** Column names never reach SQL unless they are identifiers AND exist on the table. */
    public function testColumnNameInjectionIsImpossible(): void
    {
        $this->swapRequest(['df_region`; DROP TABLE x; --' => '1', 'df_region' => '1']);
        $q = new FakeQuery();
        FilterState::applyTo($q, $this->table);
        $this->assertSame([['where', ['column_suuid_region', '1']]], $q->calls);
    }
}
