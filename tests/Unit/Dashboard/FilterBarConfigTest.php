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

    public function testDefaultQuery()
    {
        $config = FilterBarConfig::fromDashboard($this->makeDashboard($this->bar([
            'grade' => ['default' => ' 1 '],
            'subject' => ['default' => '国語, 算数 ,'],
            'score' => ['default' => '60~90', 'style' => 'range'],
            'semester' => ['default' => ''],
            'gone' => ['default' => '9'],
            'class',
        ])));
        $table = new FakeCustomTable([
            new FakeCustomColumn('grade'),
            new FakeCustomColumn('subject'),
            new FakeCustomColumn('score', 'integer'),
            new FakeCustomColumn('semester'),
            new FakeCustomColumn('class'),
        ]);

        $this->assertSame([
            'df_grade' => '1',
            'df_subject' => ['国語', '算数'],
            'df_score' => ['from' => '60', 'to' => '90'],
        ], $config->defaultQuery($table), 'trimmed; empty default and unknown column skipped; no default = no param');
        $this->assertSame([], $config->defaultQuery(null));
    }

    public function testDefaultQueryRangeBounds()
    {
        $table = new FakeCustomTable([new FakeCustomColumn('score', 'integer')]);
        $only = function ($default, $style = 'range') use ($table) {
            return FilterBarConfig::fromArray(['source_table' => 't', 'dims' => [['column' => 'score', 'default' => $default, 'style' => $style]]])
                ->defaultQuery($table);
        };
        $this->assertSame(['df_score' => ['from' => '60']], $only('60~'));
        $this->assertSame(['df_score' => ['to' => '90']], $only('~90'));
        $this->assertSame([], $only('~'), 'both bounds empty = no param');

        // left to itself a number is a list, so its default names values, not bounds
        $this->assertSame(['df_score' => '90'], $only('90', null));
        $this->assertSame(['df_score' => ['60', '90']], $only('60, 90', null));
        // ... unless it is written as a range: a long number list shows from / to on the bar,
        // and a typed range keeps its inputs on any number / date item
        $this->assertSame(['df_score' => ['from' => '60', 'to' => '90']], $only('60~90', null));
        $this->assertSame(['df_score' => ['from' => '60']], $only('60~', null));

        // a text column never compares: its "~" is just a character of one value
        $text = new FakeCustomTable([new FakeCustomColumn('memo', 'text')]);
        $this->assertSame(['df_memo' => 'a~b'], FilterBarConfig::fromArray(['source_table' => 't', 'dims' => [['column' => 'memo', 'default' => 'a~b']]])->defaultQuery($text));
    }

    public function testSelectionKeepsOnlyTheBarsItems()
    {
        $config = FilterBarConfig::fromArray(['source_table' => 't', 'dims' => [['column' => 'grade'], ['column' => 'subject'], ['column' => 'score'], ['column' => 'class']]]);

        $this->assertSame([
            'df_grade' => '1',
            'df_subject' => ['国語', '算数'],
            'df_score' => ['from' => '60'],
        ], $config->selection([
            'dashboard' => 'abc',
            'df_score' => ['from' => ' 60 ', 'to' => ''],
            'df_gone' => '9',
            'df_grade' => [' 1 '],
            'df_subject' => ['国語', ['nested'], '算数', '国語'],
            'df_class' => '',
        ]), "bar order; items no longer on the bar, empty values and junk dropped; one value = a plain param");
        $this->assertSame([], $config->selection([]));
    }

    public function testQueryStringUsesTheBarsOwnUrlFormat()
    {
        $this->assertSame(
            'dashboard=abc&df_grade=1&df_subject%5B%5D=%E5%9B%BD%E8%AA%9E&df_subject%5B%5D=a%20b&df_score%5Bfrom%5D=60&dfr=1',
            FilterBarConfig::queryString([
                'dashboard' => 'abc',
                'df_grade' => '1',
                'df_subject' => ['国語', 'a b', ['nested']],
                'df_score' => ['from' => '60'],
                'dfr' => 1,
            ]),
            'list values as df_col[] (not df_col[0]) like dashboard.js writes them; a nested value is skipped'
        );
        $this->assertSame('', FilterBarConfig::queryString([]));
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
