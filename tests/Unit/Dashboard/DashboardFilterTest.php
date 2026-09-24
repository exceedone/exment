<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\DashboardFilter;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomTable;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;

class DashboardFilterTest extends DashboardUnitTestCase
{
    private function table(): FakeCustomTable
    {
        return new FakeCustomTable([
            new FakeCustomColumn('grade', 'select_table', true),
            new FakeCustomColumn('subject', 'select_table', true),
            new FakeCustomColumn('score', 'integer', false),
        ]);
    }

    public function testReadsOnlyConfiguredItems()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject', 'score']));
        $this->swapRequest(['df_grade' => '1', 'df_subject' => ['2', '3'], 'df_score' => ['from' => '50'], 'df_other' => 'x', 'df_' => 'y', 'grade' => 'z']);
        $filter = DashboardFilter::fromRequest($dashboard);

        $this->assertFalse($filter->isEmpty());
        $this->assertSame(['grade', 'subject', 'score'], array_keys($filter->values()));
        $this->assertSame(['in' => ['1']], $filter->spec('grade'));
        $this->assertSame(['in' => ['2', '3']], $filter->spec('subject'));
        $this->assertSame(['from' => '50', 'to' => null], $filter->spec('score'));
        $this->assertNull($filter->spec('other'));
    }

    public function testSameAsComparesTheSelectionNotTheParams()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject', 'score']));
        $of = function (array $params) use ($dashboard) {
            return DashboardFilter::of($dashboard, $params);
        };
        $defaults = $of(['df_grade' => '1', 'df_subject' => ['2', '3']]);

        $this->assertTrue($of(['df_subject' => ['3', '2'], 'df_grade' => ['1']])->sameAs($defaults), 'value order, item order and one-vs-list shape do not matter');
        $this->assertTrue($of(['df_grade' => '1', 'df_subject' => ['2', '3'], 'df_other' => 'x'])->sameAs($defaults), 'params of items not on the bar are no selection');
        $this->assertFalse($of(['df_grade' => '1', 'df_subject' => '2'])->sameAs($defaults));
        $this->assertFalse($of(['df_grade' => '1'])->sameAs($defaults));
        $this->assertFalse($of([])->sameAs($defaults), 'an emptied bar is not at its defaults');
        $this->assertTrue($of([])->sameAs($of(['df_grade' => ''])), 'no defaults: an empty bar is at them');
        $this->assertTrue($of(['df_score' => ['from' => '50']])->sameAs($of(['df_score' => ['from' => '50', 'to' => '']])));
        $this->assertFalse($of(['df_score' => ['from' => '50']])->sameAs($of(['df_score' => ['to' => '50']])));
    }

    public function testNoBarMeansNoFilterEvenWithParams()
    {
        $filter = DashboardFilter::of($this->makeDashboard(null), ['df_grade' => '1']);
        $this->assertTrue($filter->isEmpty());
        $this->assertNull($filter->config());
        $this->assertSame([], $filter->columnsFor($this->table(), null));
        $this->assertSame('', $filter->fingerprint());
    }

    public function testColumnsForRespectsTableMembershipAndTargeting()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject' => ['targets' => ['box1']], 'region']));
        $box1 = $this->makeBox('box1', [], $dashboard);
        $box2 = $this->makeBox('box2', [], $dashboard);
        $filter = DashboardFilter::of($dashboard, ['df_grade' => '1', 'df_subject' => '2', 'df_region' => '3']);

        $this->assertSame(['grade', 'subject'], array_keys($filter->columnsFor($this->table(), $box1)));
        $this->assertSame(['region'], $filter->ignoredFor($this->table(), $box1), 'region is not a column of this table');

        $this->assertSame(['grade'], array_keys($filter->columnsFor($this->table(), $box2)));
        $this->assertSame(['subject', 'region'], $filter->ignoredFor($this->table(), $box2), 'subject targets box1 only');

        $this->assertSame([], $filter->columnsFor(null, $box1));
    }

    public function testSelectionInTheWrongShapeForItsControlNarrowsNothing()
    {
        // grade always lists its values (text), so a from / to left over in a URL (or in
        // the remembered selection) has no control that could show it — it must not filter
        // in silence. score is a number: the bar shows from / to for it once its list is
        // capped, so its range is honoured although the item is not configured as one
        $dashboard = $this->makeDashboard($this->bar(['grade', 'score']));
        $box = $this->makeBox('box1', [], $dashboard);
        $filter = DashboardFilter::of($dashboard, ['df_grade' => ['from' => '1', 'to' => '3'], 'df_score' => ['from' => '45', 'to' => '59']]);

        $q = new FakeQuery();
        $this->assertSame(['score'], $filter->applyTo($q, $this->table(), $box));
        $this->assertSame([
            'JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) <> \'\'',
            'CAST(JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) AS DECIMAL(20,4)) >= ? ["45"]',
            'CAST(JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) AS DECIMAL(20,4)) <= ? ["59"]',
        ], $q->sql(), 'the stale range on the text item emits no WHERE; the number range does');
        $this->assertSame(['grade'], $filter->ignoredFor($this->table(), $box));

        // and the mirror image: a picked value on an item that asks for from / to
        $ranged = $this->makeDashboard($this->bar(['score' => ['style' => 'range']]));
        $rangedBox = $this->makeBox('box1', [], $ranged);
        $picked = DashboardFilter::of($ranged, ['df_score' => '93']);
        $this->assertSame([], $picked->applyTo(new FakeQuery(), $this->table(), $rangedBox));
    }

    public function testStyleOfIsTheOneRuleForAnItemsControl()
    {
        // the bar, columnsFor and a chart's highlight all decide by styleOf, so an item that
        // shows from / to for its current value is a range item to every one of them at
        // once — whichever column it is, and whether its list outgrew the cap or the user
        // typed the range. Nothing else has a rule of its own.
        $dashboard = $this->makeDashboard($this->bar(['grade', 'score', 'subject' => ['style' => 'range']]));
        $score = $this->table()->custom_columns->firstWhere('column_name', 'score');
        $grade = $this->table()->custom_columns->firstWhere('column_name', 'grade');
        $subject = $this->table()->custom_columns->firstWhere('column_name', 'subject');

        $empty = DashboardFilter::of($dashboard, []);
        $this->assertSame('select', $empty->styleOf($score), 'a number lists its values while nothing is on it');
        $this->assertSame('select', $empty->styleOf($grade));
        $this->assertSame('range', $empty->styleOf($subject), 'the configured style');

        $ranged = DashboardFilter::of($dashboard, ['df_score' => ['from' => '45', 'to' => '59'], 'df_grade' => ['from' => '1']]);
        $this->assertSame('range', $ranged->styleOf($score), 'a from / to on a number: from / to, so a chart on score filters by it instead of highlighting it');
        $this->assertSame('select', $ranged->styleOf($grade), 'a text column never compares: its stale range is no control');
        $this->assertSame(['score'], array_keys($ranged->columnsFor($this->table())), 'columnsFor decides by the same rule');

        $picked = DashboardFilter::of($dashboard, ['df_score' => ['45', '59']]);
        $this->assertSame('select', $picked->styleOf($score), 'picked values keep the list');
        $this->assertSame(['45', '59'], $picked->selected('score'));

        $this->assertSame('select', DashboardFilter::of($this->makeDashboard(null), [])->styleOf($score), 'no bar: the column default');
    }

    public function testApplyToEmitsOneWherePerHonouredItem()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject' => ['targets' => ['box1']], 'score' => ['style' => 'range']]));
        $box2 = $this->makeBox('box2', [], $dashboard);
        $filter = DashboardFilter::of($dashboard, ['df_grade' => ['1', '2'], 'df_subject' => '5', 'df_score' => ['to' => '60']]);

        $q = new FakeQuery();
        $applied = $filter->applyTo($q, $this->table(), $box2);
        $this->assertSame(['grade', 'score'], $applied);
        $this->assertSame([
            'column_grade IN ["1","2"]',
            'JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) <> \'\'',
            'CAST(JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\')) AS DECIMAL(20,4)) <= ? ["60"]',
        ], $q->sql());
    }

    public function testFixedScopeNarrowsOptionListsOnly()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade'], ['scope' => ['subject' => '3', 'school' => '17'], 'max_options' => 1000]));
        $filter = DashboardFilter::of($dashboard, ['df_grade' => '1']);
        $this->assertSame(1000, $filter->maxOptions());
        $this->assertSame(['subject'], array_keys($filter->fixedScopeColumnsFor($this->table())), 'only scope columns the table carries');
        $this->assertSame([], $filter->fixedScopeColumnsFor(null));

        $q = new FakeQuery();
        $filter->applyFixedScope($q, $this->table());
        $this->assertSame(['column_subject = "3"'], $q->sql(), 'only scope columns the table carries');

        $q = new FakeQuery();
        $filter->applyTo($q, $this->table(), null);
        $this->assertSame(['column_grade = "1"'], $q->sql(), 'box data is never narrowed by the fixed scope');

        $this->assertSame(500, DashboardFilter::of($this->makeDashboard(null), [])->maxOptions());
    }

    public function testExceptedItemNarrowsNothingAndLeavesTheFingerprint()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject', 'score']));
        $filter = DashboardFilter::of($dashboard, ['df_grade' => '1', 'df_subject' => ['2', '3'], 'df_score' => ['from' => '50']]);

        $q = new FakeQuery();
        $this->assertSame(['grade'], $filter->applyTo($q, $this->table(), null, ['subject', 'score']));
        $this->assertSame(['column_grade = "1"'], $q->sql(), 'a chart highlights its own X item instead of filtering by it');
        $this->assertSame(['2', '3'], $filter->selected('subject'));
        $this->assertSame([], $filter->selected('score'), 'a range item has no picked values');
        $this->assertSame([], $filter->selected('other'));

        $same = DashboardFilter::of($dashboard, ['df_grade' => '1', 'df_score' => ['from' => '50']]);
        $this->assertSame($same->fingerprint(), $filter->fingerprint(['subject']), 'a pick on the highlighted item changes no data, so no cache key');
        $this->assertNotSame($same->fingerprint(), $filter->fingerprint());
        $this->assertSame('', $filter->fingerprint(['grade', 'subject', 'score']));
    }

    public function testLabelsAndFingerprint()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade' => ['label' => '学年'], 'subject']));
        $a = DashboardFilter::of($dashboard, ['df_grade' => ['1', '2'], 'df_subject' => '5']);
        $b = DashboardFilter::of($dashboard, ['df_subject' => '5', 'df_grade' => ['2', '1']]);
        $c = DashboardFilter::of($dashboard, ['df_grade' => '1']);

        $this->assertSame(['学年', 'subject'], $a->labels(['grade', 'subject']));
        $this->assertSame($a->fingerprint(), $b->fingerprint(), 'param order and value order do not matter');
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
        $this->assertSame(32, strlen($a->fingerprint()));
    }
}
