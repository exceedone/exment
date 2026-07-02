<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Feature 1 (part A):
 * One screen that lists ALL un-actioned workflow tasks of the current login
 * user, across every workflow-enabled table. Also reachable from the navbar
 * icon (WorkflowTaskNav), which shows an unseen-count badge like the bell.
 */
class WorkflowTaskController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('workflow_task.header'), exmtrans('workflow_task.header'), exmtrans('workflow_task.description'), 'fa-tasks');
    }

    /**
     * Index interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $perPageName = 'per_page';
        $perPageOptions = [10, 20, 50, 100];
        $perPage = (int)$request->get($perPageName, 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        $page = max(1, (int)$request->get('page', 1));

        // tasks already sorted (newest first), each annotated with a "seen" flag
        $rows = (new WorkflowTaskService())->getTasksWithSeen();

        $total = $rows->count();
        $unseenTotal = $rows->filter(function ($row) {
            return !$row['seen'];
        })->count();
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => $request->url(),
        ]);
        $paginator->appends($request->except('page'));

        return $this->AdminContent($content)->body(view('exment::workflow_task.index', [
            'rows' => $items,
            'paginator' => $paginator,
            'total' => $total,
            'unseenTotal' => $unseenTotal,
            'perPageName' => $perPageName,
            'perPageOptions' => $perPageOptions,
            'perPage' => $perPage,
        ]));
    }

    /**
     * Mark a single task as seen and redirect to the target record.
     * (mirrors NotifyNavbarController::redirectTargetData)
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function read(Request $request)
    {
        $key = $request->get('key');
        $service = new WorkflowTaskService();

        if (!is_nullorempty($key)) {
            $service->markSeen([$key]);
        }

        // task_key format: "{customTableId}:{morphId}:{suffix}" -> redirect to the record
        $url = admin_url('workflow_task');
        if (!is_nullorempty($key)) {
            $parts = explode(':', $key);
            if (count($parts) >= 2) {
                $custom_table = CustomTable::getEloquent($parts[0]);
                if (isset($custom_table)) {
                    $custom_value = $custom_table->getValueModel($parts[1]);
                    if (isset($custom_value)) {
                        $url = $custom_value->getUrl();
                    }
                }
            }
        }

        return redirect($url);
    }

    /**
     * Mark every currently-pending task as seen.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function readAll(Request $request)
    {
        (new WorkflowTaskService())->markAllSeen();
        admin_toastr(exmtrans('workflow_task.message.mark_all_seen_succeeded'));
        return redirect(admin_url('workflow_task'));
    }
}
