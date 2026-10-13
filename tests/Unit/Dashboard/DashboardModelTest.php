<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

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
