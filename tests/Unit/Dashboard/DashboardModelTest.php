<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

class DashboardModelTest extends DashboardUnitTestCase
{
    public function testRowSettingMergesIntoOptions()
    {
        $dashboard = $this->makeDashboard($this->bar(['grade']), ['row1' => 1, 'row2' => 2, 'keep_me' => true]);
        $dashboard->row_setting = ['row1' => 3, 'row2' => 0];
        $this->assertSame(3, $dashboard->getOption('row1'));
        $this->assertSame(0, $dashboard->getOption('row2'));
        $this->assertTrue($dashboard->getOption('keep_me'), 'keys the form does not manage survive');
        $this->assertSame('fake_table', $dashboard->getOption('filter_bar.source_table'));
    }

    public function testFilterBarTableAndDims()
    {
        $dashboard = $this->makeDashboard(null);
        $dashboard->filter_bar_table = 'f_score';
        $dashboard->filter_bar_dims = [
            ['column' => 'grade', 'label' => ' 学年 ', 'targets' => ['b1', '', 'b2'], 'default' => ' 1 '],
            ['column' => '', 'label' => 'blank row'],
            ['column' => 'subject', 'label' => '', 'targets' => [], 'default' => '  '],
        ];
        $this->assertSame('f_score', $dashboard->filter_bar_table);
        $this->assertSame([
            ['column' => 'grade', 'label' => '学年', 'targets' => ['b1', 'b2'], 'default' => '1'],
            ['column' => 'subject', 'label' => 'subject'],
        ], $dashboard->getOption('filter_bar.dims'), 'default trimmed; blank default = key absent');
        $this->assertSame([
            ['column' => 'grade', 'label' => '学年', 'targets' => ['b1', 'b2'], 'default' => '1'],
            ['column' => 'subject', 'label' => 'subject', 'targets' => [], 'default' => ''],
        ], $dashboard->filter_bar_dims);

        // "style" is not a form field: a re-save reads it back off the stored item of the same column
        $styled = $this->makeDashboard(['source_table' => 'f_score', 'dims' => [
            ['column' => 'score', 'label' => '点数', 'style' => 'range'],
            ['column' => 'grade', 'label' => '学年', 'style' => 'nonsense'],
        ]]);
        $styled->filter_bar_dims = [['column' => 'grade', 'label' => '学年'], ['column' => 'score', 'label' => '点数']];
        $this->assertSame([
            ['column' => 'grade', 'label' => '学年'],
            ['column' => 'score', 'label' => '点数', 'style' => 'range'],
        ], $styled->getOption('filter_bar.dims'), 'style survives the save; an unusable one is dropped');

        $dashboard->filter_bar_dims = [];
        $this->assertArrayNotHasKey('dims', $dashboard->getOption('filter_bar'));
        $dashboard->filter_bar_table = '';
        $this->assertSame([], $dashboard->getOption('filter_bar'));
    }

    /** Unsaved chart box at {row}-{column}, no dashboard relation (what a template export walks). */
    private function boxAt(string $suuid, int $row, int $column): DashboardBox
    {
        $box = $this->makeBox($suuid, []);
        $box->row_no = $row;
        $box->column_no = $column;
        $box->unsetRelation('dashboard');
        return $box;
    }

    /** Unsaved Dashboard whose save() is counted instead of reaching the database. */
    private function countingDashboard(?array $filterBar): Dashboard
    {
        $dashboard = new class () extends Dashboard {
            /** @var int */
            public $saves = 0;

            public function save(array $options = [])
            {
                $this->saves++;
                return true;
            }
        };
        $dashboard->dashboard_name = 'unit_dashboard';
        $dashboard->options = $filterBar === null ? [] : ['filter_bar' => $filterBar];
        return $dashboard;
    }

    public function testRenameFilterBarTargets()
    {
        $dims = [
            ['column' => 'grade', 'targets' => ['old-a', 'old-b']],
            ['column' => 'subject', 'targets' => ['old-b', 7, null]],
            ['column' => 'class'],
            'junk',
        ];
        $this->assertSame([
            ['column' => 'grade', 'targets' => ['1-1', '2-1']],
            ['column' => 'subject', 'targets' => ['2-1', 7, null]],
            ['column' => 'class'],
            'junk',
        ], Dashboard::renameFilterBarTargets($dims, ['old-a' => '1-1', 'old-b' => '2-1']), 'known targets renamed; anything else untouched');
        $this->assertSame($dims, Dashboard::renameFilterBarTargets($dims, ['9-9' => 'x']), 'no match = the identical array');
        $this->assertSame([], Dashboard::renameFilterBarTargets([], ['1-1' => 'x']));
    }

    public function testTemplateExportNamesFilterBarTargetsByPosition()
    {
        // a box does not keep its suuid through an import: the export names a target by "{row}-{column}"
        $dashboard = $this->makeDashboard(['source_table' => 'fake_table', 'dims' => [
            ['column' => 'grade', 'label' => '学年', 'targets' => ['box-a', 'box-b']],
            ['column' => 'subject', 'targets' => ['box-b', 'gone']],
            ['column' => 'class'],
        ]]);
        $dashboard->setRelation('dashboard_boxes', collect([$this->boxAt('box-a', 1, 1), $this->boxAt('box-b', 2, 1)]));
        $this->assertSame('2-1', $dashboard->dashboard_boxes[1]->positionKey());

        $dims = array_get($dashboard->getTemplateExportItems(), 'options.filter_bar.dims');
        $this->assertSame(['1-1', '2-1'], $dims[0]['targets']);
        $this->assertSame('学年', $dims[0]['label'], 'the rest of the item travels as it is');
        $this->assertSame(['2-1', 'gone'], $dims[1]['targets'], 'a target no box of the dashboard carries is kept');
        $this->assertArrayNotHasKey('targets', $dims[2]);
        $this->assertSame(['box-a', 'box-b'], $dashboard->getOption('filter_bar.dims.0.targets'), 'the stored option is untouched');
        $this->assertArrayNotHasKey('options', $dashboard->getTemplateExportItems(true), 'the language export has no options to rename');
    }

    public function testImportedBoxesTakeTheirPositionsPlaceInTheFilterBar()
    {
        $bar = ['source_table' => 'fake_table', 'dims' => [
            ['column' => 'grade', 'label' => '学年', 'targets' => ['1-1', '2-1', '3-3'], 'default' => '1'],
            ['column' => 'subject', 'targets' => ['2-1']],
        ]];
        $dashboard = $this->countingDashboard($bar);
        $dashboard->setRelation('dashboard_boxes', collect([$this->boxAt('box-a', 1, 1), $this->boxAt('box-b', 2, 1), $this->boxAt('box-x', 4, 4)]));

        // every position takes the suuid of the box standing there, in one save; a position no
        // box fills is kept, and the rest of the bar survives the write in place
        $dashboard->resolveFilterBarTargets();
        $this->assertSame(['source_table' => 'fake_table', 'dims' => [
            ['column' => 'grade', 'label' => '学年', 'targets' => ['box-a', 'box-b', '3-3'], 'default' => '1'],
            ['column' => 'subject', 'targets' => ['box-b']],
        ]], $dashboard->getOption('filter_bar'));
        $this->assertSame(['filter_bar'], array_keys($dashboard->options), 'no stray "filter_bar.dims" key beside the bar');
        $this->assertSame(1, $dashboard->saves);

        // a later pass (an import landing on an existing dashboard) finds nothing to rename: no write
        $dashboard->resolveFilterBarTargets();
        $this->assertSame(1, $dashboard->saves);

        // no bar, or a bar without items: nothing to do
        foreach ([null, ['source_table' => 'fake_table'], ['source_table' => 'fake_table', 'dims' => 'junk']] as $noItems) {
            $dashboard = $this->countingDashboard($noItems);
            $dashboard->setRelation('dashboard_boxes', collect([$this->boxAt('box-a', 1, 1)]));
            $dashboard->resolveFilterBarTargets();
            $this->assertSame(0, $dashboard->saves);
            $this->assertSame($noItems, $dashboard->getOption('filter_bar'));
        }
    }

    public function testImportTemplateEndsWithThePositionPass()
    {
        // Dashboard::importTemplate: the trait's import (the dashboard, then its boxes), then
        // the pass over the bar's position targets on what it returned
        $dashboard = $this->countingDashboard(['source_table' => 'fake_table', 'dims' => [['column' => 'grade', 'targets' => ['2-1']]]]);
        $dashboard->setRelation('dashboard_boxes', collect([$this->boxAt('box-b', 2, 1)]));
        $importing = new class () extends Dashboard {
            /** @var Dashboard|null what the trait's import returns */
            public static $imported;

            protected static function templateImport($array, $is_update, $options = [])
            {
                return static::$imported;
            }
        };
        $importing::$imported = $dashboard;
        $this->assertSame($dashboard, $importing::importTemplate(['dashboard_name' => 'unit_dashboard'], false));
        $this->assertSame(['box-b'], $dashboard->getOption('filter_bar.dims.0.targets'));
        $this->assertSame(1, $dashboard->saves);

        $importing::$imported = null;
        $this->assertNull($importing::importTemplate([], false), 'an import the trait refused: nothing to resolve');
    }

    public function testNormalizeDropsHalfConfiguredBarAndDuplicateColumns()
    {
        $normalize = new \ReflectionMethod(\Exceedone\Exment\Model\Dashboard::class, 'normalizeFilterBarOption');
        $normalize->setAccessible(true);

        $dashboard = $this->makeDashboard(['source_table' => 't', 'dims' => [['column' => 'a'], ['column' => 'b'], ['column' => 'a', 'label' => 'dup'], ['column' => '']]]);
        $normalize->invoke($dashboard);
        $this->assertSame([['column' => 'a'], ['column' => 'b']], $dashboard->getOption('filter_bar.dims'));

        $dashboard = $this->makeDashboard(['source_table' => '', 'dims' => [['column' => 'a']]], ['row1' => 1]);
        $normalize->invoke($dashboard);
        $this->assertArrayNotHasKey('filter_bar', $dashboard->options);
        $this->assertSame(1, $dashboard->getOption('row1'));

        $dashboard = $this->makeDashboard(['source_table' => 't', 'dims' => []]);
        $normalize->invoke($dashboard);
        $this->assertArrayNotHasKey('filter_bar', $dashboard->options);

        $dashboard = $this->makeDashboard(null);
        $normalize->invoke($dashboard);
        $this->assertArrayNotHasKey('filter_bar', $dashboard->options);
    }
}
