<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Exceedone\Exment\Services\Dashboard\FilterState;

/**
 * FilterState::isIdentifier / columnExpr — the ONE identifier check and the ONE column SQL
 * expression every dashboard service, ChartItem and the controllers share (they used to
 * carry their own copies). Pins the exact strings the SQL layer relies on.
 */
class FilterStateHelpersTest extends DashboardUnitTestCase
{
    public function testIsIdentifier(): void
    {
        foreach (['region', 'ok_2', 'Z', '_x', '123'] as $ok) {
            $this->assertTrue(FilterState::isIdentifier($ok), $ok);
        }
        foreach (['', ' region', 'bad name', 'x;y', 'a-b', "a\n", null, 5, 1.5, true, [], ['a']] as $bad) {
            $this->assertFalse(FilterState::isIdentifier($bad), json_encode($bad));
        }
    }

    public function testColumnExprPrefersTheIndexColumn(): void
    {
        $indexed = new FakeCustomColumn('region', 'select', true);
        $this->assertSame('`column_suuid_region`', FilterState::columnExpr($indexed));
        $this->assertSame('`column_suuid_region`', FilterState::columnExpr($indexed, 'region'), 'name argument is ignored when indexed');
    }

    public function testColumnExprFallsBackToJsonExtraction(): void
    {
        $plain = new FakeCustomColumn('score', 'integer', false);
        $json = 'JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$."score"\'))';
        $this->assertSame($json, FilterState::columnExpr($plain));
        $this->assertSame($json, FilterState::columnExpr($plain, 'score'));
        $this->assertSame($json, FilterState::columnExpr(null, 'score'), 'no model → JSON path on the given name');
    }
}
