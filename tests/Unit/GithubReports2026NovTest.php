<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\SystemItem;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Exceedone\Exment\DataItems\Grid\GanttGrid;
use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\FilterBarForm;
use Exceedone\Exment\Services\Line\LineInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The bug reports of GitHub of 2026-11-25 (#2156, #2160, #2161, #2162, #2164, #2165, #2166,
 * #2167, #2168): one test each.
 */
class GithubReports2026NovTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    private function read(string $relative): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $this->assertNotFalse($content, $relative);
        return strval($content);
    }

    /**
     * #2156: the "?" of the LINE link page and of the flow designer opens their pages of the manual.
     */
    public function testHelpUrlsOfTheLineLinkAndTheFlowDesigner(): void
    {
        $uris = array_column(Define::HELP_URLS, 'uri');
        $helps = array_column(Define::HELP_URLS, 'help_uri', 'uri');
        $this->assertSame('line', array_get($helps, 'line/link'));
        $this->assertSame('workflow_design', array_get($helps, 'workflow/design/create'));
        $this->assertSame('workflow_design', array_get($helps, 'workflow/*/design'));
        // the first entry that matches wins: the designer before "workflow"
        $workflow = array_search('workflow', $uris, true);
        $this->assertLessThan($workflow, array_search('workflow/design/create', $uris, true));
        $this->assertLessThan($workflow, array_search('workflow/*/design', $uris, true));

        // "*" is one part of the path, in the script and in its build
        foreach (['src/Web/ts/common.ts', 'public/vendor/exment/js/common.js'] as $file) {
            $this->assertStringContainsString("uri.indexOf('*') !== -1", $this->read($file), $file);
            $this->assertStringContainsString("'[^/]+'", $this->read($file), $file);
        }
    }

    /**
     * #2160: the columns of the safety check and LINE tables are system columns (no delete
     * button), the ones a user added are not.
     */
    public function testTheColumnsOfTheFeatureAreSystemColumns(): void
    {
        $table = $this->_createSimpleTable('gh2160_owned');
        CustomColumn::create([
            'custom_table_id' => $table->id,
            'column_name' => 'added_by_user',
            'column_view_name' => 'added',
            'column_type' => ColumnType::TEXT,
        ]);
        System::clearCache();

        LineInstaller::markSystemColumns($table, ['name']);
        System::clearCache();

        $this->assertTrue(boolval(CustomColumn::getEloquent('name', $table)->disabled_delete));
        $this->assertFalse(boolval(CustomColumn::getEloquent('added_by_user', $table)->disabled_delete));

        // the migration: only the tables of the features (system tables), so nothing here
        LineInstaller::markOwnedColumns();
        System::clearCache();
        $this->assertFalse(boolval(CustomColumn::getEloquent('added_by_user', $table)->disabled_delete));

        $this->assertFileExists(dirname(__DIR__, 2) . '/database/migrations/2026_12_03_000001_line_safety_check_columns_are_system.php');
    }

    /**
     * #2161: the text of the new flow designer does not name a "step 3" (on the screen step 3 is
     * the notification).
     */
    public function testTheTextOfTheNewFlowDesignerHasNoStepNumber(): void
    {
        foreach (['ja' => 'ステップ3', 'en' => 'step 3', 'de' => 'Schritt 3', 'es' => 'paso 3', 'ko' => '3단계', 'vi' => 'bước 3', 'zh_CN' => '步骤 3'] as $locale => $step) {
            $text = strval(trans('exment::exment.workflow.design.new_description', [], $locale));
            $this->assertNotSame('exment::exment.workflow.design.new_description', $text, $locale);
            $this->assertStringNotContainsStringIgnoringCase($step, $text, $locale);
        }
    }

    /**
     * #2162: the "filtered" chip names the users of created_user and "YES" for the work users,
     * not their ids or "1".
     */
    public function testTheChipNamesTheUsersAndTheWorkUsers(): void
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent('custom_value_edit_all');
        $this->assertNotNull($table);

        $item = SystemItem::getItem($table, SystemColumn::CREATED_USER, null);
        $id = TestDefine::TESTDATA_USER_LOGINID_ADMIN;
        $this->assertSame(strval(getUserName($id)), $item->chipText([$id]));
        $this->assertNotSame($id, $item->chipText([$id]));
        // another system column: the chip finds it itself
        $this->assertNull(SystemItem::getItem($table, SystemColumn::ID, null)->chipText(['1']));

        $work = WorkflowItem::getItem($table, SystemColumn::WORKFLOW_WORK_USERS, null);
        $this->assertSame('YES', $work->chipText('1'));
    }

    /**
     * #2164: every value of a multiple select column is a label of the card, not only the first.
     */
    public function testAllTheValuesOfAMultipleColumnAreLabels(): void
    {
        $grid = (new \ReflectionClass(KanbanGrid::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(KanbanGrid::class, 'buildCardLabels');
        $method->setAccessible(true);

        $labels = $method->invoke($grid, null, null, ['a' => 'A', 'b' => 'B'], collect(['a', 'b']));
        $this->assertSame([['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']], $labels);
        $this->assertSame([['key' => 'a', 'label' => 'A']], $method->invoke($grid, null, null, ['a' => 'A'], 'a'));
    }

    /**
     * #2165: the deadline of a group of the Gantt chart is the date column named so, by its view
     * name ("期限") too, and a name that only has "end" inside a word is not.
     */
    public function testTheDeadlineColumnOfTheGantt(): void
    {
        $column = function (string $name, string $view) {
            return new CustomColumn(['column_name' => $name, 'column_view_name' => $view, 'column_type' => ColumnType::DATE]);
        };
        $this->assertTrue(GanttGrid::isDeadlineColumn($column('date2', '期限')));
        $this->assertTrue(GanttGrid::isDeadlineColumn($column('date3', '納期')));
        $this->assertTrue(GanttGrid::isDeadlineColumn($column('end_date', 'End')));
        $this->assertTrue(GanttGrid::isDeadlineColumn($column('due', 'Due')));
        $this->assertFalse(GanttGrid::isDeadlineColumn($column('calendar_date', 'Calendar')));
        $this->assertFalse(GanttGrid::isDeadlineColumn($column('attend_date', '出席日')));
        $this->assertFalse(GanttGrid::isDeadlineColumn($column('start_date', '開始日')));
    }

    /**
     * #2166: no file or image column in the dashboard and chart filters (their values are paths).
     */
    public function testNoFileOrImageColumnInTheFilters(): void
    {
        $this->assertFalse(FilterBarForm::isFilterable(new CustomColumn(['column_type' => ColumnType::FILE])));
        $this->assertFalse(FilterBarForm::isFilterable(new CustomColumn(['column_type' => ColumnType::IMAGE])));
        $this->assertTrue(FilterBarForm::isFilterable(new CustomColumn(['column_type' => ColumnType::SELECT])));
        $this->assertFalse(FilterBarForm::isFilterable(null));

        $this->be(\Exceedone\Exment\Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent('all_columns_table_fortest');
        $this->assertNotNull($table);
        $options = FilterBarForm::columnOptions($table);
        $this->assertNotEmpty($options);
        foreach ($table->custom_columns as $custom_column) {
            if (ColumnType::isAttachment($custom_column->column_type)) {
                $this->assertArrayNotHasKey($custom_column->column_name, $options);
            }
        }
    }

    /**
     * #2167: a slow double click (after the row began to open) does not open an editor too.
     */
    public function testASlowDoubleClickDoesNotAlsoOpenTheEditor(): void
    {
        foreach (['src/Web/ts/common.ts', 'public/vendor/exment/js/common.js'] as $file) {
            $this->assertStringContainsString('__exmRowOpenedAt = Date.now()', $this->read($file), $file);
        }
        $this->assertStringContainsString('if (window.__exmRowOpenedAt && Date.now() - window.__exmRowOpenedAt < 2000) return;', $this->read('public/vendor/exment/js/grid_tools.js'));
    }

    /**
     * #2168: an image column is not offered for grouping, nor for "filter in this page".
     */
    public function testNoGroupingOnAnImageColumn(): void
    {
        $js = $this->read('public/vendor/exment/js/grid_tools.js');
        $this->assertStringContainsString('function isImageColumn(table, col)', $js);
        $this->assertStringContainsString("cell.classList.contains('exm-coltype-image')", $js);
        $this->assertStringContainsString("!isImageColumn(closest(td, 'table.exm-grid'), fcol)", $js);
        // the class the script reads is on the cells of the list
        $this->assertStringContainsString("'exm-coltype-' . \$columnType", $this->read('src/DataItems/Grid/DefaultGrid.php'));
    }
}
