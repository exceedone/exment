<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\NotifyTrigger;
use Carbon\Carbon;

trait NotifyScheduleTrait
{
    /**
     * notify user flow
     */
    protected function notify()
    {
        // get notifies data for notify_trigger is 1(time), and notify_hour is executed time
        $hh = Carbon::now()->format('G');
        $notifies = Notify::where('notify_trigger', NotifyTrigger::TIME)
            ->where('trigger_settings->notify_hour', $hh)
            ->where('active_flg', 1)
            ->get();

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifySchedule();
        }
    }
}
