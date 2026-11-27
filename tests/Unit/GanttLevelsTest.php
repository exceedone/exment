<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * The gantt draws every level of a parent column (an issue, its child, the child of that one...),
 * indented, and a parent folds what hangs under it. Before, only one level was placed under its
 * parent and the deeper ones went flat to the end (ExmentPM nests issues five levels).
 *
 * The drawing is javascript the test runner does not execute; this reads the script for the parts
 * that do it. The order itself was checked with node (1 > 2 > 3 > 4 > 5 under one another, by
 * start date, a loop of old data at the end).
 */
class GanttLevelsTest extends UnitTestBase
{
    protected function script(): string
    {
        return (string) file_get_contents(exment_package_path('resources/views/widgets/gantt/script.blade.php'));
    }

    public function testEveryLevelIsPlacedUnderItsParent(): void
    {
        $script = $this->script();
        $this->assertStringContainsString('function orderRows(list)', $script);
        // a walk through every level, with the depth and the ancestors of each row
        $this->assertStringContainsString('var walk = function (r, depth, anc)', $script);
        $this->assertStringContainsString('walk(c, depth + 1, anc.concat([String(r.id)]))', $script);
        $this->assertStringNotContainsString('grandchildren...) keeps flat order at the end', $script);
        // a loop never reaches a root: it is drawn, flat, once
        $this->assertStringContainsString('if (seen[String(r.id)]) { return; }', $script);
    }

    public function testADeeperLevelIsIndentedAndAParentFolds(): void
    {
        $script = $this->script();
        $this->assertStringContainsString("' style=\"padding-left:' + (depth * 12) + 'px\"'", $script);
        $this->assertStringContainsString('class="gt-tgl fa fa-caret-down" data-tid="', $script);
        $this->assertStringContainsString("el.classList.toggle('gt-tree-hidden'", $script);
        $this->assertStringContainsString('data-anc="', $script);

        $style = (string) file_get_contents(exment_package_path('resources/views/widgets/gantt/style.blade.php'));
        $this->assertStringContainsString('.exment-gantt .gt-row.gt-tree-hidden{display:none;}', $style);
    }
}
