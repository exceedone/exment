<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;

/**
 * Shared helpers for the DB-free dashboard unit tests.
 *
 * Everything FilterState & co. read comes from (a) the current request and (b) plain
 * model attributes / relations, so tests can build the whole world in memory:
 * unsaved Dashboard / DashboardBox models (never persisted — no DB round trip) plus the
 * Fake* doubles in this namespace.
 */
trait DashboardUnitHelpers
{
    /**
     * Swap the container's request for a fresh GET request carrying $params — the same
     * trick the _evd/tests harness uses (Facade cache must be cleared, or request() keeps
     * serving the previous instance).
     */
    protected function swapRequest(array $params = []): Request
    {
        $req = Request::create('/admin', 'GET', $params);
        $this->app->instance('request', $req);
        Facade::clearResolvedInstance('request');
        return $req;
    }

    /** Unsaved Dashboard carrying options.filter_bar = $filterBar (null = no bar at all). */
    protected function makeDashboard(?array $filterBar, array $extraOptions = []): Dashboard
    {
        $options = $extraOptions;
        if ($filterBar !== null) {
            $options['filter_bar'] = $filterBar;
        }
        $d = new Dashboard();
        $d->dashboard_name = 'unit_dashboard';
        $d->options = $options;
        return $d;
    }

    /**
     * Unsaved chart DashboardBox with the given suuid + options, attached (in memory) to
     * $dashboard so `$box->dashboard` resolves without a query.
     */
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

    /** filter_bar config with plain dims (each: column [, extra keys]). */
    protected function bar(array $dims, array $extra = []): array
    {
        $rows = [];
        foreach ($dims as $col => $spec) {
            if (is_int($col)) {
                $rows[] = ['column' => $spec];
            } else {
                $rows[] = ['column' => $col] + $spec;
            }
        }
        return ['source_table' => 'fake_table', 'dims' => $rows] + $extra;
    }
}
