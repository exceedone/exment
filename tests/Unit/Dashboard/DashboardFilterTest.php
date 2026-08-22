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

    public function testApplyToEmitsOneWherePerHonouredItem()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade', 'subject' => ['targets' => ['box1']], 'score']));
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
