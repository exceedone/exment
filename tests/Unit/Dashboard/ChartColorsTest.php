<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Services\Dashboard\ChartColors;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;

/**
 * Colors painted on a chart by right-clicking it (box option chart_colors).
 */
class ChartColorsTest extends DashboardUnitTestCase
{
    private const PALETTE = ['#111111', '#222222', '#333333'];

    public function testFromOptionKeepsOnlyHexColorsOfKnownKinds()
    {
        $colors = ChartColors::fromOption([
            'points' => ['国語' => '#FF0000', '算数' => 'red', '理科' => '#ff00001', '社会' => ' #0f0 ', 1 => '#00f'],
            'series' => ['' => '#4472C4', '1年' => ['#000']],
            'other' => ['x' => '#fff'],
        ]);
        $this->assertSame([
            'points' => ['国語' => '#ff0000', '社会' => '#0f0', '1' => '#00f'],
            'series' => ['' => '#4472c4'],
        ], $colors->toArray(), 'lower-cased; names and non-hex values dropped');
        $this->assertTrue(ChartColors::fromOption(null)->isEmpty());
        $this->assertTrue(ChartColors::fromOption('#fff')->isEmpty());
        $this->assertSame('#00f', $colors->get(ChartColors::POINT, 1), 'a numeric category text finds its color');
    }

    public function testColorsForKeepsThePaletteOfWhatIsNotPainted()
    {
        $colors = ChartColors::fromOption(['points' => ['b' => '#ff0000']]);
        $this->assertSame(['#111111', '#ff0000', '#333333', '#111111'], $colors->colorsFor(ChartColors::POINT, ['a', 'b', 'c', 'd'], self::PALETTE), 'palette cycled by position, the painted one wins');
        $this->assertSame(['#ff0000', '#222222'], $colors->colorsFor(ChartColors::POINT, ['b', 'a'], self::PALETTE), 'the color follows the category, not its slot');
        $this->assertSame(['#111111', '#222222'], $colors->colorsFor(ChartColors::SERIES, ['b', 'a'], self::PALETTE), 'series colors are their own');
        $this->assertSame([], $colors->colorsFor(ChartColors::POINT, [], self::PALETTE));
    }

    public function testWithSetsAndClearsOneColor()
    {
        $colors = ChartColors::fromOption(['points' => ['a' => '#111']]);
        $this->assertSame(['points' => ['a' => '#111', 'b' => '#abcdef']], $colors->with(ChartColors::POINT, 'b', '#ABCDEF'));
        $this->assertSame(['points' => ['a' => '#111'], 'series' => ['' => '#123456']], $colors->with(ChartColors::SERIES, '', '#123456'));
        $this->assertSame([], $colors->with(ChartColors::POINT, 'a', ''), 'back to the palette; an empty kind disappears');
        $this->assertSame([], $colors->with(ChartColors::POINT, 'a', 'javascript:alert(1)'), 'not a color = back to the palette');
        $this->assertSame(['points' => ['a' => '#111']], $colors->toArray(), 'with() leaves the object untouched');
    }

    public function testWhichTypesArePainted()
    {
        foreach (['bar', 'line', 'pie', 'hbar', 'area', 'doughnut', 'radar', 'funnel', 'gauge', 'scatter', 'mbar', 'sbar', 'mline', 'sarea', 'treemap', 'sunburst'] as $type) {
            $this->assertTrue(ChartType::supportsColorEdit($type), $type);
        }
        foreach (['heatmap', 'boxplot', null] as $type) {
            $this->assertFalse(ChartType::supportsColorEdit($type), json_encode($type));
        }
    }

}
