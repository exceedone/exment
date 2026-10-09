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

class SafetyCheckController extends AdminControllerBase
{
    /** @var array<int|string, int> */
    protected $answeredCounts = [];

    public const SEND_LOCK_SECONDS = 600;

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
        $content = $this->AdminContent($content);
        if (!$this->isInstalled()) {
            return $content->withError(exmtrans('safety.menu_title'), exmtrans('safety.message_not_installed'));
        }
        return $content
            ->body(view('exment::safety.index'))
            ->body($this->grid());
    }

    protected function isInstalled(): bool
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$eventTable || !$answerTable) {
            return false;
        }
        foreach (['title', 'trigger_type', 'event_status', 'triggered_at'] as $name) {
            if (!CustomColumn::getEloquent($name, $eventTable)) {
                return false;
            }
        }
        foreach (['event', 'answer_status'] as $name) {
            if (!CustomColumn::getEloquent($name, $answerTable)) {
                return false;
            }
        }
        return true;
    }

    protected function notInstalledResponse()
    {
        admin_toastr(exmtrans('safety.message_not_installed'), 'error');
        return redirect(admin_url('safety_check'));
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);

        $classname = getModelName(SafetyCheckDefine::TABLE_EVENT);
        $grid = new Grid(new $classname());

        $grid->model()->orderBy('id', 'desc');

        $grid->model()->collection(function ($collection) use ($answerTable) {
            $this->answeredCounts = $this->answeredCounts($answerTable, $collection);
            return $collection;
        });

        $columnKey = function ($name) use ($eventTable) {
            return CustomColumn::getEloquent($name, $eventTable)->getQueryKey();
        };

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

        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->disableRowSelector();

        $grid->actions(function (Grid\Displayers\Actions $actions) use ($controller) {
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();

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

    public function getAnsweredCount($eventId): int
    {
        return (int) ($this->answeredCounts[$eventId] ?? 0);
    }

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
        if (!$this->isInstalled()) {
            return $this->notInstalledResponse();
        }

        $title = trim((string) $request->get('title'));
        if ($title === '') {
            admin_toastr(exmtrans('safety.message_title_required'), 'error');
            return redirect(admin_url('safety_check'));
        }

        $triggerType = $request->get('trigger_type');
        if (!in_array($triggerType, [SafetyCheckDefine::TRIGGER_MANUAL, SafetyCheckDefine::TRIGGER_DRILL], true)) {
            $triggerType = SafetyCheckDefine::TRIGGER_MANUAL;
        }


        $lockKey = static::sendLockKey();
        if (!\Cache::add($lockKey, 1, static::SEND_LOCK_SECONDS)) {
            admin_toastr(exmtrans('safety.message_send_in_progress'), 'error');
            return redirect(admin_url('safety_check'));
        }

        try {
            $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
            $event = $eventTable->getValueModel();
            $event->setValue([
                'title' => $title,
                'trigger_type' => $triggerType,
                'event_status' => SafetyCheckDefine::EVENT_OPEN,
                'triggered_at' => now()->format('Y-m-d H:i:s'),
            ])->save();

            $this->broadcast($event);
        } finally {
            \Cache::forget($lockKey);
        }

        admin_toastr(exmtrans('safety.message_send_succeeded'));
        return redirect(admin_url('safety_check'));
    }

    public static function sendLockKey(): string
    {
        return 'safety_check_send.' . \Exment::getUserId();
    }

    protected function broadcast(CustomValue $event, bool $onlyUnanswered = false): void
    {
        \Exment::setTimeLimitLong();
        SafetyCheckSender::send($event, $onlyUnanswered);
    }

    public function resend(Request $request, $id)
    {
        if (!$this->isInstalled()) {
            return $this->notInstalledResponse();
        }

        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $event = $this->findEventOrFail($eventTable, $id);

        if ($event->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED) {
            admin_toastr(exmtrans('safety.resend_closed_error'), 'error');
            return redirect(admin_url('safety_check'));
        }

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

        $this->broadcast($event, true);

        admin_toastr(exmtrans('safety.message_resend_succeeded'));
        return redirect(admin_url('safety_check'));
    }

    public function close(Request $request, $id)
    {
        if (!$this->isInstalled()) {
            return $this->notInstalledResponse();
        }

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
