<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use PHPUnit\Framework\TestCase;

/**
 * The filter settings screen offers user and organization columns, but
 * getValue() resolves them to a CustomValue object, which rangeValue() cannot
 * turn into a number. The document then carries no n_<table>::<column> at all,
 * so the sidebar showed a min/max box that matched zero records.
 */
class SupportsRangeTest extends TestCase
{
    public function testDateAndNumericColumnsSupportRange(): void
    {
        foreach (['date', 'datetime', 'time', 'integer', 'decimal', 'currency'] as $type) {
            $this->assertTrue(DocumentMapper::supportsRange($type), $type);
        }
    }

    public function testRelationAndTextColumnsDoNot(): void
    {
        foreach (['user', 'organization', 'select', 'select_table', 'text', 'yesno'] as $type) {
            $this->assertFalse(DocumentMapper::supportsRange($type), $type);
        }
    }

    /**
     * The declared list must match what rangeValue() can actually produce, or a
     * column passes configuration and still indexes nothing.
     */
    public function testEveryDeclaredTypeActuallyProducesANumber(): void
    {
        $sample = [
            'date' => '2025-01-15',
            'datetime' => '2025-01-15 14:30:00',
            'time' => '14:30:00',
            'integer' => '42',
            'decimal' => '42.5',
            'currency' => '1000',
        ];

        foreach (DocumentMapper::RANGE_COLUMN_TYPES as $type) {
            $this->assertArrayHasKey($type, $sample, "no sample value for {$type}");
            $this->assertNotNull(
                DocumentMapper::rangeValue($sample[$type], $type),
                "{$type} is declared range-capable but rangeValue() returns null."
            );
        }
    }

    public function testUnsupportedValueYieldsNoRangeField(): void
    {
        $this->assertNull(DocumentMapper::rangeValue(new \stdClass(), 'user'));
    }
}
