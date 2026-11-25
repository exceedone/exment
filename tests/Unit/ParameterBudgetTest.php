<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Database\ParameterBudget;
use Illuminate\Support\Facades\DB;

/**
 * ParameterBudget: the count of bound parameters of a statement and the answer "does it fit".
 * SQL Server accepts 2,100 bound parameters per statement; the other drivers get no budget and are left as they are.
 */
class ParameterBudgetTest extends UnitTestBase
{
    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function limited(): bool
    {
        return $this->driver() === 'sqlsrv';
    }

    public function testTheLimitIsOnlyForSqlServer(): void
    {
        if ($this->limited()) {
            $this->assertSame(ParameterBudget::SQLSRV_LIMIT - ParameterBudget::SQLSRV_RESERVE, ParameterBudget::limit());
            $this->assertSame(2000, ParameterBudget::limit());
            $this->assertTrue(ParameterBudget::isLimited());
        } else {
            $this->assertNull(ParameterBudget::limit(), 'no budget: the driver is left as it is');
            $this->assertFalse(ParameterBudget::isLimited());
        }
        $this->assertSame(2100, ParameterBudget::SQLSRV_LIMIT);
    }

    public function testCountsEveryBindingOfTheStatement(): void
    {
        $this->assertSame(0, ParameterBudget::bindingCount([]));
        $this->assertSame(3, ParameterBudget::bindingCount([1, 'a', null]));

        $query = DB::table('custom_tables')
            ->where('id', 1)                                   // 1
            ->whereIn('table_name', ['a', 'b', 'c'])           // 3
            ->where(function ($q) {                            // 2
                $q->where('order', '>', 1)->orWhere('order', '<', -1);
            })
            ->whereExists(function ($q) {                      // 1
                $q->select(DB::raw(1))->from('custom_columns')->whereColumn('custom_columns.custom_table_id', 'custom_tables.id')->where('column_type', 'x');
            })
            ->whereRaw('1 = ?', [1]);                          // 1
        $this->assertSame(8, ParameterBudget::bindingCount($query));
        $this->assertSame(8, ParameterBudget::bindingCount(\Exceedone\Exment\Model\CustomTable::query()->where('id', 1)->whereIn('table_name', range(1, 7))), 'an Eloquent builder counts the same way');
    }

    public function testSeveralInListsCarryTheSumOfTheirValues(): void
    {
        // "A OR IN clauses reduce nothing": 3 lists of 5 are 15 bound parameters
        $query = DB::table('custom_tables')->where(function ($q) {
            $q->whereIn('id', range(1, 5))->orWhereIn('order', range(1, 5))->orWhereIn('table_name', ['a', 'b', 'c', 'd', 'e']);
        });
        $this->assertSame(15, ParameterBudget::bindingCount($query));
    }

    public function testBelowAtAndAboveTheBudgetWithTheBindingsOfOtherFilters(): void
    {
        $limit = ParameterBudget::limit();
        if (is_null($limit)) {
            // another driver: everything fits, whatever the size
            $query = DB::table('custom_tables')->whereIn('id', range(1, 3000));
            $this->assertTrue(ParameterBudget::fits($query));
            $this->assertTrue(ParameterBudget::fits($query, 1000000));
            return;
        }

        // the statement already carries 40 bindings of its own filters
        $base = function (int $own) {
            return DB::table('custom_tables')->whereIn('id', range(1, $own));
        };
        $own = 40;
        $this->assertSame($own, ParameterBudget::bindingCount($base($own)));

        $additional = $limit - $own;                              // exactly the budget
        $this->assertTrue(ParameterBudget::fits($base($own), $additional - 1), 'below the budget');
        $this->assertTrue(ParameterBudget::fits($base($own), $additional), 'at the budget');
        $this->assertFalse(ParameterBudget::fits($base($own), $additional + 1), 'above the budget');

        // the filters of the statement count as much as the added part
        $this->assertTrue(ParameterBudget::fits($base($limit), 0), 'the statement itself at the budget');
        $this->assertFalse(ParameterBudget::fits($base($limit + 1), 0), 'the statement itself above the budget');
        $this->assertFalse(ParameterBudget::fits($base($limit), 1));

        // the reserve is kept free: at the budget the statement still runs (2,100 - 100 reserved)
        $rows = $base($limit)->count();
        $this->assertGreaterThanOrEqual(0, $rows);
    }

    public function testPreferSubqueriesIsScopedAndRestoredEvenWhenTheCallbackThrows(): void
    {
        $this->assertFalse(ParameterBudget::subqueriesPreferred());
        $this->assertSame('inside', ParameterBudget::preferSubqueries(function () {
            $this->assertTrue(ParameterBudget::subqueriesPreferred());
            // nested
            ParameterBudget::preferSubqueries(function () {
                $this->assertTrue(ParameterBudget::subqueriesPreferred());
            });
            $this->assertTrue(ParameterBudget::subqueriesPreferred(), 'still on after the inner one');
            return 'inside';
        }));
        $this->assertFalse(ParameterBudget::subqueriesPreferred());

        try {
            ParameterBudget::preferSubqueries(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse(ParameterBudget::subqueriesPreferred(), 'restored after an exception');
    }

    /**
     * whereInMultiple() on SQL Server (no row-value IN): the default reads the ids first and binds them, the set-based form
     * asked for by ParameterBudget::preferSubqueries() gives the same rows with a statement that carries the values of the lookup.
     */
    public function testWhereInMultipleSubqueryFormGivesTheSameRowsWithFewerBindings(): void
    {
        if (!$this->limited()) {
            $this->markTestSkipped('only SQL Server reads the ids first: the other drivers have the row-value IN');
        }
        $pairs = DB::table('custom_value_authoritables')->select('authoritable_user_org_type', 'authoritable_target_id')->distinct()->limit(5)->get()
            ->map(function ($r) {
                return [$r->authoritable_user_org_type, $r->authoritable_target_id];
            })->all();
        if (count($pairs) < 2) {
            $this->markTestSkipped('needs shares in custom_value_authoritables');
        }
        $build = function () use ($pairs) {
            return \Exceedone\Exment\Model\CustomValueAuthoritable::query()->whereInMultiple(['authoritable_user_org_type', 'authoritable_target_id'], $pairs, true);
        };

        $default = $build();
        $subquery = ParameterBudget::preferSubqueries($build);
        $this->assertSame(
            $default->orderBy('id')->pluck('id')->all(),
            $subquery->orderBy('id')->pluck('id')->all(),
            'the same rows'
        );
        $this->assertStringContainsString('select [id] from [custom_value_authoritables]', $subquery->toBase()->toSql());
        $this->assertStringNotContainsString('select [id] from', $default->toBase()->toSql());

        // no share matches: both are empty, and the empty list is still "no row"
        $none = [['no_such_type', 987654321]];
        $empty = \Exceedone\Exment\Model\CustomValueAuthoritable::query()->whereInMultiple(['authoritable_user_org_type', 'authoritable_target_id'], $none, true);
        $emptySubquery = ParameterBudget::preferSubqueries(function () use ($none) {
            return \Exceedone\Exment\Model\CustomValueAuthoritable::query()->whereInMultiple(['authoritable_user_org_type', 'authoritable_target_id'], $none, true);
        });
        $this->assertSame([], $empty->pluck('id')->all());
        $this->assertSame([], $emptySubquery->pluck('id')->all());
        // an empty value list with $zeroQueryIfEmpty: always false in both forms
        $this->assertSame([], \Exceedone\Exment\Model\CustomValueAuthoritable::query()->whereInMultiple(['authoritable_user_org_type', 'authoritable_target_id'], [], true)->pluck('id')->all());
    }
}
