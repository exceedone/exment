<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\GanttGrid;

/**
 * The gantt can group its rows by the parent record (ISS00244): the issues of several projects in
 * one chart, a project per group. And on a phone the rows read as a list by the week they are due
 * in, with a button between the list and the chart (L3).
 */
class GanttParentGroupTest extends UnitTestBase
{
    public function testGroupsSortDatedFirstThenByName(): void
    {
        $groups = GanttGrid::sortGroups([
            ['key' => '3', 'label' => 'B', 'due' => null],
            ['key' => '1', 'label' => 'A', 'due' => '2026-12-20'],
            ['key' => '2', 'label' => 'C', 'due' => '2026-12-01'],
            ['key' => '4', 'label' => 'A', 'due' => null],
        ], false);
        $this->assertSame(['2', '1', '4', '3'], array_column($groups, 'key'));
    }

    public function testTheGroupWithoutParentComesLast(): void
    {
        $groups = GanttGrid::sortGroups([['key' => '1', 'label' => 'A', 'due' => null]], true);
        $this->assertSame(['1', ''], array_column($groups, 'key'));
    }

    public function testGroupByParentIsAChoiceAndReadsTheParent(): void
    {
        $this->assertSame('parent_id', GanttGrid::GROUP_PARENT);
        $grid = (string) file_get_contents(exment_package_path('src/DataItems/Grid/GanttGrid.php'));
        $this->assertStringContainsString('$options[self::GROUP_PARENT] = exmtrans(\'custom_view.gantt_group_parent\'', $grid);
        $this->assertStringContainsString('$group_key = is_nullorempty($record->parent_id) ? \'\' : strval($record->parent_id);', $grid);
        // the parents in one query
        $this->assertStringContainsString("->whereIn('id', \$ids)->get()->keyBy('id')", $grid);
    }

    public function testAPhoneReadsAList(): void
    {
        $script = (string) file_get_contents(exment_package_path('resources/views/widgets/gantt/script.blade.php'));
        $this->assertStringContainsString('function listHtml()', $script);
        $this->assertStringContainsString("setMode(root.clientWidth > 0 && root.clientWidth < 600);", $script);
        $style = (string) file_get_contents(exment_package_path('resources/views/widgets/gantt/style.blade.php'));
        $this->assertStringContainsString('.exment-gantt.gt-as-list .gt-scroll{display:none;}', $style);
        foreach (['ja', 'en', 'vi', 'zh_CN', 'ko', 'de', 'es'] as $locale) {
            $lang = (string) file_get_contents(exment_package_path('resources/lang/' . $locale . '/exment.php'));
            foreach (['gantt_group_parent', 'gantt_view_list', 'gantt_view_chart', 'gantt_week'] as $key) {
                $this->assertStringContainsString("'" . $key . "' => ", $lang, $locale . ' ' . $key);
            }
        }
    }
}
