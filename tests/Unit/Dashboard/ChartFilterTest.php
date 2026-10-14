<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\ChartFilter;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomTable;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeQuery;

class ChartFilterTest extends DashboardUnitTestCase
{
    private function table(): FakeCustomTable
    {
        $semester = new FakeCustomColumn('semester', 'select_valtext', true, '学期');
        $semester->options['select_item_valtext'] = "1,1学期\r\n2,2学期";
        return new FakeCustomTable([
            new FakeCustomColumn('class', 'select_table', true, 'クラス'),
            $semester,
            new FakeCustomColumn('score', 'integer', true, '点数'),
        ]);
    }

    public function testDeclaredColumnsIntersectedWithTable(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['class', 'missing', 'bad-name', 'class', 'score']]);
        $filter = ChartFilter::of($box, $this->table(), []);
        $this->assertTrue($filter->isConfigured());
        $this->assertSame(['class', 'score'], array_keys($filter->columns()));
        $this->assertSame([], $filter->values(), 'nothing selected');
    }

    public function testNothingWithoutDeclarationOrTable(): void
    {
        $this->assertFalse(ChartFilter::of($this->makeBox('b1', []), $this->table(), ['bf_class' => '1'])->isConfigured());
        $this->assertFalse(ChartFilter::of($this->makeBox('b1', ['chart_filters' => ['class']]), null, ['bf_class' => '1'])->isConfigured());
        $this->assertFalse(ChartFilter::of(null, $this->table(), [])->isConfigured());
    }

    public function testOnlyDeclaredParamsCount(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['class']]);
        $filter = ChartFilter::of($box, $this->table(), ['bf_class' => ['2', '3'], 'bf_semester' => '1', 'df_class' => '9']);
        $this->assertSame(['class' => ['in' => ['2', '3']]], $filter->values());
    }

    public function testApplyToWithExcept(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['class', 'semester', 'score']]);
        $filter = ChartFilter::of($box, $this->table(), ['bf_class' => '2', 'bf_semester' => ['1', '2'], 'bf_score' => ['from' => '80', 'to' => '100']]);

        $q = new FakeQuery();
        $filter->applyTo($q);
        $score = $this->wrap('exm__fake.column_score');
        $this->assertSame([
            'exm__fake.column_class = "2"',
            'exm__fake.column_semester IN ["1","2"]',
            "{$score} IS NOT NULL AND {$score} <> ''",
            "CAST({$score} AS DECIMAL(22,8)) >= ? [\"80\"]",
            "CAST({$score} AS DECIMAL(22,8)) <= ? [\"100\"]",
        ], $q->sql());

        $q = new FakeQuery();
        $filter->applyTo($q, ['semester']);
        $this->assertCount(4, $q->sql(), 'the excluded column is skipped (its own option list)');
    }

    public function testCaptionsResolveValtextLabels(): void
    {
        $box = $this->makeBox('b1', ['chart_filters' => ['semester', 'score']]);
        $filter = ChartFilter::of($box, $this->table(), ['bf_semester' => ['2', '9'], 'bf_score' => ['from' => '80']]);
        $this->assertSame([
            ['label' => '学期', 'value' => '2学期, 9'],
            ['label' => '点数', 'value' => '80 –'],
        ], $filter->captions());
    }
}
