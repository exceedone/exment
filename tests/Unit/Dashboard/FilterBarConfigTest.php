<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomTable;

class FilterBarConfigTest extends DashboardUnitTestCase
{
    public function testNullWithoutUsableConfig()
    {
        $this->assertNull(FilterBarConfig::fromArray(null));
        $this->assertNull(FilterBarConfig::fromArray([]));
        $this->assertNull(FilterBarConfig::fromArray(['source_table' => 't']));
        $this->assertNull(FilterBarConfig::fromArray(['source_table' => '', 'dims' => [['column' => 'a']]]));
        $this->assertNull(FilterBarConfig::fromArray(['source_table' => 't', 'dims' => [['column' => 'bad-name']]]));
        $this->assertNull(FilterBarConfig::fromDashboard($this->makeDashboard(null)));
        $this->assertNull(FilterBarConfig::fromDashboard(null));
    }

    public function testDimsAreNormalized()
    {
        $config = FilterBarConfig::fromDashboard($this->makeDashboard($this->bar([
            'grade' => ['label' => ' 学年 ', 'targets' => ['box1', '', 7, 'box2']],
            'subject',
            'grade' => ['label' => 'dup'],
            'x-y',
        ])));
        $this->assertSame('fake_table', $config->sourceTable());
        $this->assertSame(['grade', 'subject'], array_column($config->dims(), 'column'));
        $this->assertSame('dup', $config->label('grade'), 'the last row with the same column wins via array key overwrite in bar(); config keeps one per column');
        $this->assertSame('subject', $config->label('subject'), 'label defaults to the column name');
        $this->assertSame('unknown', $config->label('unknown'));
        $this->assertNull($config->dim('unknown'));
        $this->assertSame(FilterBarConfig::DEFAULT_MAX_OPTIONS, $config->maxOptions());
    }

    public function testTargetsFilteringAndMaxOptions()
    {
        $config = FilterBarConfig::fromArray([
            'source_table' => 't',
            'max_options' => 20,
            'dims' => [['column' => 'grade', 'targets' => ['box1', '', 7, 'box2']]],
        ]);
        $this->assertSame(['box1', 'box2'], $config->dim('grade')['targets']);
        $this->assertSame(20, $config->maxOptions());
        $this->assertSame(FilterBarConfig::DEFAULT_MAX_OPTIONS, FilterBarConfig::fromArray(['source_table' => 't', 'max_options' => -1, 'dims' => [['column' => 'a']]])->maxOptions());
    }

    public function testFixedScope()
    {
        $config = FilterBarConfig::fromArray([
            'source_table' => 't',
            'dims' => [['column' => 'class']],
            'scope' => ['school' => '17', 'grade' => ['1', '2'], 'bad-name' => '1', 'empty' => ''],
        ]);
        $this->assertSame(['school' => ['in' => ['17']], 'grade' => ['in' => ['1', '2']]], $config->scope());
        $this->assertSame([], FilterBarConfig::fromArray(['source_table' => 't', 'dims' => [['column' => 'a']]])->scope());
    }

    public function testAppliesToHonoursTargeting()
    {
        $config = FilterBarConfig::fromDashboard($this->makeDashboard($this->bar([
            'grade',
            'subject' => ['targets' => ['box1']],
        ])));
        $box1 = $this->makeBox('box1', []);
        $box2 = $this->makeBox('box2', []);

        $this->assertTrue($config->appliesTo('grade', $box1));
        $this->assertTrue($config->appliesTo('grade', $box2));
        $this->assertTrue($config->appliesTo('grade', null));
        $this->assertTrue($config->appliesTo('subject', $box1));
        $this->assertFalse($config->appliesTo('subject', $box2));
        $this->assertTrue($config->appliesTo('subject', null), 'no box = dashboard-wide meaning');
        $this->assertFalse($config->appliesTo('unknown', $box1), 'a column that is not an item never applies');
    }

    public function testDimsForHonoursColumnsAndTargeting()
    {
        $config = FilterBarConfig::fromDashboard($this->makeDashboard($this->bar([
            'grade',
            'subject' => ['targets' => ['box1']],
            'semester',
        ])));
        // the box table carries grade and subject, but not semester
        $table = new FakeCustomTable([new FakeCustomColumn('grade'), new FakeCustomColumn('subject')]);

        $this->assertSame(['grade', 'subject'], $config->dimsFor($table, $this->makeBox('box1', [])));
        $this->assertSame(['grade'], $config->dimsFor($table, $this->makeBox('box2', [])), 'subject targets box1 only');
        $this->assertSame([], $config->dimsFor(null, $this->makeBox('box1', [])), 'no table = never narrowed');
    }
}
