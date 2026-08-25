<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
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
    /** @var array<int|string, int> answered counts for the events on the current grid page */
    protected $answeredCounts = [];

    public function __construct()
    {
        $this->setPageInfo(exmtrans('safety.menu_title'), exmtrans('safety.menu_title'), exmtrans('safety.description'), 'fa-heartbeat');

        $this->middleware(function ($request, $next) {
            if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
                abort(403);
            }
            return $next($request);
        });
    }

    public function index(Request $request, Content $content)
    {
        return $this->AdminContent($content)
            ->body(view('exment::safety.index'))
            ->body($this->grid());
    }

    /**
     * Event list as a standard admin grid (same look as the other list screens):
     * filter, pagination, per-page selector, and per-row resend/close actions.
     *
     * @return Grid
     */
    protected function grid()
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);

        $classname = getModelName(SafetyCheckDefine::TABLE_EVENT);
        $grid = new Grid(new $classname());

        // newest-first by default (a column-sort click replaces this via resetOrderBy)
        $grid->model()->orderBy('id', 'desc');

        // answered counts for just the rows on the current page, in one grouped query
        $grid->model()->collection(function ($collection) use ($answerTable) {
            $this->answeredCounts = $this->answeredCounts($answerTable, $collection);
            return $collection;
        });

        $columnKey = function ($name) use ($eventTable) {
            return CustomColumn::getEloquent($name, $eventTable)->getQueryKey();
        };

        // no manual esc_html here: the grid column escapes values itself by default
        $grid->column($columnKey('title'), exmtrans('safety.col_title'))->sortable();
        $grid->column($columnKey('trigger_type'), exmtrans('safety.col_trigger_type'))->sortable()->display(function ($value) {
            return exmtrans('safety.trigger_type_' . $value);
        });
        $grid->column($columnKey('event_status'), exmtrans('safety.col_event_status'))->sortable()->display(function ($value) {
            $class = $value === SafetyCheckDefine::EVENT_CLOSED ? 'default' : 'success';
            return '<span class="label label-' . $class . '">' . esc_html(exmtrans('safety.event_status_' . $value)) . '</span>';
        })->escape(false);
        $grid->column($columnKey('triggered_at'), exmtrans('safety.col_triggered_at'))->sortable();
        $grid->column('sent_count', exmtrans('safety.col_sent_count'))->display(function () {
            return (int) $this->getValue('sent_count');
        });
        $grid->column('target_count', exmtrans('safety.col_target_count'))->display(function () {
            return (int) $this->getValue('target_count');
        });
        $controller = $this;
        $grid->column('answered_count', exmtrans('safety.col_answered_count'))->display(function () use ($controller) {
            return $controller->getAnsweredCount($this->id);
        });

        // events are created from the send form above the grid, never edited in place
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->disableRowSelector();
        // (intSetting: an emptied admin-UI field is stored as 0 -> Define default 20)
        $grid->paginate(SafetyCheckDefine::intSetting('safety_check_index_limit'));

        $grid->actions(function (Grid\Displayers\Actions $actions) use ($controller) {
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();

            // a closed event can be neither resent (blocked in resend()) nor re-closed
            if ($actions->row->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED) {
                return;
            }

            $id = $actions->getKey();
            $actions->append($controller->rowActionHtml(
                admin_urls('safety_check', $id, 'resend'),
                'safety-resend-' . $id,
                exmtrans('safety.button_resend'),
                exmtrans('safety.confirm_resend')
            ));
            $actions->append($controller->rowActionHtml(
                admin_urls('safety_check', $id, 'close'),
                'safety-close-' . $id,
                exmtrans('safety.button_close'),
                exmtrans('safety.confirm_close')
            ));
        });

        $grid->filter(function ($filter) use ($columnKey) {
            $filter->disableIdFilter();
            $filter->like($columnKey('title'), exmtrans('safety.col_title'));
            $filter->equal($columnKey('trigger_type'), exmtrans('safety.col_trigger_type'))->select([
                SafetyCheckDefine::TRIGGER_MANUAL   => exmtrans('safety.trigger_type_manual'),
                SafetyCheckDefine::TRIGGER_DRILL    => exmtrans('safety.trigger_type_drill'),
                SafetyCheckDefine::TRIGGER_JMA_AUTO => exmtrans('safety.trigger_type_jma_auto'),
            ]);
            $filter->equal($columnKey('event_status'), exmtrans('safety.col_event_status'))->select([
                SafetyCheckDefine::EVENT_OPEN   => exmtrans('safety.event_status_open'),
                SafetyCheckDefine::EVENT_CLOSED => exmtrans('safety.event_status_closed'),
            ]);
        });

        Admin::script($this->rowActionScript());

        return $grid;
    }

    /** Answered count for one listed event (populated per page by the grid's collection callback). */
    public function getAnsweredCount($eventId): int
    {
        return (int) ($this->answeredCounts[$eventId] ?? 0);
    }

    /**
     * A grid row action rendered as a small text button (same style as the other
     * list screens), submitting a POST form (resend/close are POST routes) after
     * a swal confirm popup (see rowActionScript).
     */
    public function rowActionHtml(string $url, string $formId, string $label, string $confirm): string
    {
        return sprintf(
            '<form id="%1$s" method="POST" action="%2$s" style="display:none;">%3$s</form>'
            . '<a href="javascript:void(0);" class="btn btn-sm btn-default" style="margin-right:5px;"'
            . ' data-safety-submit="%1$s" data-safety-title="%4$s" data-safety-text="%5$s">%4$s</a>',
            esc_html($formId),
            esc_html($url),
            csrf_field(),
            esc_html($label),
            esc_html($confirm)
        );
    }

    /**
     * Confirm popup for the row action buttons: the same swal dialog the other
     * screens use (OperationButton etc.), submitting the row's hidden form on OK.
     */
    protected function rowActionScript(): string
    {
        $confirm = trans('admin.confirm');
        $cancel = trans('admin.cancel');
        return <<<EOT
$('a[data-safety-submit]').off('click.safety').on('click.safety', function () {
    var formId = $(this).data('safety-submit');
    swal({
        title: $(this).data('safety-title'),
        text: $(this).data('safety-text'),
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DD6B55',
        confirmButtonText: '$confirm',
        cancelButtonText: '$cancel'
    }).then(function (result) {
        if (result.value) {
            document.getElementById(formId).submit();
        }
    });
});
EOT;
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
