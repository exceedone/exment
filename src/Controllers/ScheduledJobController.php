<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Services\ScheduledJobs\Recorder;
use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleStatus;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleTuning;
use Exceedone\Exment\Services\UserTimezone;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The scheduled jobs (ISS02832): what php artisan schedule:run does, when, whether it is
 * used, its last runs, and "Run now". Only system administrators (Auth\Permission,
 * "scheduled_jobs"), only when the feature is used (Features, "scheduled_jobs").
 */
class ScheduledJobController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('scheduled_jobs.header'), exmtrans('scheduled_jobs.header'), exmtrans('scheduled_jobs.description'), 'fa-clock-o');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::onlyAdministrators();
        $this->AdminContent($content);

        $users = [];
        $userName = function (string $triggeredBy) use (&$users): string {
            if ($triggeredBy === ScheduledJobRun::TRIGGERED_BY_SYSTEM) {
                return strval(exmtrans('scheduled_jobs.triggered_system'));
            }
            if (!array_key_exists($triggeredBy, $users)) {
                $user = getModelName(SystemTableName::USER)::find(intval($triggeredBy));
                $users[$triggeredBy] = isset($user) ? strval($user->getLabel()) : $triggeredBy;
            }
            return $users[$triggeredBy];
        };

        $rows = [];
        foreach (ScheduledJobs::jobs() as $job) {
            $runs = ScheduledJobRun::query()->where('job_key', $job['key'])->orderByDesc('id')->limit(Recorder::keepRuns())->get();
            $rows[] = $job + [
                'runs' => $runs,
                'last' => $runs->first(),
                'streak' => Recorder::failureStreak($job['key']),
                'failing' => Recorder::failing($job['key']),
                'unavailable' => ScheduledJobs::runUnavailable($job),
                'disabled_text' => $job['enabled'] ? null : ScheduledJobs::disabledText($job['key'], $job['reason']),
            ];
        }

        $lastFinished = ScheduleStatus::read()['last_finished'];
        $content->row(view('exment::scheduled_jobs.index', [
            'rows' => $rows,
            'alive' => (new ScheduleTuning())->alive(),
            'lastScheduleRun' => is_null($lastFinished) ? null : static::local(\Carbon\Carbon::createFromTimestamp($lastFinished, strval(config('app.timezone')))),
            'staleMinutes' => intdiv(ScheduleTuning::STALE_SECONDS, 60),
            'cronLine' => (new ScheduleTuning())->getFixText(),
            'processAvailable' => ScheduledJobs::processAvailable(),
            'alertFailures' => Recorder::alertFailures(),
            'keepRuns' => Recorder::keepRuns(),
            'local' => function ($value): string {
                return static::local($value);
            },
            'userName' => $userName,
            'runUrl' => admin_urls('scheduled_jobs', 'run'),
        ]));
        return $content;
    }

    /**
     * Run a job now, in the background.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function run(Request $request)
    {
        static::onlyAdministrators();
        $key = strval($request->get('job'));
        [$done, $reason] = ScheduledJobs::runNow($key, \Exceedone\Exment\Facades\ExmentFacade::getUserId());
        if ($done) {
            admin_toastr(exmtrans('scheduled_jobs.message.started', ['name' => ScheduledJobs::label($key)]));
        } else {
            admin_toastr(ScheduledJobs::unavailableText($reason ?? 'failed'), 'error');
        }
        return redirect(admin_url('scheduled_jobs'));
    }

    /**
     * The system administrators only: also on a site where the roles are not
     * used (Auth\Permission lets everyone in then).
     */
    protected static function onlyAdministrators(): void
    {
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        if (!isset($user) || !$user->isAdministrator()) {
            abort(403);
        }
    }

    /**
     * A date and time of the site in the time zone of the user.
     *
     * @param mixed $value
     */
    public static function local($value): string
    {
        return strval(UserTimezone::display($value, 'Y-m-d H:i:s') ?? '');
    }
}
