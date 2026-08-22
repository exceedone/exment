<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Tests\TestCase;

/**
 * Base of the DB-free dashboard unit tests: every SQL statement executed during a test
 * FAILS it — the tests build their world from unsaved models, the Fake* doubles here and
 * the current request.
 */
abstract class DashboardUnitTestCase extends TestCase
{
    /** @var string[] */
    protected $sqlSeen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlSeen = [];
        DB::listen(function ($query) {
            $this->sqlSeen[] = $query->sql;
        });
    }

    protected function tearDown(): void
    {
        $seen = $this->sqlSeen;
        parent::tearDown();
        if (!empty($seen)) {
            $this->fail("unit test executed SQL:\n  " . implode("\n  ", $seen));
        }
    }

    /** Swap the container's request for a fresh GET carrying $params. */
    protected function swapRequest(array $params = []): Request
    {
        $request = Request::create('/admin', 'GET', $params);
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        return $request;
    }

    /** Unsaved Dashboard carrying options.filter_bar = $filterBar (null = no bar). */
    protected function makeDashboard(?array $filterBar, array $extraOptions = []): Dashboard
    {
        $options = $extraOptions;
        if ($filterBar !== null) {
            $options['filter_bar'] = $filterBar;
        }
        $dashboard = new Dashboard();
        $dashboard->dashboard_name = 'unit_dashboard';
        $dashboard->options = $options;
        return $dashboard;
    }

    /** Unsaved chart box attached in memory to $dashboard. */
    protected function makeBox(string $suuid, array $options, ?Dashboard $dashboard = null, string $type = 'chart'): DashboardBox
    {
        $box = new DashboardBox();
        $box->suuid = $suuid;
        $box->dashboard_box_type = $type;
        $box->dashboard_box_view_name = 'unit box ' . $suuid;
        $box->options = $options;
        $box->setRelation('dashboard', $dashboard ?? $this->makeDashboard(null));
        return $box;
    }

    /** filter_bar config from plain dims (column, or column => extra keys). */
    protected function bar(array $dims, array $extra = []): array
    {
        $rows = [];
        foreach ($dims as $column => $spec) {
            $rows[] = is_int($column) ? ['column' => $spec] : (['column' => $column] + $spec);
        }
        return ['source_table' => 'fake_table', 'dims' => $rows] + $extra;
    }
}
