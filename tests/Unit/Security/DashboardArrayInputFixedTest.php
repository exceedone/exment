<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * FIXED: a dashboard endpoint answered 500 to a parameter posted as an array where it
 * reads a text:
 * - dashboard/filter_bar_values?dashboard[]=…: Dashboard::find() of an array is a list,
 *   which FilterBarConfig::fromDashboard() then called getOption() on
 * - dashboardbox/chart_color with key[]=…: (string) of an array
 * Both now treat it as a value they do not know (no dashboard / 422).
 *
 * The runtime checks use LoginUser 1 (system admin) and the user table; data rolled back.
 */
class DashboardArrayInputFixedTest extends SecurityRegressionTestCase
{
    public function test_filter_bar_values_takes_an_array_dashboard_as_none(): void
    {
        $response = $this->asAdmin()->get($this->url('dashboard/filter_bar_values') . '?table=user&column=user_name&dashboard[]=1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('select', $response->json('kind'));
    }

    public function test_filter_bar_values_still_reads_a_dashboard_id(): void
    {
        $box = $this->chartBox();

        $response = $this->asAdmin()->get($this->url('dashboard/filter_bar_values') . '?table=user&column=user_name&dashboard=' . $box->dashboard_id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('select', $response->json('kind'));
    }

    public function test_chart_color_answers_422_to_an_array_key(): void
    {
        $box = $this->chartBox();

        $response = $this->asAdmin()->post($this->url('dashboardbox/chart_color/' . $box->suuid), ['kind' => 'series', 'key' => ['a'], 'color' => '#ff0000']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], array_get($box->refresh()->options, 'chart_colors', []));
    }

    public function test_chart_color_still_paints_the_one_series_of_an_empty_key(): void
    {
        $box = $this->chartBox();

        // an empty key reaches the controller as null (ConvertEmptyStringsToNull)
        $response = $this->asAdmin()->post($this->url('dashboardbox/chart_color/' . $box->suuid), ['kind' => 'series', 'key' => '', 'color' => '#FF0000']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['series' => ['' => '#ff0000']], $box->refresh()->options['chart_colors']);
    }

    public function test_chart_color_reset_ignores_the_key(): void
    {
        $box = $this->chartBox(['chart_colors' => ['points' => ['A' => '#00ff00']]]);

        $response = $this->asAdmin()->post($this->url('dashboardbox/chart_color/' . $box->suuid), ['reset' => 1, 'key' => ['a']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('chart_colors', $box->refresh()->options ?? []);
    }

    private function asAdmin(): self
    {
        $admin = LoginUser::find(1);
        if ($admin === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        return $this->actingAs($admin, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }

    /**
     * A user dashboard of LoginUser 1 with one chart box (rolled back by the transaction).
     *
     * @param array<string, mixed> $options
     */
    private function chartBox(array $options = []): DashboardBox
    {
        $this->asAdmin();
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'array_input_' . uniqid();
        $dashboard->dashboard_view_name = 'array input';
        $dashboard->created_user_id = LoginUser::find(1)->getUserId();
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'box';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = $options;
        $box->save();
        return $box;
    }
}
