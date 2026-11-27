<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\Insights\InsightReport;
use Exceedone\Exment\Services\Insights\Insights;
use Exceedone\Exment\Services\Insights\MyInsights;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * "My insights" (ISS02964): the numbers of the user who looks, only. No parameter chooses the
 * user: an administrator sees here only their own numbers too.
 */
class MyInsightsController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('my_insights.header'), exmtrans('my_insights.header'), exmtrans('my_insights.description'), 'fa-line-chart');
    }

    public function index(Request $request, Content $content)
    {
        // the feature guard answers 404 when the feature is off; here: the user is not in its role groups
        if (!Features::allowed(Insights::MY_FEATURE)) {
            abort(403);
        }
        $user = \Exment::user();
        abort_unless(isset($user), 403);
        $this->AdminContent($content);
        $period = InsightReport::fromRequest($request, false);
        $mine = new MyInsights($user, $period->from, $period->to);
        $content->row(view('exment::insights.mine', [
            'from' => $period->from,
            'to' => $period->to,
            'dates' => $mine->dates(),
            'logins' => $mine->logins(),
            'data' => $mine->data(),
            'pm' => $mine->pmIssues(),
            'workflow' => $mine->workflow(),
            'files' => Features::enabled(Features::FILE_SERVER) ? $mine->files() : null,
            'api' => $mine->api(),
            'periods' => InsightReport::PERIODS,
            'days' => intval($request->query('days', 0)),
            'counted' => InsightDaily::tableExists(),
            'newPlaceDays' => MyInsights::NEW_PLACE_DAYS,
            'dueSoonDays' => MyInsights::DUE_SOON_DAYS,
            'url' => admin_url('auth/insights'),
        ]));
        return $content;
    }
}
