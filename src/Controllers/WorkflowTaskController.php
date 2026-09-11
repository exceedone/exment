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
    /**
     * Most task keys one "mark the selected rows as seen" request may carry.
     * The list offers 100 checkboxes at most, so this is ten times what a click can produce:
     * it only stops a hand made body - bounded by post_max_size alone - from becoming a huge
     * array of strings in memory.
     */
    const MAX_CHECK_KEYS = 1000;

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
        // only the values the select box offers: "?per_page=1000000" comes straight from the
        // query string and decides how many rows the service is asked to read
        if (!in_array($perPage, $perPageOptions, true)) {
            $perPage = 20;
        }
        $page = max(1, (int)$request->get('page', 1));

        // The filter reaches the service, not the rows: every count and every read goes through
        // the same conditions, so the paginator can never disagree with what it paginates.
        // normalizeFilter() is what stands between the query string and the query builder.
        $filter = WorkflowTaskService::normalizeFilter($request->all());

        // getPage() reads id + updated_at of the candidates and builds a model only for the rows
        // of this page; both totals are COUNTs. A user with thousands of pending tasks now costs
        // the same as one with twenty.
        $service = new WorkflowTaskService($filter);
        $result = $service->getPage($page, $perPage);

        $items = $result['rows'];
        $total = $result['total'];
        // getPage() clamps the page to the last one that exists, so the paginator must use the
        // page that was actually served, not the one that was asked for
        $page = $result['page'];
        $unseenTotal = $service->countUnseen();

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
            // the normalized filter goes back to the form, so what the screen shows and what
            // the query ran are always the same thing
            'filter' => $service->filter(),
            'isFiltered' => $service->isFiltered(),
            'tableOptions' => WorkflowTaskService::tableOptions(),
            // the batch menu of notify_navbar, built the same way: an array of swal buttons
            'menulist' => $this->getMenuList($service->filter()),
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
        $url = admin_url('workflow_task');

        // The key arrives raw from the query string. Validate it BEFORE anything else.
        // parseTaskKey() is the single source of truth for the shape (it also rejects
        // "?key[]=x", an array, which would otherwise blow up as a TypeError).
        $parsed = WorkflowTaskService::parseTaskKey($key);
        if (is_nullorempty($parsed)) {
            return redirect()->to($url);
        }

        // Resolve the record BEFORE writing. getValueModel() goes through the permission global
        // scope (CustomValueModelScope), so a key pointing at a record this user cannot access
        // resolves to null and nothing is stored. Marking first would let any logged-in user
        // fill workflow_task_reads with rows for records they have nothing to do with.
        $custom_table = CustomTable::getEloquent($parsed[0]);
        if (is_nullorempty($custom_table)) {
            return redirect()->to($url);
        }

        $custom_value = $custom_table->getValueModel($parsed[1]);
        if (is_nullorempty($custom_value)) {
            return redirect()->to($url);
        }

        (new WorkflowTaskService())->markSeen([$key]);

        return redirect()->to($custom_value->getUrl());
    }

    /**
     * Mark every currently-pending task as seen.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\RedirectResponse
     */
    public function readAll(Request $request)
    {
        // the filter comes along in the url: the button sits under a filtered list, so it
        // marks that list. Without a filter this is every pending task, exactly as before.
        (new WorkflowTaskService(WorkflowTaskService::normalizeFilter($request->all())))->markAllSeen();

        return $this->batchResponse($request, exmtrans('workflow_task.message.mark_all_seen_succeeded'));
    }

    /**
     * Drop the seen marks again - the mirror of readAll(), like notify_navbar's "unread all".
     *
     * Nothing is deleted and no record is touched: only this user's read marks go, so the
     * navbar badge comes back and the list itself is unchanged.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\RedirectResponse
     */
    public function unreadAll(Request $request)
    {
        (new WorkflowTaskService(WorkflowTaskService::normalizeFilter($request->all())))->markAllUnseen();

        return $this->batchResponse($request, exmtrans('workflow_task.message.mark_all_unseen_succeeded'));
    }

    /**
     * Mark the tasks selected with the checkboxes as seen.
     * The counterpart of notify_navbar/rowcheck, and the reason the checkbox is drawn on every
     * row and not only on the ones that may be deleted.
     *
     * The keys are not trusted here: markSeenSelected() keeps only the ones that really are
     * un-actioned, still unseen tasks of this user, which is the same permission scoped list
     * the screen itself is built from.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response Response for ajax json
     */
    public function rowCheck(Request $request)
    {
        // "keys" is a comma separated list of task keys, the same strings the checkboxes carry.
        // Anything malformed is dropped by markSeenSelected(), it never aborts the batch.
        //
        // is_string() first: "?keys[]=1:1" hands over an ARRAY, and casting an array to string
        // is a PHP warning - which Laravel raises as an ErrorException, so a malformed request
        // would answer 500 instead of "nothing to update". read() refuses the same shape through
        // parseTaskKey(). Then the list is cut, so the size of the answer is not decided by the
        // size of the body.
        $raw = $request->get('keys');
        $keys = is_string($raw)
            ? collect(explode(',', $raw, self::MAX_CHECK_KEYS + 1))
                ->take(self::MAX_CHECK_KEYS)
                ->map(function ($key) {
                    return trim($key);
                })
                ->filter()
                ->all()
            : [];

        $count = (new WorkflowTaskService())->markSeenSelected($keys);

        if ($count === 0) {
            // already seen, gone, or never this user's task - the same answer notify_navbar
            // gives when every selected row is read already
            return getAjaxResponse([
                'result' => false,
                'toastr' => exmtrans('workflow_task.message.check_notfound'),
            ]);
        }

        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('workflow_task.message.check_succeeded'),
        ]);
    }

    /**
     * Answer a batch button.
     *
     * The menu posts by ajax and expects the json CommonEvent.CallbackExmentAjax reads (toastr
     * + pjax reload). A plain form post - no javascript, or a bookmarked url - still gets the
     * redirect it always got, so the feature does not depend on the dropdown.
     *
     * @param Request $request
     * @param string $message
     * @return \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\RedirectResponse
     */
    protected function batchResponse(Request $request, string $message)
    {
        if ($request->ajax()) {
            return getAjaxResponse([
                'result' => true,
                'toastr' => $message,
            ]);
        }

        admin_toastr($message);

        return redirect(admin_url('workflow_task'));
    }

    /**
     * The batch menu of the list screen - the same dropdown notify_navbar has.
     *
     * "Delete all" is deliberately NOT here. On notify_navbar a row is a notification, so
     * deleting all of them loses nothing. Here every row is a real record of a real table, and
     * one click would delete every record the user still has to act on. Deleting stays where
     * it names what it removes: the row button and the multi-select.
     *
     * @param array<string, mixed> $filter normalized filter of the list being shown
     * @return array<int, array<string, string>>
     */
    protected function getMenuList(array $filter): array
    {
        // the filter travels in the url, so the buttons act on the list the user is looking at
        $query = array_filter($filter, function ($value) {
            return !is_null($value);
        });
        $suffix = empty($query) ? '' : '?' . http_build_query($query);

        return [
            [
                'url' => admin_url('workflow_task/readAll') . $suffix,
                'label' => exmtrans('workflow_task.mark_all_seen'),
                'title' => exmtrans('workflow_task.batch_all'),
                'text' => exmtrans('workflow_task.confirm_text.mark_all_seen'),
                'method' => 'post',
                'confirm' => trans('admin.confirm'),
                'cancel' => trans('admin.cancel'),
            ],
            [
                'url' => admin_url('workflow_task/unreadAll') . $suffix,
                'label' => exmtrans('workflow_task.mark_all_unseen'),
                'title' => exmtrans('workflow_task.batch_all'),
                'text' => exmtrans('workflow_task.confirm_text.mark_all_unseen'),
                'method' => 'post',
                'confirm' => trans('admin.confirm'),
                'cancel' => trans('admin.cancel'),
            ],
        ];
    }
}
