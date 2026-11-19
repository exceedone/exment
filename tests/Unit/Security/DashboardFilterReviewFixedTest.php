<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ColumnOptions;
use Exceedone\Exment\Services\Dashboard\FilterValue;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeCustomColumn;
use Illuminate\Support\Facades\DB;

/**
 * FIXED (dashboard filter review):
 * - a filter on a list column (select / select_table / user / organization with
 *   multiple_enabled, stored as a JSON array) compared the array text with the picked value
 *   and never matched; its options listed the arrays ("["1", "2"]") instead of the values
 * - a non-ASCII value of a list column never matched on MariaDB / SQL Server, which keep the
 *   list with its text escaped ("\u6771\u4eac"): the value is now searched for escaped too
 * - filter_bar_columns?q[id][]=…, filter_bar_values?table[id][]=…,
 *   dashboardbox/chart_filter_columns?q[id][]=… answered 500: CustomTable::getEloquent()
 *   answers such an array with the array itself
 *
 * The list column is a stand-in on the user table, whose rows are inserted here and rolled
 * back; the requests use LoginUser 1 (system admin).
 */
class DashboardFilterReviewFixedTest extends SecurityRegressionTestCase
{
    public function test_a_list_column_filter_matches_the_rows_holding_the_value(): void
    {
        [$table, $mark] = $this->listRows();
        $column = $this->listColumn('select');
        $count = function (string ...$values) use ($table, $mark, $column) {
            $query = DB::table($table)->where("{$table}.value->list_mark", $mark);
            FilterValue::apply($query, $column, ['in' => array_values($values)]);
            return $query->count();
        };

        $this->assertSame(2, $count('1'), '["1","2"] and "1", stored before the column turned multiple');
        $this->assertSame(3, $count('2'));
        $this->assertSame(4, $count('2', '3'), 'any of the values');
        $this->assertSame(1, $count('12'), 'a whole value, not a part of one');
        $this->assertSame(0, $count('9'));
        $this->assertSame(1, $count('東京'), 'a non-ASCII value, however the list is stored');
        $this->assertSame(0, $count('京'));
    }

    public function test_a_list_column_lists_its_values(): void
    {
        $this->asAdmin();
        [, $mark] = $this->listRows();
        $user = CustomTable::getEloquent('user');
        $scope = function ($query) use ($mark) {
            $query->where('value->list_mark', $mark);
        };

        $select = $this->listColumn('select');
        $select->options['select_item'] = "1\n2\n3\n12\n99";
        $result = ColumnOptions::choices($user, $select, $scope, true);
        $this->assertSame(['1', '2', '3', '12'], array_column($result['options'], 'id'), 'the catalogue, cross-filtered to the values the lists hold');

        $plain = $this->listColumn('select_table'); // no master: the values the rows hold
        $result = ColumnOptions::distinct($user, $plain, $scope);
        $this->assertSame(['1', '2', '3', '12', '東京'], array_column($result['options'], 'id'), 'once each, in numeric order, read back from the stored text');
    }

    public function test_table_endpoints_take_an_array_table_as_none(): void
    {
        $this->asAdmin();
        $prefix = '/' . config('admin.route.prefix', 'admin') . '/';

        $response = $this->get($prefix . 'dashboard/filter_bar_columns?q[id][]=1');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json());

        $response = $this->get($prefix . 'dashboard/filter_bar_values?table[id][]=1&column=user_name');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json('options'));

        $response = $this->get($prefix . 'dashboardbox/chart_filter_columns?q[id][]=1');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json());
    }

    public function test_table_endpoints_still_read_a_table_name(): void
    {
        $this->asAdmin();
        $prefix = '/' . config('admin.route.prefix', 'admin') . '/';

        $response = $this->get($prefix . 'dashboard/filter_bar_columns?q=user');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('user_name', array_column($response->json(), 'id'));

        $response = $this->get($prefix . 'dashboardbox/chart_filter_columns?q=user');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->json());
    }

    /**
     * Rows of the user table holding a list in value.list: ["1","2"], ["2"], ["3"], ["12"]
     * and "1" (a value stored before the column turned multiple), marked to be told apart.
     *
     * @return array{0: string, 1: string} [db table, mark]
     */
    private function listRows(): array
    {
        $table = getDBTableName(CustomTable::getEloquent('user'));
        $mark = uniqid('list_');
        // stored the way the engine stores a list: its non-ASCII text escaped ("\u6771\u4eac")
        // where the database keeps JSON as text (MariaDB, SQL Server), as is on MySQL
        $flags = \ExmentDB::isUseUnicodeMultipleColumn() ? 0 : JSON_UNESCAPED_UNICODE;
        foreach ([['1', '2'], ['2'], ['3'], ['12'], '1', ['東京', '2']] as $list) {
            DB::table($table)->insert([
                'suuid' => short_uuid(),
                'value' => json_encode(['list' => $list, 'list_mark' => $mark], $flags),
            ]);
        }
        return [$table, $mark];
    }

    private function listColumn(string $type): FakeCustomColumn
    {
        $column = new FakeCustomColumn('list', $type);
        $column->options = ['multiple_enabled' => '1'];
        $column->custom_table_cache = CustomTable::getEloquent('user');
        return $column;
    }

    private function asAdmin(): void
    {
        $admin = LoginUser::find(1);
        if ($admin === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($admin, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }
}
