<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use ReflectionMethod;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;

/**
 * Feature 2 — slicer targeting, ADMIN side: the dashboard form's filter-item rows go
 * through Dashboard::setFilterBarDimsAttribute. What matters:
 *
 *  - `targets` are stored only when non-empty (empty = key absent = legacy "every box"),
 *    so an untouched dashboard's options stay byte-identical;
 *  - a save from a form WITHOUT the targets field must not silently drop stored targets
 *    (an empty multi-select posts no key at all → the `targets_submitted` sentinel
 *    disambiguates an intentional clear from an unaware submitter);
 *  - keys the form does not manage (disables, note, advanced, style, from_master, parent)
 *    ride through a save untouched;
 *  - normalizeFilterBarOption() (the saving hook) drops half-configured bars, duplicate
 *    columns, dangling / looping parents.
 *
 * All on an unsaved model — the mutators only rewrite the options JSON in memory.
 */
class DashboardFilterBarTargetsTest extends DashboardUnitTestCase
{
    protected function dashboardWith(array $dims, array $bar = []): Dashboard
    {
        return $this->makeDashboard(['source_table' => 'f_score', 'dims' => $dims] + $bar);
    }

    protected function dims(Dashboard $d): array
    {
        return $d->getOption('filter_bar.dims');
    }

    // ---- accessor (form load) ------------------------------------------------------

    public function testAccessorExposesTargetsAsStringList(): void
    {
        $d = $this->dashboardWith([
            ['column' => 'region', 'targets' => ['b1', 7, null, 'b2']],
            ['column' => 'subject'],
        ]);
        $rows = $d->filter_bar_dims;
        $this->assertSame(['b1', 'b2'], $rows[0]['targets']);
        $this->assertSame([], $rows[1]['targets']);
        $this->assertSame('region', $rows[0]['column']);
        $this->assertSame([], $this->makeDashboard(null)->filter_bar_dims);
    }

    // ---- mutator (form save) -------------------------------------------------------

    public function testTargetsStoredOnlyWhenNonEmpty(): void
    {
        $d = $this->dashboardWith([]);
        $d->filter_bar_dims = [
            ['column' => 'region', 'label' => '地方', 'targets' => ['b1', 'b2'], 'targets_submitted' => 1],
            ['column' => 'subject', 'label' => '', 'targets_submitted' => 1],
        ];
        $dims = $this->dims($d);
        $this->assertSame(['column' => 'region', 'label' => '地方', 'targets' => ['b1', 'b2']], $dims[0]);
        $this->assertSame(['column' => 'subject', 'label' => 'subject'], $dims[1], 'empty targets → key absent; empty label → column');
    }

    public function testSentinelClearsStoredTargets(): void
    {
        $d = $this->dashboardWith([['column' => 'region', 'targets' => ['b1']]]);
        $d->filter_bar_dims = [['column' => 'region', 'targets_submitted' => 1]]; // multi-select posted nothing
        $this->assertArrayNotHasKey('targets', $this->dims($d)[0], 'form knew about targets → absent = intentional clear');
    }

    public function testUnawareFormKeepsStoredTargets(): void
    {
        $d = $this->dashboardWith([['column' => 'region', 'targets' => ['b1']]]);
        $d->filter_bar_dims = [['column' => 'region', 'label' => 'R']]; // no targets key, no sentinel
        $this->assertSame(['b1'], $this->dims($d)[0]['targets'], 'a submitter without the field must not drop stored targets');
    }

    public function testExplicitTargetsKeyWithoutSentinelStillCounts(): void
    {
        $d = $this->dashboardWith([['column' => 'region', 'targets' => ['b1']]]);
        $d->filter_bar_dims = [['column' => 'region', 'targets' => ['b9']]];
        $this->assertSame(['b9'], $this->dims($d)[0]['targets']);
    }

    public function testTargetsJunkFiltered(): void
    {
        $d = $this->dashboardWith([]);
        $d->filter_bar_dims = [['column' => 'region', 'targets' => ['b1', '', 5, null, 'b1']]];
        $this->assertSame(['b1', 'b1'], $this->dims($d)[0]['targets'], 'non-string / empty dropped (no dedupe here — harmless for in_array)');
    }

    /** Keys the form no longer exposes survive a save when the row does not carry them. */
    public function testUnmanagedKeysRideThrough(): void
    {
        $d = $this->dashboardWith([[
            'column' => 'region', 'label' => 'R', 'parent' => '-', 'style' => 'range',
            'from_master' => true, 'advanced' => true, 'note' => 'n', 'disables' => ['score'],
            'targets' => ['b1'],
        ]]);
        $d->filter_bar_dims = [['column' => 'region', 'label' => 'R2', 'targets' => ['b1'], 'targets_submitted' => 1]];
        $dim = $this->dims($d)[0];
        $this->assertSame('R2', $dim['label']);
        $this->assertSame('-', $dim['parent']);
        $this->assertSame('range', $dim['style']);
        $this->assertTrue($dim['from_master']);
        $this->assertTrue($dim['advanced']);
        $this->assertSame('n', $dim['note']);
        $this->assertSame(['score'], $dim['disables']);
        $this->assertSame(['b1'], $dim['targets']);
    }

    public function testRowsThatCarryAKeyMayClearIt(): void
    {
        $d = $this->dashboardWith([['column' => 'region', 'parent' => 'x', 'style' => 'range', 'from_master' => true, 'advanced' => true, 'note' => 'n']]);
        $d->filter_bar_dims = [['column' => 'region', 'parent' => '', 'style' => 'auto', 'from_master' => 0, 'advanced' => '0', 'note' => '']];
        $this->assertSame(['column' => 'region', 'label' => 'region'], $this->dims($d)[0]);
    }

    public function testBlankRowsDroppedAndOtherOptionsUntouched(): void
    {
        $d = $this->makeDashboard(['source_table' => 'f_score', 'dims' => [], 'root_label' => 'ALL'], ['ai_summary' => 1, 'row1' => '3']);
        $d->filter_bar_dims = [['column' => '', 'label' => 'x'], ['column' => ' region ']];
        $this->assertSame([['column' => 'region', 'label' => 'region']], $this->dims($d));
        $this->assertSame('ALL', $d->getOption('filter_bar.root_label'));
        $this->assertSame(1, $d->getOption('ai_summary'));
        $this->assertSame('3', $d->getOption('row1'));

        $d->filter_bar_dims = [];
        $this->assertNull($d->getOption('filter_bar.dims'), 'no rows → dims key removed (normalize then forgets the bar)');
    }

    // ---- normalizeFilterBarOption() (saving hook) --------------------------------------

    protected function normalize(Dashboard $d): void
    {
        $m = new ReflectionMethod($d, 'normalizeFilterBarOption');
        $m->setAccessible(true);
        $m->invoke($d);
    }

    public function testNormalizeForgetsHalfConfiguredBar(): void
    {
        $d = $this->makeDashboard(['source_table' => '', 'dims' => [['column' => 'a']]]);
        $this->normalize($d);
        $this->assertNull($d->getOption('filter_bar'), 'no source table → whole bar dropped');

        $d = $this->makeDashboard(['source_table' => 'f_score', 'dims' => []]);
        $this->normalize($d);
        $this->assertNull($d->getOption('filter_bar'), 'no dims → whole bar dropped');

        $d = $this->makeDashboard(null, ['ai_summary' => 1]);
        $this->normalize($d);
        $this->assertSame(['ai_summary' => 1], $d->options, 'no bar at all → untouched');
    }

    public function testNormalizeKeepsFirstOfDuplicateColumns(): void
    {
        $d = $this->dashboardWith([['column' => 'a', 'label' => 'first', 'targets' => ['b1']], ['column' => 'a', 'label' => 'second'], ['column' => 'b']]);
        $this->normalize($d);
        $dims = $this->dims($d);
        $this->assertCount(2, $dims);
        $this->assertSame('first', $dims[0]['label']);
        $this->assertSame(['b1'], $dims[0]['targets'], 'targets of the kept row survive');
    }

    public function testNormalizeDropsDanglingSelfAndLoopingParents(): void
    {
        $d = $this->dashboardWith([
            ['column' => 'a', 'parent' => 'b'],
            ['column' => 'b', 'parent' => 'a'],       // loop a<->b
            ['column' => 'c', 'parent' => 'c'],       // self
            ['column' => 'd', 'parent' => 'ghost'],   // dangling
            ['column' => 'e', 'parent' => FilterBarConfig::PARENT_NONE],
            ['column' => 'f', 'parent' => 'e'],       // valid
        ]);
        $this->normalize($d);
        $by = collect($this->dims($d))->keyBy('column');
        $this->assertArrayNotHasKey('parent', $by['c']);
        $this->assertArrayNotHasKey('parent', $by['d']);
        $this->assertSame('-', $by['e']['parent'], "'-' (forced none) is preserved");
        $this->assertSame('e', $by['f']['parent']);
        // the loop is cut somewhere: at least one of a/b lost its parent, none points into a cycle
        $cfg = FilterBarConfig::fromArray(['dims' => array_values($by->all())]);
        $this->assertNotSame(['a' => 'b', 'b' => 'a'], ['a' => $cfg->parentOf('a'), 'b' => $cfg->parentOf('b')]);
        $this->assertIsArray($cfg->chainColumns(), 'chain derivation terminates');
    }
}
