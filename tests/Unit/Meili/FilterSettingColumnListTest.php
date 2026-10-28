<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use PHPUnit\Framework\TestCase;

/**
 * The filter settings screen (MeiliFilterController) decides which columns an
 * admin may pick. Its allow-list was hand-written and had drifted from
 * DocumentMapper::RANGE_COLUMN_TYPES, the list of types the indexer can turn
 * into a filterable number: decimal and time were missing, so a range filter on
 * a decimal column simply could not be configured even though everything
 * downstream supported it.
 *
 * Deriving the list from the constant keeps the screen and the indexer from
 * drifting apart again - in either direction: a type that stops being
 * range-capable also stops being offered.
 */
class FilterSettingColumnListTest extends TestCase
{
    private function columnsForTableBody(): string
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Controllers/MeiliFilterController.php'
        );

        return substr($src, (int) strpos($src, 'function columnsForTable'));
    }

    public function testTheScreenOffersEveryRangeCapableType(): void
    {
        $this->assertStringContainsString(
            'DocumentMapper::RANGE_COLUMN_TYPES',
            $this->columnsForTableBody(),
            'columnsForTable() lists column types by hand again; it must derive them from '
            . 'DocumentMapper::RANGE_COLUMN_TYPES or range-capable types silently become unselectable.'
        );
    }

    /**
     * Guards the reason the list matters: a type in the constant is one the
     * saving() guard (supportsRange) will accept, so it has to be offered.
     */
    public function testEveryOfferedRangeTypePassesTheSavingGuard(): void
    {
        foreach (DocumentMapper::RANGE_COLUMN_TYPES as $type) {
            $this->assertTrue(
                DocumentMapper::supportsRange($type),
                $type . ' is offered as a range column but supportsRange() rejects it.'
            );
        }
    }
}
