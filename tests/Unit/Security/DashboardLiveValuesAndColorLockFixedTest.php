<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\ColumnOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * FIXED (review of dashboard v2, M-24 / L-25):
 *  - a filter bar list read off the column's index, on a table with a soft-deleted row, asked
 *    one query per value (up to the option limit, 500 — 5000 configured) to drop the values
 *    only deleted rows hold: the probes are now asked 100 at a time, in one query;
 *  - painting a chart color read the box's colors, added one and wrote them all back: a paint
 *    posted at the same time wrote back the colors it had read, the other color was lost. The
 *    colors are now read again under a row lock, in the transaction of the save.
 *
 * The data is rolled back; LoginUser 1 (system admin) paints.
 */
class DashboardLiveValuesAndColorLockFixedTest extends SecurityRegressionTestCase
{
    public function test_the_live_values_are_probed_a_hundred_at_a_time(): void
    {
        $user = CustomTable::getEloquent(SystemTableName::USER);
        $column = $user->custom_columns->firstWhere('column_name', 'user_code');
        $this->assertTrue((bool) $column->index_enabled, 'user_code is indexed in the test data');
        $dbTable = getDBTableName($user);
        $indexColumn = $column->getIndexColumnName(false);

        $codes = DB::table($dbTable)->whereNull('deleted_at')->orderBy('id')->pluck($indexColumn)->map(fn ($v) => (string) $v)->all();
        $this->assertGreaterThanOrEqual(2, count($codes));
        // the first user is deleted: its code is held by no live row any more
        DB::table($dbTable)->where($indexColumn, $codes[0])->update(['deleted_at' => now()]);
        // more values than one query probes; values no row holds; a code in other letter case
        $values = array_merge($codes, [strtoupper($codes[1])], array_map(fn ($i) => 'no_such_code_' . $i, range(1, 150)));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $live = (new \ReflectionMethod(ColumnOptions::class, 'liveValues'))->invoke(null, $user, $column, $values);
        $asked = count($queries);

        // the same answer as one probe per value
        $expected = array_values(array_filter($values, function ($v) use ($dbTable, $indexColumn) {
            return DB::table($dbTable)->where($indexColumn, $v)->whereNull('deleted_at')->exists();
        }));
        $this->assertSame($expected, $live);
        $this->assertNotContains($codes[0], $live, 'a code only a deleted row holds');
        $this->assertContains($codes[1], $live);
        $this->assertNotContains('no_such_code_1', $live);
        // hasTrashed, then the probes: ceil(n / 100) queries, not n
        $this->assertSame(1 + (int) ceil(count($values) / 100), $asked);
    }

    public function test_the_colors_are_read_again_under_a_lock_and_saved_in_its_transaction(): void
    {
        $this->asUser(LoginUser::find(1));
        // two requests at once cannot run in a test: the lock and the transaction are checked
        $box = $this->chartBox(['chart_colors' => ['points' => ['A' => '#ff0000']]]);

        $outer = DB::transactionLevel();
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (stripos($query->sql, 'for update') !== false) {
                $locks[] = $query->sql;
            }
        });
        $levels = [];
        Event::listen('eloquent.saving: ' . DashboardBox::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $this->post($this->url('dashboardbox/chart_color/' . $box->suuid), ['kind' => 'points', 'key' => 'B', 'color' => '#00ff00'])
            ->assertJson(['status' => true]);

        $this->assertSame(
            ['points' => ['A' => '#ff0000', 'B' => '#00ff00']],
            array_get($this->storedOptions($box), 'chart_colors'),
            'added to the colors painted before'
        );
        $this->assertCount(1, $locks, 'the box is read with a row lock');
        $this->assertCount(1, $levels);
        $this->assertGreaterThan($outer, $levels[0], 'saved in the transaction of the lock');
    }

    public function test_a_reset_and_a_refused_kind_still_answer_as_before(): void
    {
        $this->asUser(LoginUser::find(1));
        $box = $this->chartBox(['chart_colors' => ['points' => ['A' => '#ff0000']]]);
        $url = $this->url('dashboardbox/chart_color/' . $box->suuid);

        $this->post($url, ['kind' => 'bars', 'key' => 'B', 'color' => '#00ff00'])->assertStatus(422);
        $this->assertSame(['points' => ['A' => '#ff0000']], array_get($this->storedOptions($box), 'chart_colors'));

        $this->post($url, ['reset' => 1])->assertJson(['status' => true]);
        $this->assertFalse(array_has($this->storedOptions($box), 'chart_colors'));

        $this->post($this->url('dashboardbox/chart_color/no_such_box'), ['kind' => 'points', 'key' => 'A', 'color' => '#00ff00'])->assertStatus(404);
    }

    /**
     * A chart box on a system dashboard created by the current user.
     *
     * @param array<string, mixed> $options
     */
    private function chartBox(array $options = []): DashboardBox
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'colors_' . uniqid();
        $dashboard->dashboard_view_name = 'dashboard';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'chart';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = $options;
        $box->save();
        return $box;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedOptions(DashboardBox $box): array
    {
        $stored = DashboardBox::where('id', $box->id)->first();
        $this->assertInstanceOf(DashboardBox::class, $stored);
        return $stored->options ?? [];
    }

    private function asUser(?LoginUser $login): void
    {
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
