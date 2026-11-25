<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\DashboardBoxItems\ListItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardBoxFixtures;
use Exceedone\Exment\Tests\Unit\Security\SecurityRegressionTestCase;
use Illuminate\Support\Facades\DB;

/**
 * A dashboard box reads together the records its rows point at (ISS00413): a list box the
 * select_table / user / organization targets, the records reached through a pivot column and
 * the files of its page, a chart of an aggregate view the targets of its groups. They were
 * read one query per row and column (a list of 20 rows of 12 select_table columns: 119
 * queries, now 28). The box draws the same as without it.
 *
 * Uses the test data (all_columns_table_fortest); the views made here are rolled back.
 */
class DashboardPrefetchTest extends SecurityRegressionTestCase
{
    use DashboardBoxFixtures;

    public function test_a_list_box_reads_the_targets_of_its_page_together(): void
    {
        $this->asAdmin();
        $table = $this->table();
        $columns = ['select_table', 'select_table_2', 'user', 'organization', 'image', 'file'];
        $viewColumns = array_map(function ($name) use ($table) {
            return [$table->id, $this->column($name)->id, []];
        }, $columns);
        // a column of the record the select_table column points at
        $selectTable = $this->column('select_table');
        $target = $selectTable->select_target_table;
        $targetColumn = $target->custom_columns->first();
        $viewColumns[] = [$target->id, $targetColumn->id, ['view_pivot_table_id' => $table->id, 'view_pivot_column_id' => $selectTable->id]];
        $viewId = $this->makeView(ViewKindType::DEFAULT, $viewColumns);

        $options = ['target_table_id' => $table->id, 'target_view_id' => $viewId, 'pager_count' => 20];
        [$html, $queries] = $this->render(new ListItem($this->box(DashboardBoxType::LIST, $options)));
        [$plain, $plainQueries] = $this->render(new class ($this->box(DashboardBoxType::LIST, $options)) extends ListItem {
            protected function prefetchReferences($rows, $view_columns)
            {
            }
        });

        $this->assertSame($plain, $html, 'the box draws the same');
        $this->assertLessThan(count($plainQueries), count($queries), 'fewer queries than one per row and column');
        foreach (['user', 'organization', 'select_table', 'select_table_2'] as $name) {
            $target = $this->column($name)->select_target_table;
            $this->assertLessThanOrEqual(1, $this->countById($queries, $target), "the {$name} targets: one query for the page");
        }
        $this->assertLessThanOrEqual(1, count((array) preg_grep('/from [`"\[]?files/', $queries)), 'the files of the page: one query');
    }

    public function test_a_chart_of_an_aggregate_view_reads_the_targets_of_its_groups_together(): void
    {
        $this->asAdmin();
        $table = $this->table();
        $integer = $this->column('integer');
        foreach (['user', 'select_table'] as $name) {
            $group = $this->column($name);
            [$viewId, $columnId, $summaryId] = $this->aggregateView($table->id, $group->id, $integer->id);
            $options = [
                'target_table_id' => $table->id, 'target_view_id' => $viewId, 'chart_type' => 'bar',
                'chart_axisx' => ViewKindType::DEFAULT . '_' . $columnId, 'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summaryId,
            ];
            [$html, $queries] = $this->render(new ChartItem($this->box(DashboardBoxType::CHART, $options)));
            [$plain, $plainQueries] = $this->render(new class ($this->box(DashboardBoxType::CHART, $options)) extends ChartItem {
                protected function prefetchSummaryTargets($rows, $items)
                {
                }
            });

            $this->assertSame($plain, $html, "the chart grouped by {$name} draws the same");
            $this->assertLessThan(count($plainQueries), count($queries), "grouped by {$name}: fewer queries than one per group");
            $this->assertLessThanOrEqual(1, $this->countById($queries, $group->select_target_table), "grouped by {$name}: one query for the labels");
        }
    }

    /**
     * File::getData() answers a prefetched path from the request, and asks the database again
     * for a file saved or deleted since; a path not in the database is answered too (null).
     */
    public function test_a_prefetched_file_is_forgotten_when_saved_or_deleted(): void
    {
        System::clearRequestSession();
        $file = new ExmentFile(['file_type' => 1, 'local_dirname' => 'prefetch_test', 'local_filename' => uniqid() . '.txt', 'filename' => 'a.txt']);
        $file->save();
        $this->assertNotEmpty($file->uuid, 'the file still gets its uuid');
        $path = $file->local_dirname . '/' . $file->local_filename;
        $missing = 'prefetch_test/' . uniqid() . '.txt';

        ExmentFile::prefetch([$path, [$missing], null, 'not-a-path']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            $this->assertSame($file->uuid, ExmentFile::getData($path)->uuid);
            $this->assertNull(ExmentFile::getData($missing));
            $this->assertCount(0, $queries, 'answered from the request');

            $file->filename = 'b.txt';
            $file->save();
            $this->assertSame('b.txt', ExmentFile::getData($path)->filename, 'a saved file is read again');

            ExmentFile::prefetch([$path]);
            $file->delete();
            $this->assertNull(ExmentFile::getData($path), 'a deleted file is not answered');
        } finally {
            app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);
        }
    }
}
