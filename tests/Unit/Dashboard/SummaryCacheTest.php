<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\SummaryCache;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardBoxFixtures;
use Exceedone\Exment\Tests\Unit\Security\SecurityRegressionTestCase;

/**
 * EXMENT_DASHBOARD_CACHE_SECONDS keeps the rows a chart of an aggregate view aggregates
 * (ISS00413): the next load of the box draws the same without aggregating. A saved row, another
 * viewer, another filter asked for aggregate again. Off (0, the default), every load aggregates.
 *
 * The cache is an array store of the test; the views and rows made here are rolled back.
 */
class SummaryCacheTest extends SecurityRegressionTestCase
{
    use DashboardBoxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'exment.dashboard_cache_seconds' => 60]);
    }

    public function test_the_next_load_draws_the_same_without_aggregating(): void
    {
        $this->asAdmin();
        $options = $this->chartOptions();

        [$first, $queries] = $this->render($this->chart($options));
        $this->assertSame(1, $this->countAggregations($queries), 'the first load aggregates');
        [$second, $queries] = $this->render($this->chart($options));
        $this->assertSame(0, $this->countAggregations($queries), 'the next load does not');
        $this->assertSame($first, $second, 'and draws the same');
    }

    public function test_off_every_load_aggregates(): void
    {
        config(['exment.dashboard_cache_seconds' => 0]);
        $this->assertFalse(SummaryCache::enabled());
        $this->asAdmin();
        $options = $this->chartOptions();

        $this->render($this->chart($options));
        [, $queries] = $this->render($this->chart($options));
        $this->assertSame(1, $this->countAggregations($queries));
    }

    public function test_a_saved_row_aggregates_again(): void
    {
        $this->asAdmin();
        $options = $this->chartOptions();
        $this->render($this->chart($options));

        /** @var \Exceedone\Exment\Model\CustomValue $row */
        $row = $this->table()->getValueModel()::query()->first();
        $row->setValue('integer', intval($row->getValue('integer')) + 1000)->save();

        [, $queries] = $this->render($this->chart($options));
        $this->assertSame(1, $this->countAggregations($queries));
    }

    public function test_another_viewer_or_filter_aggregates_again(): void
    {
        $this->asAdmin();
        $options = $this->chartOptions();
        $this->render($this->chart($options));

        // another filter asked for (the request is part of the key)
        request()->merge(['df_select' => 'foo']);
        try {
            [, $queries] = $this->render($this->chart($options));
            $this->assertSame(1, $this->countAggregations($queries), 'another filter');
        } finally {
            request()->offsetUnset('df_select');
        }

        // another viewer: never the rows aggregated for someone else
        $other = LoginUser::where('id', '<>', 1)->first();
        if ($other === null) {
            $this->markTestSkipped('needs a second LoginUser');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($other, 'admin');
        [, $queries] = $this->render($this->chart($options));
        $this->assertLessThanOrEqual(1, $this->countAggregations($queries), 'another viewer reads for themselves');
        $this->assertTrue($this->countAggregations($queries) === 1 || !$this->table()->hasPermission(), 'another viewer aggregates (or may not see the table)');
    }

    /**
     * A chart of an aggregate view of the test data, grouped by user, summing integer.
     *
     * @return array<string, mixed>
     */
    protected function chartOptions(): array
    {
        $table = $this->table();
        [$viewId, $columnId, $summaryId] = $this->aggregateView($table->id, $this->column('user')->id, $this->column('integer')->id);
        return [
            'target_table_id' => $table->id, 'target_view_id' => $viewId, 'chart_type' => 'bar',
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $columnId, 'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summaryId,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function chart(array $options): ChartItem
    {
        $box = $this->box(DashboardBoxType::CHART, $options);
        $box->suuid = 'summary_cache_test';
        return new ChartItem($box);
    }

    /**
     * The aggregating queries of the table (the GROUP BY of the view).
     *
     * @param string[] $queries
     */
    protected function countAggregations(array $queries): int
    {
        $name = preg_quote(getDBTableName($this->table()), '/');
        return count((array) preg_grep('/from [`"\[]?' . $name . '[`"\]]?.* group by /is', $queries));
    }
}
