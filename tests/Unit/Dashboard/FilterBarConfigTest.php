<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Services\Dashboard\FilterBarConfig;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

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
}
