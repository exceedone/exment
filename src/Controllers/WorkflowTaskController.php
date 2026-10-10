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

    /**
     * The conditions of the filter panel. The free word is not one of them: it has its own box
     * next to the filter button, like the free-word search of the data grid (DefaultGrid).
     */
    const PANEL_FILTER_KEYS = ['custom_table_id', 'seen', 'status', 'from', 'to'];

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
        // taken before any row is read, and sent back by the delete buttons: an action executed
        // on a record after this moment is one the rows below may not show (see rowDelete())
        $listedAt = \Carbon\Carbon::now()->format('Y-m-d H:i:s');

        $perPageName = 'per_page';
        // the page sizes a grid offers (Grid::$perPages)
        $perPageOptions = [10, 20, 30, 50, 100];
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
        // the tasks taken off the list are on none of the others: the list says how many there
        // are, and leads to them (WorkflowTaskService::countRemoved())
        $removedTotal = $service->countRemoved();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => $request->url(),
        ]);
        $paginator->appends($request->except('page'));

        // the normalized filter goes back to the forms, so what the screen shows and what the
        // query ran are always the same thing
        $filter = $service->filter();
        // did the user pick the order? The filter always carries one (the default is oldest
        // first), but the header shows a direction only once it was picked, like a grid does
        $sorted = in_array($request->get('sort'), ['asc', 'desc'], true);

        // The free-word box and the filter panel are two forms, as on the data grid. A search in
        // one keeps what the other one set, and the order and page size picked on the list -
        // the grid keeps them in the query string of its form actions, this screen sends them
        // as hidden fields, which a form without pjax does not drop either.
        $notNull = function ($value) {
            return !is_null($value);
        };
        $keep = array_filter(['sort' => $sorted ? $filter['sort'] : null, $perPageName => $perPage], $notNull);
        $panel = array_filter(array_intersect_key($filter, array_flip(self::PANEL_FILTER_KEYS)), $notNull);

        // 削除済み, the tasks taken off the list, is not a condition of the panel but a list of its
        // own, where the data grid keeps its deleted data: the 削除済データ scope of the filter
        // button (Grid\Tools\FilterButton), which no すべて (all) of a grid filter ever holds.
        // Every search, リセット included, stays in it; ▼ > キャンセル leaves it.
        $removedView = $filter['seen'] === WorkflowTaskService::SEEN_REMOVED;
        $scope = $removedView ? ['seen' => WorkflowTaskService::SEEN_REMOVED] : [];
        $conditions = array_diff_key($panel, $scope);
        // links, not forms: the conditions of the list come along, the page and _pjax do not
        $removedUrl = $request->fullUrlWithQuery(['seen' => WorkflowTaskService::SEEN_REMOVED, 'page' => null, '_pjax' => null]);
        $cancelUrl = $request->fullUrlWithQuery(['seen' => null, 'page' => null, '_pjax' => null]);

        return $this->AdminContent($content)->body(view('exment::workflow_task.index', [
            'rows' => $items,
            'paginator' => $paginator,
            'total' => $total,
            'unseenTotal' => $unseenTotal,
            'removedTotal' => $removedTotal,
            'removedView' => $removedView,
            'removedUrl' => $removedUrl,
            'cancelUrl' => $cancelUrl,
            'perPageName' => $perPageName,
            'perPageOptions' => $perPageOptions,
            'perPage' => $perPage,
            'filter' => $filter,
            // the 削除済み list is a list, not a condition: alone, it filters nothing
            'isFiltered' => $removedView ? (!empty($conditions) || !is_null($filter['q'])) : $service->isFiltered(),
            'sorted' => $sorted,
            // the panel opens on its own conditions, like a grid's; the free word and the
            // 削除済み list leave it alone
            'expandFilter' => !empty($conditions),
            'quickSearchKeep' => $panel + $keep,
            // the 削除済み list draws no 状態 choice, so the panel form carries it itself
            'panelKeep' => array_filter(['q' => $filter['q']], $notNull) + $keep + $scope,
            // the reset button of a grid filter (Grid\Filter::urlWithoutFilters()): drops the
            // conditions of the panel and the page, keeps the free word, the order, the page
            // size and the list (a grid keeps its scope). _pjax is what jquery.pjax adds to its
            // own requests, not something to link to.
            'resetUrl' => $request->fullUrlWithoutQuery(array_merge(array_diff(self::PANEL_FILTER_KEYS, array_keys($scope)), ['page', '_pjax'])),
            // the date pickers of the 更新日時 filter, set up like the data grid sets up its own
            // (Grid\Filter\Between::setupDatetime()): the calendar in the language of the screen,
            // not of the OS, and dates written as yyyy-mm-dd - the only shape normalizeFilter() reads
            'dateOptions' => ['format' => 'YYYY-MM-DD', 'locale' => config('app.locale')],
            'tableOptions' => WorkflowTaskService::tableOptions(),
            'statusOptions' => WorkflowTaskService::statusOptions(),
            // the batch menu of notify_navbar, built the same way: an array of swal buttons
            'menulist' => $this->getMenuList($service->filter(), $service->isFiltered()),
            'listedAt' => $listedAt,
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

        // Only a record of a workflow table this user works in: the tables the list itself reads.
        // The permission scope below is not enough on its own - it does not filter every table (a
        // document answers to anybody), and the redirect tells where the record lives: for a
        // document, the address of its file. Found in review: any logged-in user could collect the
        // file address of every document this way, records they may not see included.
        // @phpstan-ignore-next-line
        if (!array_key_exists($parsed[0], WorkflowTaskService::tableOptions())) {
            return redirect()->to($url);
        }

        // Resolve the record BEFORE writing. getValueModel() goes through the permission global
        // scope (CustomValueModelScope), so a key pointing at a record this user cannot access
        // resolves to null and nothing is stored.
        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent($parsed[0]);
        if (is_nullorempty($custom_table)) {
            return redirect()->to($url);
        }

        /** @var \Exceedone\Exment\Model\CustomValue $custom_value */
        $custom_value = $custom_table->getValueModel($parsed[1]);
        if (is_nullorempty($custom_value)) {
            return redirect()->to($url);
        }

        // Marked only while it is an unread task of this user (markSeenSelected() checks the key
        // against their own list): a record somebody acted on since the list was drawn still
        // opens, and leaves no mark on a record that is nobody's task of theirs.
        (new WorkflowTaskService())->markSeenSelected([$key]);

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
        $service = new WorkflowTaskService(WorkflowTaskService::normalizeFilter($request->all()));
        $service->markAllSeen();

        // "all tasks" would be a claim about the lists the filter hid, which were not touched
        return $this->batchResponse($request, exmtrans($service->isFiltered()
            ? 'workflow_task.message.mark_all_seen_filtered_succeeded'
            : 'workflow_task.message.mark_all_seen_succeeded'));
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
        $service = new WorkflowTaskService(WorkflowTaskService::normalizeFilter($request->all()));
        $service->markAllUnseen();

        return $this->batchResponse($request, exmtrans($service->isFiltered()
            ? 'workflow_task.message.mark_all_unseen_filtered_succeeded'
            : 'workflow_task.message.mark_all_unseen_succeeded'));
    }

    /**
     * Mark the tasks selected with the checkboxes as seen.
     * The counterpart of notify_navbar/rowcheck.
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
        $count = (new WorkflowTaskService())->markSeenSelected($this->requestKeys($request));

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
     * Take the selected tasks off the current user's list - the "delete" of this screen, for one
     * row (its trash button) or for the ticked ones (the batch menu).
     *
     * Only the user's own mark is written (WorkflowTaskService::hideSelected()): a row here is a
     * real record that other users work on, so it is never deleted from this screen. The keys
     * are checked the same way rowCheck() checks them.
     *
     * "listed_at" is the moment index() drew the list the button sits on. A record somebody acted
     * on after it has a task the user has not seen yet, and is left on the list.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response Response for ajax json
     */
    public function rowDelete(Request $request)
    {
        $keys = $this->requestKeys($request);
        $count = (new WorkflowTaskService())->hideSelected($keys, $request->get('listed_at'));

        if ($count === 0) {
            // already off the list, acted on in the meantime, or never this user's task. The
            // request comes from a confirm dialog, which only a swal answer closes.
            return getAjaxResponse([
                'result' => false,
                'swaltext' => exmtrans('workflow_task.message.delete_notfound'),
            ]);
        }

        // The dialog announced the whole selection. What stayed - acted on since the list was
        // drawn, or already gone - is still on the reloaded list, and the answer says so rather
        // than leaving the user to count rows.
        $left = count(array_unique($keys)) - $count;

        return getAjaxResponse([
            'result' => true,
            'toastr' => $left > 0
                ? exmtrans('workflow_task.message.delete_partial', $count, $left)
                : exmtrans('workflow_task.message.delete_succeeded'),
        ]);
    }

    /**
     * Put the selected tasks back on the current user's list - the way back from rowDelete(), for
     * the tasks the 削除済み filter shows (WorkflowTaskService::restoreSelected()). Nothing of the
     * record is touched either way, so there is no confirm dialog, like marking as seen.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response Response for ajax json
     */
    public function rowRestore(Request $request)
    {
        $count = (new WorkflowTaskService())->restoreSelected($this->requestKeys($request));

        if ($count === 0) {
            // already back, acted on in the meantime, or never this user's task
            return getAjaxResponse([
                'result' => false,
                'toastr' => exmtrans('workflow_task.message.restore_notfound'),
            ]);
        }

        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('workflow_task.message.restore_succeeded', $count),
        ]);
    }

    /**
     * The task keys of a batch request.
     *
     * "keys" is a comma separated list of task keys, the same strings the checkboxes carry.
     * Anything malformed is dropped by the service, it never aborts the batch.
     *
     * is_string() first: "?keys[]=1:1" hands over an ARRAY, and casting an array to string is a
     * PHP warning - which Laravel raises as an ErrorException, so a malformed request would answer
     * 500 instead of "nothing to update". read() refuses the same shape through parseTaskKey().
     * Then the list is cut, so the size of the answer is not decided by the size of the body.
     *
     * @param Request $request
     * @return array<string>
     */
    protected function requestKeys(Request $request): array
    {
        $raw = $request->get('keys');

        return is_string($raw)
            ? collect(explode(',', $raw, self::MAX_CHECK_KEYS + 1))
                ->take(self::MAX_CHECK_KEYS)
                ->map(function ($key) {
                    return trim($key);
                })
                ->filter()
                ->values()
                ->all()
            : [];
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
     * deleting all of them loses nothing. Here a row is something the user still has to act on:
     * one click would empty the whole list, and only a new action on a record brings its task
     * back. Taking tasks off the list stays where it names what it removes: the row button and
     * the multi-select.
     *
     * Under a filter both entries act on the filtered list only, and their dialogs say so: a
     * dialog promising "all tasks" over a list narrowed to one table is how a user ends up
     * believing the other tables were marked too.
     *
     * @param array<string, mixed> $filter normalized filter of the list being shown
     * @param bool $filtered is anything filtered at all (WorkflowTaskService::isFiltered())
     * @return array<int, array<string, string>>
     */
    protected function getMenuList(array $filter, bool $filtered = false): array
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
                'text' => exmtrans($filtered ? 'workflow_task.confirm_text.mark_all_seen_filtered' : 'workflow_task.confirm_text.mark_all_seen'),
                'method' => 'post',
                'confirm' => trans('admin.confirm'),
                'cancel' => trans('admin.cancel'),
            ],
            [
                'url' => admin_url('workflow_task/unreadAll') . $suffix,
                'label' => exmtrans('workflow_task.mark_all_unseen'),
                'title' => exmtrans('workflow_task.batch_all'),
                'text' => exmtrans($filtered ? 'workflow_task.confirm_text.mark_all_unseen_filtered' : 'workflow_task.confirm_text.mark_all_unseen'),
                'method' => 'post',
                'confirm' => trans('admin.confirm'),
                'cancel' => trans('admin.cancel'),
            ],
        ];
    }
}
