<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Keep only accessible hits (already passed Exment's global scope), preserving Meili order.
 */
class FilterAccessibleHitsTest extends TestCase
{
    /**
     * @return array<int,array<string,mixed>>
     */
    private function hits(): array
    {
        return [
            ['table_name' => 'invoice', 'value_id' => 1, 'label' => 'A'],
            ['table_name' => 'invoice', 'value_id' => 2, 'label' => 'B'],
            ['table_name' => 'user', 'value_id' => 9, 'label' => 'C'],
            ['table_name' => 'secret', 'value_id' => 5, 'label' => 'D'],
        ];
    }

    public function testFiltersOutNonAccessibleIds(): void
    {
        $accessible = [
            'invoice' => [1 => true],        // only id 1 is accessible
            'user' => [9 => true],
            // 'secret' absent -> excluded entirely
        ];

        $out = MeiliSearchService::filterAccessibleHits($this->hits(), $accessible, 10);

        $labels = array_column($out, 'label');
        $this->assertSame(['A', 'C'], $labels);
    }

    public function testPreservesMeiliOrder(): void
    {
        $accessible = ['invoice' => [1 => true, 2 => true], 'user' => [9 => true], 'secret' => [5 => true]];

        $out = MeiliSearchService::filterAccessibleHits($this->hits(), $accessible, 10);

        $this->assertSame(['A', 'B', 'C', 'D'], array_column($out, 'label'));
    }

    public function testCapsAtLimit(): void
    {
        $accessible = ['invoice' => [1 => true, 2 => true], 'user' => [9 => true], 'secret' => [5 => true]];

        $out = MeiliSearchService::filterAccessibleHits($this->hits(), $accessible, 2);

        $this->assertCount(2, $out);
        $this->assertSame(['A', 'B'], array_column($out, 'label'));
    }

    public function testTableAbsentMeansNoAccess(): void
    {
        $out = MeiliSearchService::filterAccessibleHits($this->hits(), [], 10);

        $this->assertSame([], $out);
    }
}
