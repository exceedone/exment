<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Studio\ScreenLayout;

/**
 * The rows and columns of a Studio screen (ISS00231 phase 3).
 */
class StudioScreenLayoutTest extends UnitTestBase
{
    public function testAScreenWithoutALayoutIsOneRowOfOneColumn(): void
    {
        $layout = ScreenLayout::normalize(null, [['label' => 'a'], ['label' => 'b']]);

        $this->assertSame([[4]], $layout['rows']);
        $this->assertSame([['row' => 0, 'column' => 0], ['row' => 0, 'column' => 0]], $layout['places']);
    }

    public function testTheLayoutAndThePlacesAreRead(): void
    {
        $layout = ScreenLayout::normalize('[{"widths":[2,2]},{"widths":[4]}]', [
            ['row' => 0, 'column' => 1],
            ['row' => '1', 'column' => '0'],
        ]);

        $this->assertSame([[2, 2], [4]], $layout['rows']);
        $this->assertSame([['row' => 0, 'column' => 1], ['row' => 1, 'column' => 0]], $layout['places']);
        $this->assertSame([], ScreenLayout::validate($layout));
    }

    public function testOnlyTheOfferedWaysToSplitARowAreTaken(): void
    {
        $this->assertArrayHasKey('layout', ScreenLayout::validate(ScreenLayout::normalize('[{"widths":[3,3]}]', [])));
        $this->assertArrayHasKey('layout', ScreenLayout::validate(ScreenLayout::normalize('[{"widths":[1,1,1]}]', [])));
        foreach (ScreenLayout::PRESETS as $widths) {
            $this->assertLessThanOrEqual(4, array_sum($widths));
            $this->assertSame([], ScreenLayout::validate(['rows' => [$widths], 'places' => []]));
        }
    }

    public function testAnItemOutsideTheRowsIsAMistake(): void
    {
        $this->assertArrayHasKey('layout', ScreenLayout::validate(ScreenLayout::normalize('[{"widths":[2,2]}]', [['row' => 0, 'column' => 2]])));
        $this->assertArrayHasKey('layout', ScreenLayout::validate(ScreenLayout::normalize('[{"widths":[4]}]', [['row' => 1, 'column' => 0]])));
    }

    public function testTooManyRowsIsAMistake(): void
    {
        $rows = array_fill(0, ScreenLayout::MAX_ROWS + 1, ['widths' => [4]]);
        $this->assertArrayHasKey('layout', ScreenLayout::validate(ScreenLayout::normalize($rows, [])));
    }

    public function testEmptyRowsAreDroppedAndTheRowsNumberedAgain(): void
    {
        $layout = ScreenLayout::withoutEmptyRows([
            'rows' => [[4], [2, 2], [1, 1, 1, 1], [3, 1]],
            'places' => [['row' => 1, 'column' => 1], ['row' => 3, 'column' => 0]],
        ]);

        $this->assertSame([[2, 2], [3, 1]], $layout['rows']);
        $this->assertSame([['row' => 0, 'column' => 1], ['row' => 1, 'column' => 0]], $layout['places']);
    }
}
