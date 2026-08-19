<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Illuminate\Http\Request;

/**
 * Admin page for the safety-check (安否確認) feature: list recent events, trigger a
 * new one (manual/drill), re-send to still-unanswered users (throttled via the
 * safety_check_resend_throttle_minutes setting), and close an event. Every action
 * requires the system permission (see the constructor middleware).
 */
class SafetyCheckController extends AdminControllerBase
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
                abort(403);
            }
            return $next($request);
        });
    }

    public function index(Request $request, Content $content)
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);

        // newest-first, capped list size for the index table
        // (intSetting: an emptied admin-UI field is stored as 0 -> Define default 20)
        $events = $eventTable->getValueQuery()
            ->orderBy('id', 'desc')
            ->limit(SafetyCheckDefine::intSetting('safety_check_index_limit'))
            ->get();

        return $this->AdminContent($content)
            ->title(exmtrans('safety.menu_title'))
            ->body(view('exment::safety.index', [
                'events' => $events,
                'answeredCounts' => $this->answeredCounts($answerTable, $events),
            ]));
    }

    public function send(Request $request)
    {
        $title = trim((string) $request->get('title'));
        if ($title === '') {
            admin_toastr(exmtrans('safety.message_title_required'), 'error');
            return redirect(admin_url('safety_check'));
        }

        $triggerType = $request->get('trigger_type');
        if (!in_array($triggerType, [SafetyCheckDefine::TRIGGER_MANUAL, SafetyCheckDefine::TRIGGER_DRILL], true)) {
            $triggerType = SafetyCheckDefine::TRIGGER_MANUAL;
        }

        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $event = $eventTable->getValueModel();
        $event->setValue([
            'title' => $title,
            'trigger_type' => $triggerType,
            'event_status' => SafetyCheckDefine::EVENT_OPEN,
            'triggered_at' => now()->format('Y-m-d H:i:s'),
        ])->save();

        SafetyCheckSender::send($event);

        admin_toastr(exmtrans('safety.message_send_succeeded'));
        return redirect(admin_url('safety_check'));
    }

    public function resend(Request $request, $id)
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $event = $this->findEventOrFail($eventTable, $id);

        if ($event->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED) {
            // A closed event's buttons can only reply "closed" (see SafetyCheckAction::handle);
            // resending it would just send paid LINE messages nobody can meaningfully answer.
            admin_toastr(exmtrans('safety.resend_closed_error'), 'error');
            return redirect(admin_url('safety_check'));
        }

        // Re-send is throttled to at most once every N minutes (0 = no throttle).
        // The check and the resent_at write are ONE conditional UPDATE, so two
        // concurrent clicks cannot both pass a read-then-write check and double-send.
        $throttle = (int) System::safety_check_resend_throttle_minutes();
        if ($throttle > 0) {
            $cutoff = now()->subMinutes($throttle)->format('Y-m-d H:i:s');
            $reserved = \DB::table(getDBTableName($eventTable))
                ->where('id', $event->id)
                ->where(function ($query) use ($cutoff) {
                    $query->whereNull('value->resent_at')
                        ->orWhere('value->resent_at', '<=', $cutoff);
                })
                ->update(['value->resent_at' => now()->format('Y-m-d H:i:s')]);
            if (!$reserved) {
                admin_toastr(exmtrans('safety.message_resend_throttled', ['minutes' => $throttle]), 'error');
                return redirect(admin_url('safety_check'));
            }
        }

        SafetyCheckSender::send($event, true);

        admin_toastr(exmtrans('safety.message_resend_succeeded'));
        return redirect(admin_url('safety_check'));
    }

    public function close(Request $request, $id)
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $event = $this->findEventOrFail($eventTable, $id);

        $event->setValue(['event_status' => SafetyCheckDefine::EVENT_CLOSED])->save();

        admin_toastr(exmtrans('safety.message_close_succeeded'));
        return redirect(admin_url('safety_check'));
    }

    /** @return CustomValue */
    protected function findEventOrFail(CustomTable $eventTable, $id)
    {
        $event = $eventTable->getValueQuery()->find($id);
        if (!$event) {
            abort(404);
        }
        return $event;
    }

    /**
     * Answered-answer count per listed event, as [event id => count]. ONE grouped
     * query on the generated index columns instead of a COUNT per event (the JSON
     * paths cannot use the index and would scan the answer table N times).
     *
     * @param CustomTable $answerTable
     * @param \Illuminate\Support\Collection $events
     * @return array<int|string, int>
     */
    protected function answeredCounts(CustomTable $answerTable, $events): array
    {
        $indexEvent  = CustomColumn::getEloquent('event', $answerTable)->getIndexColumnName();
        $indexStatus = CustomColumn::getEloquent('answer_status', $answerTable)->getIndexColumnName();

        $counts = $answerTable->getValueQuery()
            ->whereIn($indexEvent, $events->pluck('id')->map('strval')->all())
            ->where($indexStatus, '<>', SafetyCheckDefine::ANSWER_NOT_ANSWERED)
            ->groupBy($indexEvent)
            ->selectRaw($indexEvent . ' as event_id, count(*) as answered')
            ->pluck('answered', 'event_id');

        $answeredCounts = [];
        foreach ($events as $event) {
            $answeredCounts[$event->id] = (int) $counts->get($event->id, 0);
        }
        return $answeredCounts;
    }
}
