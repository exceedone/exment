<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliSync;
use PHPUnit\Framework\TestCase;

/**
 * A document stores the label of every record it links to. Renaming a linked
 * record therefore has to re-sync the records pointing at it - otherwise the
 * search keeps answering the old name and never the new one.
 *
 * referencingColumns() decides which tables that applies to. It used to look
 * only at freeword and facet columns, so a link column used ONLY in the
 * table's label definition was missed: renaming a partner left all of its
 * orders indexed under the old partner name.
 */
class LabelLinkReindexTest extends TestCase
{
    private function labelLinkColumns($table)
    {
        $method = new \ReflectionMethod(MeiliSync::class, 'labelLinkColumns');
        $method->setAccessible(true);

        return $method->invoke(null, $table);
    }

    /**
     * A custom label format is a free string that may name any column, so every
     * column of the table has to be treated as part of the label.
     */
    public function testACustomLabelFormatOffersEveryColumn(): void
    {
        $columns = [
            (object) ['id' => 1, 'column_name' => 'product_code'],
            (object) ['id' => 2, 'column_name' => 'name'],
        ];

        $table = new class ($columns) {
            public $custom_columns_cache;

            public function __construct(array $columns)
            {
                $this->custom_columns_cache = $columns;
            }

            public function getLabelColumns()
            {
                return '【社外秘】${value:product_code} ${value:name}';
            }
        };

        $this->assertCount(2, $this->labelLinkColumns($table));
    }

    /** No label definition at all must not blow up - it simply contributes nothing. */
    public function testAnEmptyLabelDefinitionContributesNothing(): void
    {
        $table = new class () {
            public $custom_columns_cache = [];

            public function getLabelColumns()
            {
                return [];
            }
        };

        $this->assertCount(0, $this->labelLinkColumns($table));
    }

    /**
     * The columns the label is built from have to reach the same filter that
     * freeword and facet columns go through.
     */
    public function testReferencingColumnsConsidersTheLabelColumns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/Meili/MeiliSync.php');

        $this->assertStringContainsString('->concat(self::labelLinkColumns($table))', $source);
    }
}
