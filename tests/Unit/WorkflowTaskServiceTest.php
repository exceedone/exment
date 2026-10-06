<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\WorkflowTaskController;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTaskRead;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\RefreshDataService;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\Request;

/**
 * Behaviour tests for the "un-actioned workflow task" feature.
 *
 * REQUIRES the Exment test dataset:
 *     php artisan exment:inittest        <-- WARNING: this resets ALL data in the database
 *
 * Every test runs inside a transaction (DatabaseTransactions), so nothing is left behind.
 * Tests that need real workflow data mark themselves skipped when the dataset has none,
 * so the file is safe to run on any environment.
 */
class WorkflowTaskServiceTest extends UnitTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /**
     * @param string $loginId the test user to log in as (user1 unless a test needs another one)
     * @return void
     */
    protected function init(string $loginId = TestDefine::TESTDATA_USER_LOGINID_USER1)
    {
        if (!\Schema::hasTable('workflow_task_reads')) {
            $this->markTestSkipped('table workflow_task_reads is missing - run "php artisan migrate" first');
        }

        $login_user = LoginUser::find($loginId);
        if (!isset($login_user)) {
            // the Exment test dataset is not installed on this database
            $this->markTestSkipped('run "php artisan exment:inittest" first (WARNING: it resets all data)');
        }

        $this->initAllTest();
        $this->be($login_user);
    }

    /**
     * markSeen() must be idempotent: calling it twice for the same key must not create a
     * second row. It does one SELECT and one bulk INSERT, so the duplicate has to be filtered
     * out before the INSERT - the unique index is only the last line of defence.
     *
     * @return void
     */
    public function testMarkSeenIsIdempotent()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $key = WorkflowTaskService::taskKey(999999, 888888);

        $service->markSeen([$key]);
        $service->markSeen([$key]);
        $service->markSeen([$key, $key]);

        $parsed = WorkflowTaskService::parseTaskKey($key);
        $count = WorkflowTaskRead::where('target_user_id', \Exment::getUserId())
            ->where('custom_table_id', $parsed[0])
            ->where('morph_id', $parsed[1])
            ->count();

        $this->assertSame(1, $count, 'markSeen() must not insert duplicated rows');
    }

    /**
     * Empty / null keys must be ignored instead of inserting a junk row.
     *
     * @return void
     */
    public function testMarkSeenSkipsEmptyKeys()
    {
        $this->init();

        $before = WorkflowTaskRead::where('target_user_id', \Exment::getUserId())->count();

        (new WorkflowTaskService())->markSeen(['', null]);

        $after = WorkflowTaskRead::where('target_user_id', \Exment::getUserId())->count();

        $this->assertSame($before, $after, 'empty task keys must not be stored');
    }

    /**
     * The "seen" state must be per user: marking a task seen as user A must not
     * make it seen for user B.
     *
     * @return void
     */
    public function testSeenStateIsPerUser()
    {
        $this->init();

        $key = WorkflowTaskService::taskKey(999999, 888888);
        $userA = \Exment::getUserId();

        (new WorkflowTaskService())->markSeen([$key]);

        $this->assertContains($key, (new WorkflowTaskService())->seenKeys());

        // switch user
        $other = LoginUser::where('id', '<>', TestDefine::TESTDATA_USER_LOGINID_USER1)->first();
        if (!isset($other)) {
            $this->markTestSkipped('needs a second login user in the test dataset');
        }
        $this->be($other);

        $this->assertNotSame($userA, \Exment::getUserId());
        $this->assertNotContains($key, (new WorkflowTaskService())->seenKeys(), 'seen state leaked to another user');
    }

    /**
     * getTasksWithSeen() must flag exactly the rows that are in workflow_task_reads.
     *
     * @return void
     */
    public function testGetTasksWithSeenFlagsStoredKeys()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $tasks = $service->getTasksWithSeen();

        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $unseenBefore = $service->countUnseen();

        $target = $tasks->firstWhere('seen', false);
        if (is_null($target)) {
            $this->markTestSkipped('every task of this user is already marked as seen');
        }

        $service->markSeen([$target['task_key']]);

        $withSeen = $service->getTasksWithSeen();
        $seenRow = $withSeen->firstWhere('task_key', $target['task_key']);

        $this->assertTrue($seenRow['seen'], 'the marked task must be reported as seen');
        $this->assertSame($unseenBefore - 1, $service->countUnseen());
    }

    /**
     * markAllSeen() must clear the badge completely.
     *
     * @return void
     */
    public function testMarkAllSeenClearsUnseenCount()
    {
        $this->init();

        $service = new WorkflowTaskService();

        if ($service->getTasks()->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $service->markAllSeen();

        $this->assertSame(0, $service->countUnseen(), 'markAllSeen() must leave no unseen task');
    }

    /**
     * The batch "mark as seen" gets its keys from the browser, so markSeenSelected() must keep
     * only the ones that really are this user's un-actioned, still unseen tasks. Without that
     * intersection any logged-in user could fill workflow_task_reads with rows for records they
     * cannot even see - the hole workflow_task/read closes by resolving the record first.
     *
     * @return void
     */
    public function testMarkSeenSelectedOnlyAcceptsMyOwnPendingTasks()
    {
        $this->init();

        if ((new WorkflowTaskService())->getTasks()->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        // start from a known state
        (new WorkflowTaskService())->markAllUnseen();

        $userId = \Exment::getUserId();
        $before = WorkflowTaskRead::where('target_user_id', $userId)->count();

        // keys nobody may store: a table that does not exist, a record that does not exist,
        // and strings that are not keys at all
        $junk = [
            WorkflowTaskService::taskKey(4294967295, 1),
            WorkflowTaskService::taskKey(1, 99999999),
            'not-a-key',
            '',
        ];

        $this->assertSame(0, (new WorkflowTaskService())->markSeenSelected($junk));
        $this->assertSame(
            $before,
            WorkflowTaskRead::where('target_user_id', $userId)->count(),
            'a key that is not one of my pending tasks must not be stored'
        );

        // a real one, mixed in with the junk, still goes through
        $real = (new WorkflowTaskService())->getTasksWithSeen()->first()['task_key'];
        $marked = (new WorkflowTaskService())->markSeenSelected(array_merge($junk, [$real]));

        $this->assertSame(1, $marked, 'junk must be dropped, not abort the batch');
        $this->assertSame(
            $before + 1,
            WorkflowTaskRead::where('target_user_id', $userId)->count(),
            'exactly one mark must be stored'
        );

        // asking again is "nothing to update" - the answer notify_navbar gives for read rows
        $this->assertSame(0, (new WorkflowTaskService())->markSeenSelected([$real]));
    }

    /**
     * An empty selection must be answered without touching the database.
     *
     * rowCheck is a POST every logged-in user can call, and pendingKeys() under it costs one
     * indexed query per workflow table (measured on this installation: 46 queries, ~300 ms).
     * Paying that just to answer "you selected nothing" is work anybody could ask for in a loop.
     *
     * @return void
     */
    public function testMarkSeenSelectedAnswersAnEmptySelectionWithoutAQuery()
    {
        $this->init();

        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $marked = (new WorkflowTaskService())->markSeenSelected([]);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame(0, $marked);
        $this->assertCount(0, $queries, 'an empty selection must not query the database');
    }

    /**
     * A malformed request must be answered, not crash.
     *
     * "?keys[]=1:1" arrives as an array, and casting an array to string is a PHP warning that
     * Laravel raises as an ErrorException - so the plain (string) cast turned a bad request into
     * a 500. read() has always refused that same shape through parseTaskKey().
     *
     * @return void
     */
    public function testRowCheckAnswersAMalformedRequestInsteadOfCrashing()
    {
        $this->init();

        $controller = new WorkflowTaskController();
        $userId = \Exment::getUserId();
        $before = WorkflowTaskRead::where('target_user_id', $userId)->count();

        $inputs = [
            'an array'         => ['1:1', '2:2'],
            'a nested array'   => ['a' => ['b' => 'c']],
            'a number'         => 5,
            'an empty string'  => '',
            'nothing at all'   => null,
        ];

        foreach ($inputs as $label => $keys) {
            $request = Request::create(
                '/workflow_task/rowCheck',
                'POST',
                is_null($keys) ? [] : ['keys' => $keys]
            );

            $response = $controller->rowCheck($request);

            $this->assertSame(400, $response->getStatusCode(), "{$label} must be answered, not accepted");
        }

        $this->assertSame(
            $before,
            WorkflowTaskRead::where('target_user_id', $userId)->count(),
            'a malformed request must not store anything'
        );
    }

    /**
     * The number of keys one request may carry is capped, so the size of the work is decided by
     * the screen and not by the size of the body (post_max_size is 1000M on this machine).
     *
     * @return void
     */
    public function testRowCheckCutsAnAbsurdlyLongKeyList()
    {
        $this->init();

        $flood = [];
        for ($i = 1; $i <= WorkflowTaskController::MAX_CHECK_KEYS * 3; $i++) {
            $flood[] = WorkflowTaskService::taskKey(4294967295, $i);
        }

        $userId = \Exment::getUserId();
        $before = WorkflowTaskRead::where('target_user_id', $userId)->count();

        $response = (new WorkflowTaskController())->rowCheck(
            Request::create('/workflow_task/rowCheck', 'POST', ['keys' => implode(',', $flood)])
        );

        $this->assertSame(400, $response->getStatusCode(), 'keys for a table that does not exist must all be dropped');
        $this->assertSame(
            $before,
            WorkflowTaskRead::where('target_user_id', $userId)->count(),
            'nothing may be stored for them'
        );
    }

    /**
     * markAllUnseen() is the mirror of markAllSeen(): the badge comes back and nothing else
     * changes. It must remove read marks only - no record may be touched - and it must respect
     * the screen filter, exactly like the "mark all as seen" it undoes.
     *
     * @return void
     */
    public function testMarkAllUnseenIsTheMirrorOfMarkAllSeen()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();

        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $total = (new WorkflowTaskService())->countAll();

        (new WorkflowTaskService())->markAllSeen();
        $this->assertSame(0, (new WorkflowTaskService())->countUnseen(), 'precondition: everything is seen');

        $removed = (new WorkflowTaskService())->markAllUnseen();

        $this->assertGreaterThan(0, $removed, 'the marks must actually be removed');
        $this->assertSame($total, (new WorkflowTaskService())->countUnseen(), 'every task must be unseen again');
        $this->assertSame($total, (new WorkflowTaskService())->countAll(), 'no record may have been deleted');

        // the filter is respected: unmarking one table must leave the others marked
        $customTableId = $tasks->first()['custom_table_id'];
        (new WorkflowTaskService())->markAllSeen();

        $oneTable = WorkflowTaskService::normalizeFilter(['custom_table_id' => $customTableId]);
        (new WorkflowTaskService($oneTable))->markAllUnseen();

        $inThatTable = (new WorkflowTaskService($oneTable))->countUnseen();

        $this->assertGreaterThan(0, $inThatTable, 'the filtered table must be unseen again');
        $this->assertSame(
            $inThatTable,
            (new WorkflowTaskService())->countUnseen(),
            'only the filtered table may have been unmarked'
        );
    }

    /**
     * Every row must carry the fields the navbar API and the blade read.
     * A missing key turns into an "Undefined array key" warning at render time only.
     *
     * @return void
     */
    public function testTaskRowShape()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasksWithSeen();

        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $required = [
            'custom_table_id', 'table_view_name', 'icon', 'color', 'morph_id',
            'label', 'url', 'status_name', 'status_tag', 'updated_at',
            'task_key', 'seen',
        ];

        foreach ($tasks as $row) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $row, "task row is missing '{$key}'");
            }
            $this->assertNotNull(
                WorkflowTaskService::parseTaskKey($row['task_key']),
                'a generated task_key must survive parseTaskKey(), or the read link stops working'
            );
        }
    }

    /**
     * A workflow whose period is over starts nothing new, and carries what is underway to the end -
     * exactly as the record page has it.
     *
     * Which workflow a record that has not started would start in is Workflow::getWorkflowByTable():
     * active_flg AND active_start_date AND active_end_date. With every period over there is none,
     * so such a record is no task. A record already in a workflow goes on in it: the record page
     * offers its actions, and the 利用設定 screen says so (「現在進行中のワークフローは、変更前の
     * ワークフローで実行されます」). Found in review: the list dropped the whole table instead, and an
     * approver lost every request still waiting for them. Logged in as the test user who has tasks
     * of both kinds.
     *
     * @return void
     */
    public function testExpiredWorkflowStartsNothingAndCarriesWhatIsUnderway()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);

        $underway = function (array $row) {
            return !is_null(CustomTable::getEloquent($row['custom_table_id'])->getValueModel($row['morph_id'])->workflow_value);
        };
        $before = (new WorkflowTaskService())->getTasks();
        $carried = $before->filter($underway)->values();
        if ($carried->isEmpty() || $carried->count() === $before->count()) {
            $this->markTestSkipped('needs tasks both underway and not started yet for this user');
        }

        // push every workflow out of its active period (rolled back after the test)
        \DB::table(SystemTableName::WORKFLOW_TABLE)->update([
            'active_start_date' => \Carbon\Carbon::today()->subDays(10)->toDateString(),
            'active_end_date'   => \Carbon\Carbon::today()->subDays(5)->toDateString(),
        ]);
        System::clearCache();

        $after = (new WorkflowTaskService())->getTasks()->keyBy('task_key');
        $this->assertSame(
            $carried->pluck('task_key')->sort()->values()->all(),
            $after->keys()->sort()->values()->all(),
            'what is underway stays on the list, what has not started leaves it'
        );
        // ... at the status it is at. Found in review: with no workflow in use today, CustomValue
        // printed the start status of the record's workflow, a request waiting for approval read
        // as a draft - and the status filter found it under a name its row did not show
        foreach ($carried as $row) {
            $this->assertSame($row['status_name'], $after[$row['task_key']]['status_name'], $row['task_key'] . ': the period moved its status');
            $this->assertSame(esc_html($row['status_name']), $after[$row['task_key']]['status_tag'], $row['task_key'] . ': without a workflow in use the record is not locked');
        }
        // ... which is what the record page has to say about each of them
        foreach ($before as $row) {
            $this->assertSame(
                $underway($row),
                $this->recordPageOffers((int)$row['custom_table_id'], (int)$row['morph_id']),
                $row['task_key'] . ': the list and the record page disagree'
            );
        }

        // the status filter still finds a record at a status of the workflow carrying it
        $row = $carried->first();
        $this->assertTrue(
            (new WorkflowTaskService(['status' => $row['status_name']]))->getTasks()->contains('task_key', $row['task_key']),
            'the status filter must find a record carried on by a workflow whose period is over'
        );
    }

    /**
     * The status filter keeps the starts of different workflows apart, as the column does: a record
     * led back to the start is at the start of ITS workflow, one not started yet at the start of the
     * workflow in use today.
     *
     * Found in review: a start name matched every record at any start. A second workflow on the
     * table, its period over and its start named otherwise, listed every record not started yet
     * under a name none of their rows showed.
     *
     * @return void
     */
    public function testStatusFilterKeepsTheStartsOfTheWorkflowsApart()
    {
        $this->init();

        $notStarted = (new WorkflowTaskService())->getTasks()->first(function ($row) {
            return is_null(CustomTable::getEloquent($row['custom_table_id'])->getValueModel($row['morph_id'])->workflow_value);
        });
        $custom_table = isset($notStarted) ? CustomTable::getEloquent($notStarted['custom_table_id']) : null;
        $current = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($custom_table) || !isset($current)) {
            $this->markTestSkipped('needs a task not started yet for this user');
        }

        // a second workflow on the same table, its period over, its start named otherwise - a new
        // one: a workflow of the dataset can be in use on other tables, where its start is the start
        $name = 'workflow task start probe';
        $otherId = \DB::table('workflows')->insertGetId([
            'suuid' => short_uuid(),
            'workflow_type' => $current->workflow_type,
            'workflow_view_name' => 'workflow task test',
            'start_status_name' => $name,
            'setting_completed_flg' => 1,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
        ]);
        \DB::table(SystemTableName::WORKFLOW_TABLE)->insert([
            'workflow_id' => $otherId,
            'custom_table_id' => $custom_table->id,
            'active_flg' => 1,
            'active_start_date' => \Carbon\Carbon::today()->subDays(10)->toDateString(),
            'active_end_date' => \Carbon\Carbon::today()->subDays(5)->toDateString(),
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
        ]);
        System::clearCache();

        $this->assertContains($name, WorkflowTaskService::statusOptions(), 'precondition: the box offers the other start');
        $this->assertSame([], (new WorkflowTaskService(['status' => $name]))->getTasks()->pluck('task_key')->all(), 'no record is at the start of the other workflow');

        // every name finds exactly the rows that show it
        foreach (WorkflowTaskService::statusOptions() as $status) {
            foreach ((new WorkflowTaskService(['status' => $status]))->getTasks() as $row) {
                $this->assertSame($status, trim((string)$row['status_name']), "'{$status}' found {$row['task_key']}, whose column shows '{$row['status_name']}'");
            }
        }
    }

    /**
     * Does the record page offer the login user an action they still have to press - the thing a
     * task is (see WorkflowTaskService::executableActionFilter())?
     *
     * @param int $customTableId
     * @param int $id
     * @return bool
     */
    private function recordPageOffers(int $customTableId, int $id): bool
    {
        System::clearCache();
        $custom_value = CustomTable::getEloquent($customTableId)->getValueModel($id);
        if (!isset($custom_value)) {
            return false;
        }

        return $custom_value->getWorkflowActions(true, true)->contains(function ($action) use ($custom_value) {
            return !WorkflowValue::isAlreadyExecuted($action->id, $custom_value, \Exment::user()->base_user);
        });
    }

    /**
     * A deactivated workflow must not produce tasks.
     *
     * @return void
     */
    public function testTasksRespectActiveFlg()
    {
        $this->init();

        if ((new WorkflowTaskService())->getTasks()->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        \DB::table(SystemTableName::WORKFLOW_TABLE)->update(['active_flg' => 0]);
        \Exceedone\Exment\Model\System::clearCache();

        $this->assertCount(0, (new WorkflowTaskService())->getTasks());
    }

    /**
     * notify_navbars.notify_id is INT UNSIGNED NOT NULL.
     *
     * SameOrganizationWorkflowNotify writes a synthetic notification that has no parent
     * Notify record. Whatever placeholder it uses must be storable: a negative value is
     * silently coerced to 0 on a non-strict MySQL and throws on a strict one (which is
     * the Laravel default), so a workflow action would 500 AFTER the status already moved.
     *
     * @return void
     */
    public function testSyntheticNotifyIdIsStorable()
    {
        // deliberately does NOT call init(): this check only needs the schema,
        // so it still runs on a database without the Exment test dataset.
        $column = \DB::selectOne(
            'SELECT COLUMN_TYPE ct FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND COLUMN_NAME = ?',
            ['notify_navbars', 'notify_id']
        );

        if (!isset($column) || stripos($column->ct, 'unsigned') === false) {
            $this->markTestSkipped('notify_id is not an unsigned column on this driver');
        }

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Services/Notify/SameOrganizationWorkflowNotify.php');
        $this->assertNotFalse($source);

        $hits = preg_match_all('/notify_id\s*=\s*-\s*\d+/', $source, $matches);

        $this->assertSame(
            0,
            $hits,
            'notify_navbars.notify_id is ' . $column->ct . ' NOT NULL, but SameOrganizationWorkflowNotify assigns '
            . implode(', ', $matches[0] ?: []) . '. On a strict-mode MySQL (Laravel default) the insert throws, '
            . 'and it throws AFTER the workflow status has already been committed. Use 0 instead.'
        );
    }

    /**
     * The synthetic notification must be addressed to the OTHER member, never to the
     * user who executed the action (they already know), and must be readable by the
     * bell (which filters on target_user_id + read_flg).
     *
     * @return void
     */
    public function testSyntheticNotifyIsReadableByTheBell()
    {
        $this->init();

        $targetUserId = TestDefine::TESTDATA_USER_LOGINID_USER1;

        $notify = new NotifyNavbar();
        $notify->notify_id = 0;
        $notify->parent_id = 1;
        $notify->parent_type = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $notify->notify_subject = 'test subject';
        $notify->notify_body = 'test body';
        $notify->target_user_id = $targetUserId;
        $notify->trigger_user_id = $targetUserId;
        $notify->save();

        $found = NotifyNavbar::where('target_user_id', $targetUserId)
            ->where('read_flg', false)
            ->where('id', $notify->id)
            ->first();

        $this->assertNotNull($found, 'a synthetic navbar notification must be visible to the bell query');
        $this->assertSame(0, (int)$found->read_flg, 'a new notification must start unread');
    }

    /**
     * No bulk INSERT carries more values than SQL Server takes in one statement: 2,100.
     *
     * Found in review: marks went in 1,000 rows (7,000 values) and the colleague notification 500
     * rows (5,500 values) at a time. MySQL takes that; SQL Server fails the statement - "mark all
     * as seen" then fell back to two queries per task, and an organization of more than 190 other
     * members was notified not at all. Counted on the bindings, so it holds on the database the
     * tests run on.
     *
     * @return void
     */
    public function testNoBulkInsertCarriesMoreValuesThanSqlServerTakes()
    {
        $this->init();
        config(['exment.same_org_workflow_notify' => true]);
        [$action, $custom_value] = $this->notifyTarget();

        // far more than one statement may carry: 700 marks, 400 colleagues
        $keys = [];
        for ($i = 1; $i <= 700; $i++) {
            $keys[] = WorkflowTaskService::taskKey(4294967295, $i);
        }
        $userIds = range(900001, 900400);

        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            (new WorkflowTaskService())->markSeen($keys);
            \Exceedone\Exment\Services\Notify\SameOrganizationWorkflowNotify::notify($action, $custom_value, $userIds);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $inserts = array_filter($queries, function ($query) {
            return stripos(ltrim($query['query']), 'insert') === 0;
        });
        $this->assertNotEmpty($inserts);
        foreach ($inserts as $query) {
            $this->assertLessThanOrEqual(2100, count($query['bindings']), 'more values than SQL Server takes: ' . substr($query['query'], 0, 60));
        }

        // and the chunking lost nothing
        $this->assertSame(700, WorkflowTaskRead::where('custom_table_id', 4294967295)->count());
        $this->assertSame(400, NotifyNavbar::withoutGlobalScopes()
            ->where('parent_type', $custom_value->custom_table->table_name)
            ->where('parent_id', $custom_value->id)
            ->whereIn('target_user_id', $userIds)
            ->count());
    }

    /**
     * The colleague notification carries the record label as text, never as markup.
     *
     * Found in review: the notification page prints the body as HTML (html_clean(), no escaping),
     * and the label went in as typed - "<a href=...>" in a label reached every colleague as a live
     * link, inside a message that reads like it comes from the system.
     *
     * @return void
     */
    public function testNotifyBodyCarriesTheLabelAsText()
    {
        $this->init();
        config(['exment.same_org_workflow_notify' => true]);
        [$action, $custom_value] = $this->notifyTarget();

        // the label as a user may type it (getLabel() answers from this once it is set)
        $typed = '<a href="https://evil.example/login">承認はこちら</a> & "A&B"';
        $label = new \ReflectionProperty(\Exceedone\Exment\Model\CustomValue::class, '_label');
        $label->setAccessible(true);
        $label->setValue($custom_value, $typed);

        \Exceedone\Exment\Services\Notify\SameOrganizationWorkflowNotify::notify($action, $custom_value, [900001]);

        $body = NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', 900001)
            ->where('parent_id', $custom_value->id)
            ->value('notify_body');
        $this->assertIsString($body, 'the colleague was not notified');
        $this->assertStringNotContainsString('<a', $body, 'the label must not reach the body as markup');

        // what the notification page shows: the text that was typed, and no link
        $shown = html_clean(replaceBreak($body, false));
        $this->assertStringNotContainsString('<a', $shown);
        $this->assertStringContainsString($typed, html_entity_decode($shown, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The colleague notification goes to the members of the organization who may open the table,
     * not to every member. Found in review: a member without any access to the table was told the
     * label and the status of a record they cannot open. Who may open it is asked the way core asks
     * it (AuthUserOrgHelper): the roles of the user and of their organizations, and the system
     * administrators. Both colleagues are checked against the permissions they really have first.
     *
     * @return void
     */
    public function testSameOrgNotifySkipsMembersWhoCannotOpenTheTable()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($custom_table) || !isset($workflow) || $custom_table->allUserAccessable()) {
            $this->markTestSkipped('needs a workflow table whose access goes by role');
        }
        /** @var WorkflowAction|null $start */
        $start = WorkflowAction::where('workflow_id', $workflow->id)->where('status_from', Define::WORKFLOW_START_KEYNAME)->first();
        $tableName = getDBTableName($custom_table);
        /** @var \Exceedone\Exment\Model\CustomValue|null $custom_value */
        $custom_value = $custom_table->getValueQuery()
            ->whereNotExists(function ($query) use ($custom_table, $tableName) {
                $query->selectRaw('1')
                    ->from(SystemTableName::WORKFLOW_VALUE)
                    ->whereColumn(SystemTableName::WORKFLOW_VALUE . '.morph_id', $tableName . '.id')
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name);
            })
            ->first();
        if (!isset($start) || !isset($custom_value)) {
            $this->markTestSkipped('needs a record at the start of the test workflow');
        }

        // one colleague who may open the table and one who may not - by the permissions they really
        // have, which include the roles of the organizations they belong to
        $withAccess = null;
        $withoutAccess = null;
        foreach (LoginUser::where('id', '<>', TestDefine::TESTDATA_USER_LOGINID_ADMIN)->orderBy('id')->get() as $login) {
            $this->be($login);
            System::clearCache();
            if ($custom_table->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                $withAccess = $withAccess ?? (int)$login->base_user_id;
            } else {
                $withoutAccess = $withoutAccess ?? (int)$login->base_user_id;
            }
        }
        if (!isset($withAccess) || !isset($withoutAccess)) {
            $this->markTestSkipped('the test dataset has no colleague with access and one without');
        }
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearCache();

        // an organization of the two, as the work target of the start action
        $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel();
        $org->setValue('organization_code', 'workflow_task_test_org');
        $org->setValue('organization_name', 'workflow task test');
        $org->save();
        \DB::table(\Exceedone\Exment\Model\CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER))->insert([
            ['parent_id' => $org->id, 'child_id' => $withAccess],
            ['parent_id' => $org->id, 'child_id' => $withoutAccess],
        ]);
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            'related_id' => $org->id,
            'related_type' => 'organization',
            'workflow_action_id' => $start->id,
        ]);
        System::clearCache();

        // the administrator presses the button: neither colleague is the one who acted
        $told = array_map('intval', \Exceedone\Exment\Services\Notify\SameOrganizationWorkflowNotify::getOtherOrgMemberIds($start, $custom_value));

        $this->assertContains($withAccess, $told, 'the colleague who may open the table is told');
        $this->assertNotContains($withoutAccess, $told, 'the colleague who may not open the table must not be told about the record');
    }

    /**
     * Who may open the table is asked the way the permission scope lets people in: on a child
     * table that inherits the permission of its parent, whoever may open every record of the
     * parent may open the child's (CustomValueModelScope). Found in review: core's helper reads the
     * roles of the child table only, so those colleagues heard nothing once the notice went to the
     * members who may open the table. And the members are never bound as SQL parameters: thousands
     * of them on top of core's own ids passed the 2,100 SQL Server takes, and nobody was told.
     *
     * @return void
     */
    public function testSameOrgNotifyAsksWhoMayOpenTheTableLikeTheScope()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        if (!isset($parent) || !isset($child) || $parent->allUserAccessable() || $child->allUserAccessable()) {
            $this->markTestSkipped('needs the parent and the child table of the test dataset, opened by role');
        }

        // a colleague who may open neither table - by the permissions they really have
        $userId = null;
        foreach (LoginUser::where('id', '<>', TestDefine::TESTDATA_USER_LOGINID_ADMIN)->orderBy('id')->get() as $login) {
            $this->be($login);
            System::clearCache();
            if (!$parent->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)
                && !$child->hasPermission(\Exceedone\Exment\Enums\Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                $userId = (int)$login->base_user_id;
                break;
            }
        }
        if (!isset($userId)) {
            $this->markTestSkipped('the test dataset has no user without any role');
        }
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        // ... given every record of the parent table
        $now = \Carbon\Carbon::now();
        $roleGroupId = \DB::table('role_groups')->insertGetId([
            'role_group_name' => 'workflow_task_test_parent_all',
            'role_group_view_name' => 'workflow task test',
            'role_group_order' => 0,
            'description' => 'workflow task test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \DB::table('role_group_permissions')->insert([
            'role_group_id' => $roleGroupId,
            'role_group_permission_type' => \Exceedone\Exment\Enums\RoleType::TABLE,
            'role_group_target_id' => $parent->id,
            'permissions' => json_encode([\Exceedone\Exment\Enums\Permission::CUSTOM_VALUE_EDIT_ALL]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \DB::table('role_group_user_organizations')->insert([
            'role_group_id' => $roleGroupId,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        System::clearCache();

        $method = new \ReflectionMethod(\Exceedone\Exment\Services\Notify\SameOrganizationWorkflowNotify::class, 'membersWhoMayOpen');
        $method->setAccessible(true);
        $mayOpen = function (array $ids) use ($method, $child): array {
            return array_map('intval', $method->invoke(null, $child, collect($ids))->all());
        };

        $this->assertSame([], $mayOpen([$userId]), 'precondition: the child table itself is closed to them');
        $child->setOption('inherit_parent_permission', true);
        $this->assertSame([$userId], $mayOpen([$userId]), 'inheriting the permission of the parent, it is open to them');

        // thousands of members: not one statement carries more values than SQL Server takes
        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $this->assertSame([$userId], $mayOpen(array_merge(range(2000001, 2003000), [$userId])));
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(2100, count($query['bindings']), 'a statement carries more values than SQL Server takes');
        }
    }

    /**
     * An action and a record of the common test workflow, for the colleague notification.
     *
     * @return array{WorkflowAction, \Exceedone\Exment\Model\CustomValue}
     */
    private function notifyTarget(): array
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        /** @var WorkflowAction|null $action */
        $action = isset($workflow) ? WorkflowAction::where('workflow_id', $workflow->id)->first() : null;
        /** @var \Exceedone\Exment\Model\CustomValue|null $custom_value */
        $custom_value = isset($custom_table) ? $custom_table->getValueQuery()->first() : null;
        if (!isset($action) || !isset($custom_value)) {
            $this->markTestSkipped('needs a record of a workflow table in the test dataset');
        }

        return [$action, $custom_value];
    }

    /**
     * The badge and the list screen are now two different code paths: countUnseen() filters
     * with SQL, getTasksWithSeen() builds the rows and filters in PHP. They must always agree,
     * otherwise the badge says "3" and the screen shows 2 - the classic failure of this kind
     * of optimisation.
     *
     * @return void
     */
    public function testSqlCountAgreesWithTheListScreen()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $tasks = $service->getTasksWithSeen();

        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $listUnseen = $tasks->filter(function ($row) {
            return !$row['seen'];
        })->count();

        $this->assertSame($listUnseen, $service->countUnseen(), 'the SQL count drifted from the list screen');

        // and it must keep agreeing after something is marked as seen
        $service->markSeen([$tasks->first()['task_key']]);

        $after = new WorkflowTaskService();
        $listUnseenAfter = $after->getTasksWithSeen()->filter(function ($row) {
            return !$row['seen'];
        })->count();

        $this->assertSame($listUnseenAfter, $after->countUnseen());
        $this->assertSame($listUnseen - 1, $listUnseenAfter, 'marking one task seen must drop the count by exactly one');
    }

    /**
     * topUnseen() feeds the navbar dropdown. It must never return more than asked for, must
     * return only unseen tasks, and must be empty once everything is marked as seen.
     *
     * @return void
     */
    public function testTopUnseenIsLimitedAndUnseenOnly()
    {
        $this->init();

        $service = new WorkflowTaskService();

        if ($service->getTasks()->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $top = $service->topUnseen(2);
        $this->assertLessThanOrEqual(2, $top->count(), 'the dropdown must respect its limit');

        $seenKeys = array_flip($service->seenKeys());
        foreach ($top as $row) {
            $this->assertArrayNotHasKey($row['task_key'], $seenKeys, 'topUnseen() returned an already seen task');
        }

        // mark everything as seen -> nothing left for the dropdown
        $service->markAllSeen();
        $this->assertCount(0, (new WorkflowTaskService())->topUnseen());
    }

    /**
     * A login user can outlive its user record: somebody leaves the company, the user record is
     * (soft) deleted, the login row stays. That account can still sign in, and the work-user
     * conditions then read base_user->belong_organizations on null. The navbar runs on EVERY
     * admin page, so this must be an empty list, not a fatal error on every screen.
     *
     * @return void
     */
    public function testLoginUserWithoutBaseUserSeesNothing()
    {
        $this->init();

        $login = new LoginUser();
        // an id no user record can have
        $login->base_user_id = 2147483000;
        $login->login_type = 'pure';
        $login->password = \Hash::make(bin2hex(random_bytes(16)));
        $login->save();

        /** @var LoginUser $loginUser */
        $loginUser = LoginUser::find($login->id);
        $this->be($loginUser);
        $this->assertNull($login->base_user, 'the fixture must really have no base user');

        $service = new WorkflowTaskService();
        $this->assertSame(0, $service->countUnseen());
        $this->assertCount(0, $service->topUnseen());
        $this->assertCount(0, $service->getTasksWithSeen());

        // and writing must be a no-op, not a not-null violation
        $service->markSeen([WorkflowTaskService::taskKey(999999, 888888)]);
        $service->markAllSeen();
        $this->assertSame(0, $service->countUnseen());
    }

    /**
     * The badge runs on every poll, and building ONE work-user query costs about as much as
     * running it, so a table the user may not read at all has to be dropped before a query is
     * built for it.
     *
     * "In no role group" is not the same as "has no permission": Exment turns the
     * all_user_editable_flg option of a table into an edit-all permission for EVERY user
     * (HasPermissions::getCustomTablePermissions()), and the test dataset sets that flag on
     * workflow1..workflow4. Queries for those tables are correct, so counting queries proves
     * nothing - what this test pins is the one table of the dataset nobody may read.
     *
     * @return void
     */
    public function testTablesWithoutPermissionAreNotQueried()
    {
        $this->init();

        // a real user record, but in no role group at all
        $base = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $base->setValue([
            'user_code' => 'wf_task_norole',
            'user_name' => 'wf_task_norole',
            'email'     => 'wf_task_norole@example.test',
        ]);
        $base->save();

        $login = new LoginUser();
        $login->base_user_id = $base->id;
        $login->login_type = 'pure';
        $login->password = \Hash::make(bin2hex(random_bytes(16)));
        $login->save();

        /** @var LoginUser $loginUser */
        $loginUser = LoginUser::find($login->id);
        $this->be($loginUser);

        // the work-user query is the expensive one, and it is the only thing that reads these
        // two views - so this collects the SQL of every table a query really was built for
        $workUserQueries = [];
        \DB::listen(function ($query) use (&$workUserQueries) {
            if (\Str::contains($query->sql, ['view_workflow_value_unions', 'view_workflow_start'])) {
                $workUserQueries[] = $query->sql;
            }
        });

        $service = new WorkflowTaskService();
        $count = $service->countUnseen();
        // topPending() is what the navbar actually calls, topUnseen() only feeds the badge -
        // neither may reach a table this user has no permission on
        $items = $service->topPending();
        $unseenItems = $service->topUnseen();

        $this->assertSame(0, $count, 'a user with no role group must see no task');
        $this->assertCount(0, $items);
        $this->assertCount(0, $unseenItems);

        // no_permission is the one table the dataset hands out no permission on at all: it is in
        // no role group (createPermission() only walks the five custom_value_* tables) and
        // carries none of the all_user_*_flg options. It does carry an active, completed
        // workflow, so it reaches the candidate list and only the permission check can drop it -
        // which is why its physical table name is the thing to look for in the SQL.
        $forbidden = CustomTable::getEloquent('no_permission');
        $workflow = \is_nullorempty($forbidden)
            ? null
            : \Exceedone\Exment\Model\Workflow::getWorkflowByTable($forbidden);
        if (\is_nullorempty($workflow)) {
            $this->markTestSkipped('this dataset has no unreadable table with a usable workflow');
        }

        $this->assertStringNotContainsString(
            \getDBTableName($forbidden),
            implode("\n", $workUserQueries),
            'a work-user query was built for a table this user has no permission on'
        );
    }

    /**
     * Hard deleting a record has to take the "seen" marks with it. forwardWorkflowValue() only
     * clears them on a status change, so without the delete hook every hard deleted record
     * would leave one row per user in workflow_task_reads with nothing left to point at.
     *
     * @return void
     */
    public function testHardDeleteClearsTheSeenState()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $row = $tasks->first();

        $stored = function () use ($row) {
            return WorkflowTaskRead::withoutGlobalScopes()
                ->where('custom_table_id', $row['custom_table_id'])
                ->where('morph_id', $row['morph_id'])
                ->count();
        };

        // the count deliberately ignores the user scope, because the delete hook has to clear
        // the marks of EVERY user - so colleagues who already opened this record are counted
        // too. Only the row this test adds is ours; assert the delta, not the absolute number.
        $others = $stored();

        (new WorkflowTaskService())->markSeen([$row['task_key']]);
        $this->assertSame($others + 1, $stored());

        $custom_table = CustomTable::getEloquent($row['custom_table_id']);
        $custom_value = $custom_table->getValueModel($row['morph_id']);

        // a soft delete keeps the mark, so restoring the record keeps its state
        $custom_value->delete();
        $this->assertSame($others + 1, $stored(), 'a soft delete must not drop the seen state');

        /** @var \Exceedone\Exment\Model\CustomValue $trashed */
        $trashed = $custom_table->getValueModel()->withTrashed()->find($row['morph_id']);
        $trashed->forceDelete();
        $this->assertSame(0, $stored(), 'a hard delete left an orphan row in workflow_task_reads');
    }

    /**
     * The list screen used to read every pending task of every table and cut a page out of it
     * in PHP; it now asks for one page. Walking every page must still give exactly the same
     * tasks, in the same order, with the same seen state - anything else silently hides
     * somebody's approval task.
     *
     * @return void
     */
    public function testGetPageReturnsTheSameTasksAsTheFullList()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $all = $service->getTasksWithSeen();

        if ($all->isEmpty()) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $this->assertSame($all->count(), $service->countAll(), 'countAll() drifted from the list');

        // big pages on purpose: the property under test does not depend on the page size, and
        // the test dataset has enough tasks that walking it 3 rows at a time would be slow
        $perPage = 200;
        $lastPage = (int)ceil($all->count() / $perPage);
        $keys = [];
        $seen = [];
        $stamps = [];

        for ($page = 1; $page <= $lastPage; $page++) {
            $result = (new WorkflowTaskService())->getPage($page, $perPage);
            $this->assertSame($all->count(), $result['total'], 'the paginator total drifted');

            // getPage() clamps to the last page that exists
            if ($result['page'] !== $page || $result['rows']->isEmpty()) {
                break;
            }

            foreach ($result['rows'] as $row) {
                $keys[] = $row['task_key'];
                $seen[$row['task_key']] = $row['seen'];
                $stamps[] = (string)$row['updated_at'];
            }

            if ($result['rows']->count() < $perPage) {
                break;
            }
        }

        $expectedKeys = $all->pluck('task_key')->all();
        sort($expectedKeys);
        $gotKeys = $keys;
        sort($gotKeys);

        $this->assertSame($expectedKeys, $gotKeys, 'paging lost or duplicated a task');
        $this->assertCount(count($keys), array_unique($keys), 'a task appeared on two pages');

        foreach ($all as $row) {
            $this->assertSame(
                $row['seen'],
                $seen[$row['task_key']] ?? null,
                'the seen flag changed for ' . $row['task_key']
            );
        }

        // the screen defaults to oldest first
        for ($i = 1; $i < count($stamps); $i++) {
            $this->assertGreaterThanOrEqual(
                0,
                strcmp($stamps[$i], $stamps[$i - 1]),
                'the pages are not sorted oldest first'
            );
        }

        // a small page must be the head of the same order, not a different one
        $small = (new WorkflowTaskService())->getPage(1, 3);
        $this->assertSame(
            array_slice($keys, 0, min(3, count($keys))),
            $small['rows']->pluck('task_key')->all(),
            'the first small page is not the head of the list'
        );
    }

    /**
     * A page number out of range must be clamped, not turned into "read every id of every
     * table" - the page number comes straight from the query string.
     *
     * @return void
     */
    public function testGetPageClampsAnImpossiblePage()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $total = $service->countAll();

        if ($total < 1) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $perPage = 5;
        $lastPage = (int)ceil($total / $perPage);

        $result = (new WorkflowTaskService())->getPage(999999, $perPage);
        $this->assertSame($lastPage, $result['page'], 'the page was not clamped to the last one');
        $this->assertSame($total, $result['total']);
        $this->assertGreaterThan(0, $result['rows']->count(), 'the last page must not be empty');

        $first = (new WorkflowTaskService())->getPage(0, $perPage);
        $this->assertSame(1, $first['page'], 'page 0 must fall back to the first page');
    }

    /**
     * "Mark all as seen" only clears the badge. The tasks are still un-actioned, so the navbar
     * dropdown must keep listing them - otherwise it prints "there is no un-actioned task"
     * (workflow_task.empty) over a list screen that still shows every one of them.
     *
     * @return void
     */
    public function testMarkAllSeenEmptiesTheBadgeButNotTheDropdown()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $total = $service->countAll();

        if ($total < 1) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $this->assertGreaterThan(0, $service->countUnseen(), 'nothing is unseen, the test proves nothing');

        (new WorkflowTaskService())->markAllSeen();

        $after = new WorkflowTaskService();
        $this->assertSame(0, $after->countUnseen(), 'the badge must be empty after marking all as seen');
        $this->assertSame($total, $after->countAll(), 'marking as seen must not remove a task');

        // the badge is empty, the dropdown is not
        $top = $after->topPending();
        $this->assertGreaterThan(0, $top->count(), 'the dropdown claims there is no un-actioned task');
        $this->assertSame(
            min(WorkflowTaskService::NAVBAR_ITEM_COUNT, $total),
            $top->count(),
            'the dropdown must be filled up to its limit'
        );
        $this->assertSame(0, $after->topUnseen()->count(), 'nothing is unseen any more');

        // and it lists exactly the head of the list screen, in the same order. The dropdown
        // always shows the NEWEST tasks, so it is the newest-first page it has to agree with -
        // the screen itself defaults to oldest first.
        $head = (new WorkflowTaskService(['sort' => 'desc']))->getPage(1, WorkflowTaskService::NAVBAR_ITEM_COUNT);
        $this->assertSame(
            $head['rows']->pluck('task_key')->all(),
            $top->pluck('task_key')->all(),
            'the dropdown and the list screen disagree about the newest tasks'
        );

        foreach ($top as $row) {
            $this->assertTrue($row['seen'], 'every task is seen now, so none may be printed in bold');
        }
    }

    /**
     * Walk every page of a filter and return the rows in display order.
     *
     * @param array<string, mixed> $filter
     * @param int $perPage
     * @return array<int, array<string, mixed>>
     */
    private function walk(array $filter, int $perPage = 200): array
    {
        $rows = [];

        for ($page = 1; $page <= 500; $page++) {
            $result = (new WorkflowTaskService($filter))->getPage($page, $perPage);
            if ($result['page'] !== $page || $result['rows']->isEmpty()) {
                break;
            }
            foreach ($result['rows'] as $row) {
                $rows[] = $row;
            }
            if ($result['rows']->count() < $perPage) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Nothing that arrives from the query string reaches the query builder unchecked.
     *
     * @return void
     */
    public function testFilterRejectsJunkFromTheQueryString()
    {
        $empty = WorkflowTaskService::normalizeFilter([]);
        $this->assertSame('asc', $empty['sort'], 'the screen must default to oldest first');
        foreach (['custom_table_id', 'seen', 'status', 'from', 'to', 'q'] as $key) {
            $this->assertNull($empty[$key], $key . ' must default to "no filter"');
        }

        $junk = WorkflowTaskService::normalizeFilter([
            'custom_table_id' => '1 OR 1=1',
            'seen'            => 'yes',
            'status'          => ['start'],
            'from'            => '2026-02-31',
            'to'              => "'; DROP TABLE users; --",
            'q'               => str_repeat('x', 500),
            'sort'            => 'updated_at desc',
        ]);

        $this->assertNull($junk['custom_table_id'], 'a table id that is not a number must be dropped');
        $this->assertNull($junk['seen'], 'an unknown seen value must be dropped');
        $this->assertNull($junk['status'], '"?status[]=" hands over an array, not a status name');
        // a status name is compared as it is, never cut: a cut name could be another status
        $this->assertNull(
            WorkflowTaskService::normalizeFilter(['status' => str_repeat('x', WorkflowTaskService::MAX_STATUS_NAME_LENGTH + 1)])['status'],
            'a name longer than any status can have is no status'
        );
        $this->assertSame('承認待ち', WorkflowTaskService::normalizeFilter(['status' => ' 承認待ち '])['status']);
        $this->assertNull(WorkflowTaskService::normalizeFilter(['status' => '  '])['status'], 'an empty choice is "any status"');
        $this->assertNull($junk['from'], '2026-02-31 is not a date');
        $this->assertNull($junk['to'], 'a date that is not a date must be dropped');
        $this->assertSame(
            WorkflowTaskService::MAX_KEYWORD_LENGTH,
            mb_strlen($junk['q']),
            'the keyword must be cut, it goes into a LIKE'
        );
        $this->assertSame('asc', $junk['sort'], 'an unknown sort must not reach the ORDER BY');

        // the controller normalizes, then the service normalizes again
        $this->assertSame($empty, WorkflowTaskService::normalizeFilter($empty), 'normalizeFilter must be idempotent');
        $this->assertSame(1, WorkflowTaskService::normalizeFilter(['custom_table_id' => '1'])['custom_table_id']);
        $this->assertSame(0, WorkflowTaskService::normalizeFilter(['seen' => '0'])['seen'], '"0" is a value, not an absence');
    }

    /**
     * The screen sorts oldest first by default - the oldest un-actioned task is the one holding
     * everybody up. Turning the direction around must give exactly the reverse list, which only
     * holds if the order is total: record ids repeat across tables, so updated_at + id is not
     * enough to decide between two rows of two different tables.
     *
     * @return void
     */
    public function testDefaultSortIsOldestFirstAndExactlyReversible()
    {
        $this->init();

        $asc = $this->walk([]);
        if (count($asc) < 2) {
            $this->markTestSkipped('this user has fewer than two workflow tasks');
        }

        $stamps = array_map(function ($row) {
            return (string)$row['updated_at'];
        }, $asc);
        $sorted = $stamps;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $stamps, 'the default order is not oldest first');

        $desc = $this->walk(['sort' => 'desc']);
        $ascKeys = array_column($asc, 'task_key');
        $descKeys = array_column($desc, 'task_key');

        $this->assertSame(array_reverse($ascKeys), $descKeys, 'desc is not the exact reverse of asc');

        // a page boundary in the middle of a group of equal timestamps is where an order that
        // is not total falls apart. The head is enough: three pages of seven cross two
        // boundaries, and walking thousands of rows seven at a time only costs time.
        $head = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ((new WorkflowTaskService())->getPage($page, 7)['rows'] as $row) {
                $head[] = $row['task_key'];
            }
        }
        $this->assertSame(
            array_slice($ascKeys, 0, count($head)),
            $head,
            'the order depends on the page size'
        );
    }

    /**
     * Filtering by table must split the list, not reshape it: every row still belongs to the
     * chosen table, the per-table counts add up to the unfiltered total, and the select box
     * still offers every table (or the user could never get back).
     *
     * @return void
     */
    public function testTableFilterSplitsTheListWithoutLosingARow()
    {
        $this->init();

        $options = WorkflowTaskService::tableOptions();
        if (count($options) < 1) {
            $this->markTestSkipped('this user has no workflow table');
        }

        $total = (new WorkflowTaskService())->countAll();
        $sum = 0;

        foreach (array_keys($options) as $customTableId) {
            $filter = ['custom_table_id' => $customTableId];
            $count = (new WorkflowTaskService($filter))->countAll();
            $sum += $count;

            $rows = $this->walk($filter);
            $this->assertCount($count, $rows, 'the count of table ' . $customTableId . ' does not match its rows');

            foreach ($rows as $row) {
                $this->assertEquals($customTableId, $row['custom_table_id'], 'a foreign table leaked into the filter');
            }

            $this->assertSame(
                $options,
                WorkflowTaskService::tableOptions(),
                'the select box must keep offering every table while one is selected'
            );
        }

        $this->assertSame($total, $sum, 'the per-table counts do not add up to the whole list');
    }

    /**
     * Read and unread are two halves of the same list.
     *
     * @return void
     */
    public function testSeenFilterSplitsTheList()
    {
        $this->init();

        $total = (new WorkflowTaskService())->countAll();
        if ($total < 1) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $seen = $this->walk(['seen' => 1]);
        $unseen = $this->walk(['seen' => 0]);

        $this->assertSame($total, count($seen) + count($unseen), 'read + unread is not the whole list');

        foreach ($seen as $row) {
            $this->assertTrue($row['seen'], 'an unread row is inside the read filter');
        }
        foreach ($unseen as $row) {
            $this->assertFalse($row['seen'], 'a read row is inside the unread filter');
        }

        $this->assertSame(
            0,
            (new WorkflowTaskService(['seen' => 1]))->countUnseen(),
            'a read-only filter cannot contain unread tasks'
        );
    }

    /**
     * Both ends of the date range belong to the range: from=to=<a day> must return that whole
     * day, not the single instant at midnight.
     *
     * @return void
     */
    public function testDateRangeIsInclusiveOnBothEnds()
    {
        $this->init();

        $all = $this->walk([]);
        if (empty($all)) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $day = substr((string)$all[0]['updated_at'], 0, 10);
        $expected = count(array_filter($all, function ($row) use ($day) {
            return substr((string)$row['updated_at'], 0, 10) === $day;
        }));

        $rows = $this->walk(['from' => $day, 'to' => $day]);
        $this->assertGreaterThan(0, $expected, 'the sample day is empty, the test proves nothing');
        $this->assertCount($expected, $rows, 'from=to=' . $day . ' is not an inclusive single day');

        $this->assertCount(0, $this->walk(['from' => '2000-01-01', 'to' => '2000-01-02']), 'an empty range must be empty');
        $this->assertSame(
            0,
            (new WorkflowTaskService(['from' => '2000-01-01', 'to' => '2000-01-02']))->countAll(),
            'the count must follow the range as well'
        );
    }

    /**
     * The keyword matches the label the "data" column prints, and the LIKE wildcards a user
     * types are literal characters - "%" must not return the whole list.
     *
     * @return void
     */
    public function testKeywordSearchMatchesTheLabelAndEscapesWildcards()
    {
        $this->init();

        $all = $this->walk([]);
        if (empty($all)) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $label = $all[0]['label'];

        $hits = $this->walk(['q' => $label]);
        $this->assertGreaterThan(0, count($hits), 'searching the label of a listed row found nothing');
        $this->assertContains($label, array_column($hits, 'label'), 'the searched row is missing from its own result');

        $this->assertCount(0, $this->walk(['q' => 'zzz_no_such_workflow_task_zzz']), 'a keyword matching nothing must return nothing');
        // A wildcard the user typed has to be matched as itself. Counting rows cannot show
        // that: every label of the test dataset is "test_<id>", so a correctly escaped "_"
        // still matches every single one of them. The probe is built out of a label that IS
        // on the list, with its first character replaced by the wildcard - unescaped it
        // matches that very row, escaped it matches nothing.
        $probeSource = (string)$label;
        foreach (['%', '_'] as $wildcard) {
            if ($probeSource === '' || mb_substr($probeSource, 0, 1) === $wildcard) {
                continue;
            }

            $probe = $wildcard . mb_substr($probeSource, 1);
            $this->assertNotContains(
                $label,
                array_column($this->walk(['q' => $probe]), 'label'),
                'a typed ' . $wildcard . ' is being used as a wildcard'
            );
        }

        // the label starts with "#<id>" on tables that show it, so both forms must find it
        $byId = $this->walk(['q' => '#' . $all[0]['morph_id']]);
        $this->assertContains(
            $all[0]['task_key'],
            array_column($byId, 'task_key'),
            'searching "#id" does not find the record'
        );
    }

    /**
     * The values of the filter are applied together: a search with a table, 状態, a date range
     * and a free word returns exactly the tasks every one of them lets through - the
     * intersection of what each value returns on its own - and every value takes part in it.
     *
     * @return void
     */
    public function testEveryFilterValueNarrowsTheSameList()
    {
        $this->init();
        $scenario = $this->filterScenario();
        $filter = $scenario['filter'];

        $result = $this->keysOf($this->walk($filter));

        // exactly what every value lets through on its own
        $expected = null;
        foreach ($filter as $key => $value) {
            $alone = $this->keysOf($this->walk([$key => $value]));
            $expected = is_null($expected) ? $alone : array_values(array_intersect($expected, $alone));
        }
        $this->assertSame($expected, $result, 'the filter values are not applied together');

        $this->assertContains($scenario['match'], $result, 'the task every value lets through is missing');
        foreach ($scenario['excluded'] as $key => $taskKey) {
            $this->assertNotContains($taskKey, $result, "'{$key}' did not keep out the task only it excludes");
        }

        // and none of them is decoration: leaving one out lets more through
        foreach (array_keys($filter) as $key) {
            $without = $filter;
            unset($without[$key]);
            $this->assertGreaterThan(count($result), count($this->walk($without)), "'{$key}' narrows nothing in this search");
        }

        // the total follows the same conditions as the rows
        $this->assertSame(count($result), (new WorkflowTaskService($filter))->countAll());
    }

    /**
     * The list has two search forms, like the data grid: the free-word box next to the filter
     * button, and the filter panel. A search in one of them keeps the values entered in the
     * other - the user narrows the list step by step - and リセット of the panel drops the panel
     * values only. Every step sends what the browser sends: the fields the user filled in, plus
     * the hidden fields the controller gave that form.
     *
     * @return void
     */
    public function testSearchingInOneFormKeepsTheValuesOfTheOther()
    {
        $this->init();
        $scenario = $this->filterScenario();
        $keyword = $scenario['filter']['q'];
        // every value of the panel: 対象テーブル, 状態 = 未読, 現在のステータス, 更新日時
        $panel = array_diff_key($scenario['filter'], ['q' => true]) + ['status' => $scenario['status']];

        // the list as the user left it: newest first, 100 rows a page
        $list = $this->openList(['sort' => 'desc', 'per_page' => '100']);

        // 1. the panel
        $list = $this->openList(array_merge($this->asFields($panel), $list['panelKeep']));
        $this->assertListShows($panel, $list);
        $this->assertTrue($list['expandFilter'], 'values are set in the panel, it must stay open');

        // 2. a free word typed into the box next to the filter button
        $list = $this->openList(array_merge(['q' => $keyword], $list['quickSearchKeep']));
        $this->assertListShows($panel + ['q' => $keyword], $list);

        // 3. back in the panel, 状態 = 全て: the free word stays
        $list = $this->openList(array_merge($this->asFields($panel), ['seen' => ''], $list['panelKeep']));
        $this->assertListShows(array_diff_key($panel, ['seen' => true]) + ['q' => $keyword], $list);

        // 4. リセット of the panel: the panel values go, the free word stays
        parse_str((string)parse_url($list['resetUrl'], PHP_URL_QUERY), $query);
        $list = $this->openList($query);
        $this->assertListShows(['q' => $keyword], $list);
        $this->assertFalse($list['expandFilter'], 'nothing is set in the panel any more');
    }

    /**
     * 現在のステータス finds exactly the tasks whose 現在のステータス column shows the chosen name:
     * the start status (no action yet, or led back to it) as well as the later ones, in every
     * table whose workflow has a status of that name, and nothing where no task is in it.
     * Logged in as a user with tasks both at the start and at later statuses.
     *
     * @return void
     */
    public function testStatusFilterFindsTheTasksShowingThatStatus()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);

        $all = $this->walk([]);
        $byStatus = [];
        foreach ($all as $row) {
            $byStatus[(string)$row['status_name']][] = $row;
        }
        if (count($byStatus) < 2) {
            $this->markTestSkipped('this user has no tasks in two different statuses');
        }

        // every name the select box offers: the ones tasks are in and the ones none is in
        $options = WorkflowTaskService::statusOptions();
        foreach (array_keys($byStatus) as $name) {
            $this->assertContains((string)$name, $options, "the select box does not offer '{$name}', a status tasks are in");
        }
        foreach ($options as $name) {
            $expected = $this->keysOf($byStatus[$name] ?? []);
            $this->assertSame($expected, $this->keysOf($this->walk(['status' => $name])), "'{$name}' does not find the tasks showing it");
            $this->assertSame(count($expected), (new WorkflowTaskService(['status' => $name]))->countAll(), "the total of '{$name}' does not match its rows");
        }

        // one table at a time: a name the workflow of a table does not have finds nothing there
        foreach (array_keys(WorkflowTaskService::tableOptions()) as $customTableId) {
            foreach (array_keys($byStatus) as $name) {
                $expected = array_filter($byStatus[$name], function ($row) use ($customTableId) {
                    return $row['custom_table_id'] == $customTableId;
                });
                $this->assertSame(
                    $this->keysOf($expected),
                    $this->keysOf($this->walk(['custom_table_id' => $customTableId, 'status' => (string)$name])),
                    "'{$name}' in table {$customTableId}"
                );
            }
        }

        $this->assertSame([], $this->walk(['status' => 'zzz_no_such_status']), 'a name no workflow has must find nothing');
    }

    /**
     * A status called "1", or one stored with a space around it (an import can do that, the
     * settings screen trims it), is offered in the select box as the very string the screen
     * compares with, and finds the tasks showing it. As a key "1" turns into the integer 1, and an
     * untrimmed name never matches the trimmed value normalizeFilter() makes of what comes back.
     *
     * @return void
     */
    public function testStatusNamesLikeNumbersOrWithSpacesStillFilter()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);

        // a status past the start that tasks of this user are in (the start is not a row of
        // workflow_statuses, its name is stored on the workflow)
        $statusId = null;
        foreach ($this->walk([]) as $row) {
            $value = WorkflowValue::where('morph_type', CustomTable::getEloquent($row['custom_table_id'])->table_name)
                ->where('morph_id', $row['morph_id'])
                ->where('latest_flg', true)
                ->first();
            if (isset($value) && !is_null($value->workflow_status_to_id)) {
                $statusId = $value->workflow_status_to_id;
                break;
            }
        }
        if (is_null($statusId)) {
            $this->markTestSkipped('this user has no task past the start of its workflow');
        }

        // pairs, not a map: as a key, '1' would be the integer 1 here too
        foreach ([['1', '1'], [' 承認待ち ', '承認待ち']] as [$stored, $offered]) {
            // renamed in the settings (DatabaseTransactions rolls it back)
            \DB::table((new WorkflowStatus())->getTable())->where('id', $statusId)->update(['status_name' => $stored]);
            System::clearCache();

            $expected = $this->keysOf(array_filter($this->walk([]), function ($row) use ($stored) {
                return (string)$row['status_name'] === $stored;
            }));
            $this->assertNotEmpty($expected, "precondition: tasks show the status '{$stored}'");

            $options = WorkflowTaskService::statusOptions();
            $this->assertTrue(array_is_list($options), 'the options must be a list: the view reads the values, a key "1" is the integer 1');
            $this->assertContains($offered, $options, "the select box does not offer '{$offered}'");

            // the option sent back as it is offered, and as it is stored
            foreach ([$offered, $stored] as $sent) {
                $list = $this->openList(['status' => $sent]);
                $this->assertSame($offered, $list['filter']['status'], "'{$sent}' is not searched as the option it came from");
                $this->assertContains($list['filter']['status'], $list['statusOptions'], 'the chosen status is not selected in the box');
                $this->assertSame($expected, $this->keysOf($this->walk(['status' => $sent])), "'{$sent}' does not find the tasks showing it");
            }
        }
    }

    /**
     * An empty list says there is no pending task only when nothing hides one (found in review: a
     * search that matched nothing told the user they had no task at all). What hides a task is a
     * condition of the filter; the order, the page size and the page do not, and neither does a
     * value normalizeFilter() throws away.
     *
     * @return void
     */
    public function testOnlyAConditionMakesTheListSayFiltered()
    {
        $this->init();

        $plain = $this->openList([]);
        if ($plain['total'] < 1) {
            $this->markTestSkipped('this user has no workflow task');
        }
        $this->assertFalse($plain['isFiltered']);

        $hidesNothing = [
            'the order' => ['sort' => 'desc'],
            'the page size and page' => ['per_page' => '50', 'page' => '2'],
            'values that are no condition' => ['seen' => 'yes', 'from' => '2026-02-31', 'custom_table_id' => 'x', 'status' => '  ', 'q' => ' '],
        ];
        foreach ($hidesNothing as $label => $query) {
            $list = $this->openList($query);
            $this->assertFalse($list['isFiltered'], "{$label} hides no task");
            $this->assertSame($plain['total'], $list['total'], "{$label} changed the total");
        }

        $conditions = [
            'custom_table_id' => (string)array_key_first(WorkflowTaskService::tableOptions()),
            'seen' => '1',
            'status' => 'zzz_no_such_status',
            'from' => '2000-01-01',
            'to' => '2000-01-01',
            'q' => 'zzz_no_such_workflow_task_zzz',
        ];
        foreach ($conditions as $key => $value) {
            $this->assertTrue($this->openList([$key => $value])['isFiltered'], "{$key} is a condition of the filter");
        }

        // a search that finds nothing, while the user has tasks: an empty, filtered list
        $list = $this->openList(['q' => 'zzz_no_such_workflow_task_zzz']);
        $this->assertSame(0, $list['total']);
        $this->assertTrue($list['isFiltered'], 'an empty search result would say the user has no task');
        $this->assertNotSame(exmtrans('workflow_task.empty'), exmtrans('workflow_task.empty_filtered'));
    }

    /**
     * The date filter (reported: an input type=date showed the date in the format of the OS, not of
     * APP_LOCALE). The pickers get the language of the screen, and write dates in the one shape the
     * filter reads: a day picked on the calendar is the day the list is filtered by. Anything else -
     * the way an OS writes a date - is no date to the filter, which is why the picker must not use a
     * format of its own.
     *
     * @return void
     */
    public function testDatePickerSpeaksTheScreenLanguageAndWritesWhatTheFilterReads()
    {
        $this->init();

        foreach (['ja', 'en'] as $locale) {
            app()->setLocale($locale);
            $options = $this->openList([])['dateOptions'];

            $this->assertSame($locale, $options['locale'], "the calendar of a {$locale} screen is not in {$locale}");

            // 2026-09-15 picked on the calendar, written in the picker's format (moment.js tokens)
            $picked = \Carbon\Carbon::createMidnightDate(2026, 9, 15)->format(strtr($options['format'], ['YYYY' => 'Y', 'MM' => 'm', 'DD' => 'd']));
            $filter = WorkflowTaskService::normalizeFilter(['from' => $picked, 'to' => $picked]);
            $this->assertSame(['2026-09-15', '2026-09-15'], [$filter['from'], $filter['to']], "a day picked on the {$locale} calendar is not the day the list is filtered by");
        }

        foreach (['09/15/2026', '15/09/2026', '2026/09/15', '15.09.2026', '2026年9月15日', '2026-9-15'] as $osDate) {
            $this->assertNull(WorkflowTaskService::normalizeFilter(['from' => $osDate])['from'], "'{$osDate}' was taken for a date");
        }
    }

    /**
     * 更新日時 up to 9999-12-31, the last date there is, lists what no end date lists. Found in
     * review: the end date is inclusive, so the bound was the start of the next day - and
     * "10000-01-01" is no date to the database: MySQL failed the statement (error 1525) and the
     * list answered 500.
     *
     * @return void
     */
    public function testDateFilterUpToTheLastDateThereIs()
    {
        $this->init();

        $all = (new WorkflowTaskService())->countAll();

        $this->assertSame($all, (new WorkflowTaskService(['to' => '9999-12-31']))->countAll());
        $this->assertSame($all, $this->openList(['to' => '9999-12-31'])['total'], 'the list screen must answer too');
        // the day before still sets a bound, one that keeps everything here as well
        $this->assertSame($all, (new WorkflowTaskService(['to' => '9999-12-30']))->countAll());
    }

    /**
     * A date before the first year there is a record of bounds nothing: from it, the list keeps
     * everything; up to it, nothing. Found in review: SQL Server compares updated_at as a datetime,
     * which starts in 1753, and failed the whole list on "0001-01-01". No such date may reach the
     * database, whichever this runs on.
     *
     * @return void
     */
    public function testDatesBeforeTheFirstYearBoundNothing()
    {
        $this->init();

        $all = (new WorkflowTaskService())->countAll();

        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $this->assertSame($all, (new WorkflowTaskService(['from' => '0001-01-01']))->countAll(), 'nothing is older');
            $this->assertSame(0, (new WorkflowTaskService(['to' => '1752-12-31']))->countAll(), 'nothing was updated before then');
            $this->assertSame(0, $this->openList(['from' => '1500-01-01', 'to' => '1899-12-30'])['total'], 'the list screen must answer too');
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        foreach ($queries as $query) {
            foreach ($query['bindings'] as $binding) {
                $this->assertFalse(
                    is_string($binding) && preg_match('/^(0\d|1[0-8])\d\d-\d\d-\d\d/', $binding) === 1,
                    "a date before 1900 reached the database: {$binding}"
                );
            }
        }
    }

    /**
     * The free word and the LIKE / NOT LIKE execution conditions look for what was typed: a % or _
     * in it is that character, not a wildcard, whatever the database. MySQL and MariaDB take a
     * backslash; SQL Server has no escape character unless the statement names one - a backslash
     * is a plain character there - and takes a wildcard between brackets literally instead (found
     * in review: "50%" found nothing on SQL Server).
     *
     * @return void
     */
    public function testContainsPatternFindsWhatWasTyped()
    {
        $this->init();

        $pattern = new \ReflectionMethod(WorkflowTaskService::class, 'containsPattern');
        $pattern->setAccessible(true);

        // on the database this runs on, LIKE with the pattern answers what str_contains() answers
        $texts = ['50%', '50', '5%0', 'A_B', 'AxB', 'a\\b', 'ab', '[x]', 'x', '100% [OK]_\\'];
        $needles = ['%', '_', '50%', 'A_B', '\\', '[x]', '[', ']'];
        foreach ($needles as $needle) {
            foreach ($texts as $text) {
                $row = \DB::selectOne('SELECT CASE WHEN ? LIKE ? THEN 1 ELSE 0 END AS found', [$text, $pattern->invoke(null, $needle)]);
                $this->assertSame(str_contains($text, $needle), (int)$row->found === 1, (string)json_encode(['text' => $text, 'typed' => $needle]));
            }
        }

        // SQL Server, which no CI job runs on: the bracket escape, and no backslash escape
        // shouldReceive() is declared as returning an expectation OR a higher order message, and
        // only the first has andReturn(); a single method name always gives the expectation
        /** @var \Mockery\Expectation $sqlServer */
        $sqlServer = \Exment::partialMock()->shouldReceive('isSqlServer');
        $sqlServer->andReturn(true);
        $this->assertSame('%50[%]%', $pattern->invoke(null, '50%'));
        $this->assertSame('%A[_]B%', $pattern->invoke(null, 'A_B'));
        $this->assertSame('%[[]x]%', $pattern->invoke(null, '[x]'));
        $this->assertSame('%a\\b%', $pattern->invoke(null, 'a\\b'));
    }

    /**
     * A search in which every value of the filter keeps out a task that all the other values let
     * through. Built from the tasks of the logged-in user (DatabaseTransactions rolls it back):
     * in the table with the most tasks one task is marked seen and two are moved out of the date
     * range, and the free word is shared by some of its tasks but not all.
     *
     * "status" is the 現在のステータス of the task every value lets through.
     *
     * @return array{filter: array<string, mixed>, match: string, status: string, excluded: array<string, string>}
     */
    private function filterScenario(): array
    {
        // start from a known state: nothing seen, nothing taken off the list
        WorkflowTaskRead::withoutGlobalScopes()->where('target_user_id', \Exment::getUserId())->delete();

        $all = $this->walk([]);
        $byTable = [];
        foreach ($all as $row) {
            $byTable[$row['custom_table_id']][] = $row;
        }
        uasort($byTable, function ($a, $b) {
            return count($b) <=> count($a);
        });
        $customTableId = array_key_first($byTable);
        $rows = is_null($customTableId) ? [] : $byTable[$customTableId];
        if (count($byTable) < 2 || count($rows) < 5) {
            $this->markTestSkipped('the scenario needs tasks in two workflow tables, five of them in one');
        }

        // a free word some tasks of that table share, but not all: a label without its last
        // character ("index_002_00" finds 001 to 009, not 010)
        $keyword = null;
        foreach ($rows as $row) {
            $candidate = mb_substr((string)$row['label'], 0, -1);
            $hits = array_filter($rows, function ($other) use ($candidate) {
                return mb_stripos((string)$other['label'], $candidate) !== false;
            });
            if (mb_strlen($candidate) >= 3 && count($hits) >= 4 && count($hits) < count($rows)) {
                $keyword = $candidate;
                break;
            }
        }
        if (is_null($keyword)) {
            $this->markTestSkipped('no free word is shared by some but not all tasks of one table');
        }

        // what the free word finds is the database's answer, not this guess
        $found = array_column($this->walk(['q' => $keyword]), 'task_key');
        $matching = array_values(array_filter($rows, function ($row) use ($found) {
            return in_array($row['task_key'], $found, true);
        }));
        if (count($matching) < 4) {
            $this->markTestSkipped('the free word finds fewer than four tasks of the table');
        }

        $match = $matching[0];
        $day = substr((string)$match['updated_at'], 0, 10);
        $onDay = function ($row) use ($day) {
            return substr((string)$row['updated_at'], 0, 10) === $day;
        };

        $seen = collect($matching)->slice(1)->first($onDay);
        $moved = collect($matching)->slice(1)->reject(function ($row) use ($seen) {
            return !is_null($seen) && $row['task_key'] === $seen['task_key'];
        })->take(2)->values();
        $missing = collect($rows)->first(function ($row) use ($found, $onDay) {
            return !in_array($row['task_key'], $found, true) && $onDay($row);
        });
        $elsewhere = collect($all)->first(function ($row) use ($customTableId, $found, $onDay) {
            return $row['custom_table_id'] != $customTableId && in_array($row['task_key'], $found, true) && $onDay($row);
        });
        if (is_null($seen) || $moved->count() < 2 || is_null($missing) || is_null($elsewhere)) {
            $this->markTestSkipped('the dataset has no task for every value of the filter to keep out');
        }

        // 状態: one task of the day is read
        (new WorkflowTaskService())->markSeen([$seen['task_key']]);

        // 更新日時: one task a year before the day, one a year after it
        $dbTable = getDBTableName(CustomTable::getEloquent($customTableId));
        [$before, $after] = $moved->all();
        \DB::table($dbTable)->where('id', $before['morph_id'])
            ->update(['updated_at' => \Carbon\Carbon::parse($day)->subYear()->format('Y-m-d') . ' 12:00:00']);
        \DB::table($dbTable)->where('id', $after['morph_id'])
            ->update(['updated_at' => \Carbon\Carbon::parse($day)->addYear()->format('Y-m-d') . ' 12:00:00']);

        return [
            'filter' => [
                'custom_table_id' => $customTableId,
                'seen' => 0,
                'from' => $day,
                'to' => $day,
                'q' => $keyword,
            ],
            'match' => $match['task_key'],
            'status' => (string)$match['status_name'],
            // the task each value keeps out, and only that value
            'excluded' => [
                'custom_table_id' => $elsewhere['task_key'],
                'seen' => $seen['task_key'],
                'from' => $before['task_key'],
                'to' => $after['task_key'],
                'q' => $missing['task_key'],
            ],
        ];
    }

    /**
     * The task keys of rows, sorted: a set to compare.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function keysOf(array $rows): array
    {
        $keys = array_column($rows, 'task_key');
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Filter values the way a form sends them: as strings.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function asFields(array $values): array
    {
        return array_map(function ($value) {
            return (string)$value;
        }, $values);
    }

    /**
     * What the list screen last gave its view (see openList()).
     *
     * @var array<string, mixed>|null
     */
    private $listData = null;

    /**
     * Open the list screen with a query string, as the browser does, and hand back what the
     * controller gave its view - the rows, the total, and the hidden fields of both forms.
     *
     * @param array<int|string, mixed> $query a query string, as parse_str() reads one back too
     * @return array<string, mixed>
     */
    private function openList(array $query): array
    {
        if (is_null($this->listData)) {
            \View::creator('exment::workflow_task.index', function ($view) {
                $this->listData = $view->getData();
            });
        }
        $this->listData = [];

        (new WorkflowTaskController())->index(Request::create('/workflow_task', 'GET', $query), new \ExmentAdminCore\Admin\Layout\Content());
        $this->assertArrayHasKey('rows', $this->listData, 'the list screen did not draw its view');

        return $this->listData;
    }

    /**
     * The list screen as the browser gets it, from what openList() captured.
     *
     * @param array<string, mixed> $list
     * @return string
     */
    private function drawList(array $list): string
    {
        return view('exment::workflow_task.index', $list)->render();
    }

    /**
     * The query string of a link of the list, as the controller reads it back.
     *
     * @param string $url
     * @return array<int|string, mixed>
     */
    private function queryOf(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * The list shows exactly the tasks of a filter, still newest first and 100 a page: the order
     * and the page size picked when the list was opened survive every search.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $list what openList() returned
     * @return void
     */
    private function assertListShows(array $filter, array $list): void
    {
        // the list searched for exactly these values - also one that happens to narrow nothing
        $this->assertSame(
            WorkflowTaskService::normalizeFilter($filter + ['sort' => 'desc']),
            $list['filter'],
            'the list did not search for the values entered'
        );

        $expected = array_column($this->walk($filter + ['sort' => 'desc']), 'task_key');

        $this->assertSame(count($expected), $list['total'], 'the total does not match the values searched for');
        $this->assertSame(array_slice($expected, 0, 100), $list['rows']->pluck('task_key')->all(), 'the rows do not match the values searched for');
        $this->assertSame('desc', $list['filter']['sort'], 'a search lost the order');
        $this->assertSame(100, $list['perPage'], 'a search lost the page size');
    }

    /**
     * "Mark all as seen" sits under a filtered list, so it marks that list. Marking a different,
     * invisible set of tasks would be a silent data change the user never asked for.
     *
     * @return void
     */
    public function testMarkAllSeenOnlyMarksTheFilteredList()
    {
        $this->init();

        $options = WorkflowTaskService::tableOptions();
        $withRows = [];
        foreach (array_keys($options) as $customTableId) {
            if ((new WorkflowTaskService(['custom_table_id' => $customTableId]))->countAll() > 0) {
                $withRows[] = $customTableId;
            }
            if (count($withRows) >= 2) {
                break;
            }
        }

        if (count($withRows) < 2) {
            $this->markTestSkipped('this user has tasks in fewer than two workflow tables');
        }

        // start from a known state (DatabaseTransactions rolls this back)
        WorkflowTaskRead::withoutGlobalScopes()->where('target_user_id', \Exment::getUserId())->delete();

        list($marked, $untouched) = $withRows;
        (new WorkflowTaskService(['custom_table_id' => $marked]))->markAllSeen();

        $markedService = new WorkflowTaskService(['custom_table_id' => $marked]);
        $untouchedService = new WorkflowTaskService(['custom_table_id' => $untouched]);

        $this->assertSame(0, $markedService->countUnseen(), 'the filtered table is not fully marked');
        $this->assertSame(
            $untouchedService->countAll(),
            $untouchedService->countUnseen(),
            'a table outside the filter was marked too'
        );
    }

    /**
     * ... and what the user reads says so. Under a filter the confirm dialog of each menu entry
     * and the answer after it speak of the tasks matching the filter: "all tasks" over a list
     * narrowed to one table is how a user ends up believing the other tables were marked too.
     * Without a filter they keep saying "all tasks", which is then true.
     *
     * @return void
     */
    public function testBatchTextsUnderAFilterOnlySpeakOfTheFilteredList()
    {
        $this->init();

        $controller = new WorkflowTaskController();
        $menu = new \ReflectionMethod($controller, 'getMenuList');
        $menu->setAccessible(true);

        $plain = $menu->invoke($controller, WorkflowTaskService::normalizeFilter([]), false);
        $this->assertSame(exmtrans('workflow_task.confirm_text.mark_all_seen'), $plain[0]['text']);
        $this->assertSame(exmtrans('workflow_task.confirm_text.mark_all_unseen'), $plain[1]['text']);

        $filter = WorkflowTaskService::normalizeFilter(['q' => 'zzz_no_such_workflow_task_zzz']);
        $filtered = $menu->invoke($controller, $filter, true);
        $this->assertSame(exmtrans('workflow_task.confirm_text.mark_all_seen_filtered'), $filtered[0]['text']);
        $this->assertSame(exmtrans('workflow_task.confirm_text.mark_all_unseen_filtered'), $filtered[1]['text']);

        // the answer to the ajax post of each entry (DatabaseTransactions rolls the marks back)
        foreach (['readAll' => 'mark_all_seen', 'unreadAll' => 'mark_all_unseen'] as $method => $message) {
            foreach ([$message . '_filtered_succeeded' => ['q' => 'zzz_no_such_workflow_task_zzz'], $message . '_succeeded' => []] as $expected => $params) {
                $request = Request::create('/workflow_task/' . $method, 'POST', $params);
                $request->headers->set('X-Requested-With', 'XMLHttpRequest');

                $answer = json_decode($controller->{$method}($request)->getContent(), true);
                $this->assertSame(exmtrans('workflow_task.message.' . $expected), array_get($answer, 'toastr'), "{$method} with " . json_encode($params));
            }
        }
    }

    /**
     * "Delete" on this screen takes the task off the user's OWN list and nothing else.
     *
     * The row is a real record of a real table, shared with everybody else who works on it. The
     * first version sent the button to CustomValueController@destroy, so deleting a task deleted
     * the record itself (reported as: 未処理タスクで削除を選択すると、元データも削除される).
     *
     * @return void
     */
    public function testDeleteOnlyTakesTheTaskOffMyList()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $task = $tasks->first();
        $key = $task['task_key'];
        $total = (new WorkflowTaskService())->countAll();

        $this->assertSame(1, (new WorkflowTaskService())->hideSelected([$key]));

        // the record is untouched: not deleted, not even moved to the trash
        $custom_table = CustomTable::getEloquent($task['custom_table_id']);
        $custom_value = getModelName($custom_table)::withTrashed()->find($task['morph_id']);
        $this->assertNotNull($custom_value, 'deleting a task deleted the record itself');
        $this->assertFalse($custom_value->trashed(), 'deleting a task moved the record to the trash');

        // ... but the task is gone from every place that lists or counts tasks
        $service = new WorkflowTaskService();
        $this->assertFalse($service->getTasks()->contains('task_key', $key), 'the deleted task is still listed');
        $this->assertSame($total - 1, $service->countAll(), 'the total still counts the deleted task');
        $this->assertFalse($service->topPending(1000)->contains('task_key', $key), 'the dropdown still shows it');
        $this->assertFalse(
            collect((new WorkflowTaskService())->getPage(1, 100)['rows'])->contains('task_key', $key),
            'the list screen still shows it'
        );

        // deleted is not "read": the menu entry that marks everything unread must not bring it back
        (new WorkflowTaskService())->markAllUnseen();
        $this->assertFalse(
            (new WorkflowTaskService())->getTasks()->contains('task_key', $key),
            '"mark all as unread" brought a deleted task back'
        );

        // a second delete of the same task has nothing left to do
        $this->assertSame(0, (new WorkflowTaskService())->hideSelected([$key]));

        // the mark is this user's alone: nobody else's list has changed
        $this->assertSame(
            0,
            WorkflowTaskRead::withoutGlobalScopes()
                ->where('custom_table_id', $task['custom_table_id'])
                ->where('morph_id', $task['morph_id'])
                ->where('target_user_id', '<>', \Exment::getUserId())
                ->count(),
            'deleting my task wrote a mark for another user'
        );
    }

    /**
     * The keys of a batch delete come from the browser. Only this user's own, still listed tasks
     * may be taken off the list - a key for somebody else's record, a table that does not exist
     * or a string that is not a key at all is dropped, and a malformed body is answered, not a 500.
     *
     * @return void
     */
    public function testRowDeleteOnlyAcceptsMyOwnListedTasks()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $controller = new WorkflowTaskController();
        $userId = \Exment::getUserId();
        $before = WorkflowTaskRead::where('target_user_id', $userId)->count();

        $malformed = [
            'an array'        => ['1:1', '2:2'],
            'a nested array'  => ['a' => ['b' => 'c']],
            'a number'        => 5,
            'an empty string' => '',
            'nothing at all'  => null,
            'only junk'       => implode(',', [WorkflowTaskService::taskKey(4294967295, 1), WorkflowTaskService::taskKey(1, 99999999), 'not-a-key']),
        ];

        foreach ($malformed as $label => $keys) {
            $response = $controller->rowDelete(
                Request::create('/workflow_task/rowDelete', 'POST', is_null($keys) ? [] : ['keys' => $keys])
            );

            $this->assertSame(400, $response->getStatusCode(), "{$label} must be answered, not accepted");
        }

        $this->assertSame(
            $before,
            WorkflowTaskRead::where('target_user_id', $userId)->count(),
            'a request without one of my tasks in it must not store anything'
        );

        // a real key mixed in with the junk still goes through
        $real = $tasks->first();
        $response = $controller->rowDelete(Request::create('/workflow_task/rowDelete', 'POST', [
            'keys' => WorkflowTaskService::taskKey(4294967295, 1) . ',' . $real['task_key'] . ',not-a-key',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse((new WorkflowTaskService())->getTasks()->contains('task_key', $real['task_key']));
        $this->assertNotNull(
            CustomTable::getEloquent($real['custom_table_id'])->getValueModel($real['morph_id']),
            'the record behind the task must still be there'
        );
    }

    /**
     * A deleted task is off the list for the step it was deleted at. The status change clears it
     * together with the read marks (WorkflowAction::forwardWorkflowValue), for the same reason:
     * a record at a new status is a new task (TC-WT-29 / TC-WT-37).
     *
     * @return void
     */
    public function testStatusChangeClearsTheDeleteMark()
    {
        $this->init();

        $task = (new WorkflowTaskService())->getTasks()->first();
        if (is_null($task)) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $custom_value = CustomTable::getEloquent($task['custom_table_id'])->getValueModel($task['morph_id']);
        $action = $custom_value->getWorkflowActions(true, true)->first();
        if (is_null($action)) {
            $this->markTestSkipped('the listed task has no action to execute');
        }

        (new WorkflowTaskService())->hideSelected([$task['task_key']]);
        $marks = WorkflowTaskRead::withoutGlobalScopes()
            ->where('custom_table_id', $task['custom_table_id'])
            ->where('morph_id', $task['morph_id']);
        $this->assertSame(1, (clone $marks)->count(), 'precondition: the task is marked as deleted');

        $action->executeAction($custom_value, ['comment' => 'workflow task test']);

        $this->assertSame(0, (clone $marks)->count(), 'the status moved on but the delete mark stayed');
    }

    /**
     * exment:refreshtable empties a table and starts its ids over at 1. The task marks of its
     * records go with them (found in review: a mark left behind named the NEW record that got the
     * same id, and hid its task or showed it as read from the start). Other tables keep theirs.
     *
     * @return void
     */
    public function testRefreshingATableForgetsTheMarksOfItsRecords()
    {
        $this->init();

        [$custom_table, $marks, $otherMarks] = $this->markedTableWithoutRecordTable();
        $others = (clone $otherMarks)->count();

        RefreshDataService::refreshTable([$custom_table->table_name]);

        $this->assertSame(0, (clone $marks)->count(), 'the refreshed table kept the marks of its old records');
        $this->assertSame($others, (clone $otherMarks)->count(), 'the marks of another table went too');
    }

    /**
     * Deleting a table takes the task marks of its records with it: a table created later can get
     * the same id (MySQL 5.7 hands out AUTO_INCREMENT again after a restart), and would inherit them.
     *
     * @return void
     */
    public function testDeletingATableForgetsTheMarksOfItsRecords()
    {
        $this->init();

        [$custom_table, $marks, $otherMarks] = $this->markedTableWithoutRecordTable();
        $others = (clone $otherMarks)->count();

        $custom_table->delete();

        $this->assertSame(0, (clone $marks)->count(), 'the deleted table left the marks of its records behind');
        $this->assertSame($others, (clone $otherMarks)->count(), 'the marks of another table went too');
    }

    /**
     * A custom table with task marks of two users on two of its records, and a mark on a record of
     * another table (DatabaseTransactions rolls all of it back).
     *
     * Its table of records does not exist: RefreshDataService::refreshTable() and
     * CustomTable::dropTable() skip a missing one, so neither sends the TRUNCATE / DROP that would
     * commit - and end - the transaction of the test. Everything else takes its usual path.
     *
     * @return array{CustomTable, \Illuminate\Database\Eloquent\Builder<WorkflowTaskRead>, \Illuminate\Database\Eloquent\Builder<WorkflowTaskRead>}
     */
    private function markedTableWithoutRecordTable(): array
    {
        $custom_table = CustomTable::create([
            'table_name' => 'wftask_' . short_uuid(),
            'table_view_name' => 'workflow task test',
        ]);
        System::clearCache();
        $this->assertFalse(hasTable(getDBTableName($custom_table)), 'precondition: the table of records must not exist');

        $other = CustomTable::getEloquent('custom_value_edit_all');
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        if (!isset($other, $user2)) {
            $this->markTestSkipped('needs custom_value_edit_all and user2 in the test dataset');
        }

        $rows = [];
        foreach ([\Exment::getUserId(), $user2->base_user_id] as $userId) {
            foreach ([[$custom_table->id, 1], [$custom_table->id, 2], [$other->id, 999999]] as [$customTableId, $morphId]) {
                $rows[] = ['target_user_id' => $userId, 'custom_table_id' => $customTableId, 'morph_id' => $morphId, 'hidden_flg' => $morphId === 2];
            }
        }
        WorkflowTaskRead::insert($rows);

        $marks = WorkflowTaskRead::withoutGlobalScopes()->where('custom_table_id', $custom_table->id);
        $otherMarks = WorkflowTaskRead::withoutGlobalScopes()->where('custom_table_id', $other->id)->where('morph_id', 999999);
        $this->assertSame(4, (clone $marks)->count(), 'precondition: the marks are stored');

        return [$custom_table, $marks, $otherMarks];
    }

    /**
     * The navbar badge follows what the user does on the list (found in review: after a delete the
     * badge kept counting the task). The poll caches its answer for one interval; the delete button
     * and the batch "mark as read" are own writes, so the very next poll answers the new numbers.
     * That the browser polls again right after them is workflow_task_navbar.js's part (see
     * WorkflowTaskWiringTest::testActionsOnTheListRefreshTheNavbar).
     *
     * @return void
     */
    public function testNavbarPollFollowsWhatTheListChanges()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks()->take(2)->values();
        if ($tasks->count() < 2) {
            $this->markTestSkipped('needs two un-actioned workflow tasks for this user');
        }

        // every task unread (DatabaseTransactions rolls this back), and no answer cached from before
        WorkflowTaskRead::withoutGlobalScopes()->where('target_user_id', \Exment::getUserId())->delete();
        WorkflowTaskService::navbarCacheForget();

        $api = new \Exceedone\Exment\Controllers\ApiController();
        $poll = function () use ($api) {
            return $api->workflowTaskPage(Request::create('/webapi/workflowTaskPage', 'GET'));
        };
        $shows = function (array $answer, string $key) {
            return collect($answer['items'])->contains(function ($item) use ($key) {
                return str_contains($item['href'], '?key=' . rawurlencode($key));
            });
        };

        $unseen = (new WorkflowTaskService())->countUnseen();
        $this->assertSame($unseen, $poll()['count'], 'precondition: the badge counts every unread task');

        $controller = new WorkflowTaskController();
        $controller->rowDelete(Request::create('/workflow_task/rowDelete', 'POST', ['keys' => $tasks[0]['task_key']]));
        $answer = $poll();
        $this->assertSame($unseen - 1, $answer['count'], 'the poll after a delete still counts the deleted task');
        $this->assertFalse($shows($answer, $tasks[0]['task_key']), 'the dropdown still shows the deleted task');

        $controller->rowCheck(Request::create('/workflow_task/rowCheck', 'POST', ['keys' => $tasks[1]['task_key']]));
        $this->assertSame($unseen - 2, $poll()['count'], 'the poll after "mark as read" still counts the task as unread');
    }

    /**
     * A task key names a record, not the step it is at. A list that has been open for a while can
     * name a record somebody acted on since - one that may be this user's task again, at a step
     * they have never seen. The delete button must not take THAT task off the list.
     *
     * The moment the list was drawn travels with the button (listed_at). Set around the record's
     * last action it decides: drawn before that action or in its very second, the delete is
     * refused; drawn after it, it goes through. Something that is not a moment - a page from
     * before this check sends nothing - is no check at all.
     *
     * The action is made by actOnRecordNow(): it leaves the step as it is, so the record stays
     * this user's task and only the moment decides.
     *
     * @return void
     */
    public function testDeleteFromAListDrawnBeforeTheLastActionIsRefused()
    {
        $this->init();

        $task = (new WorkflowTaskService())->getTasks()->first();
        if (is_null($task)) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        $custom_table = CustomTable::getEloquent($task['custom_table_id']);
        $key = $task['task_key'];
        $format = 'Y-m-d H:i:s';

        $this->actOnRecordNow($task);
        $this->assertTrue((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'precondition: the record is still this user\'s task');

        // the last action on the record: this one, or a real one that is even later
        $actedAt = \Carbon\Carbon::parse(WorkflowValue::where('morph_type', $custom_table->table_name)
            ->where('morph_id', $task['morph_id'])
            ->max('created_at'));
        $marks = WorkflowTaskRead::where('target_user_id', \Exment::getUserId())
            ->where('custom_table_id', $task['custom_table_id'])
            ->where('morph_id', $task['morph_id']);

        $this->assertSame(0, (new WorkflowTaskService())->hideSelected([$key], $actedAt->copy()->subMinute()->format($format)), 'a list drawn before the last action took the task off');
        $this->assertSame(0, (new WorkflowTaskService())->hideSelected([$key], $actedAt->format($format)), 'a list drawn in the second of the last action took the task off');
        $this->assertTrue((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'a refused delete still changed the list');

        // the button gets the answer of "nothing to delete", whose text asks for a reload
        $response = (new WorkflowTaskController())->rowDelete(Request::create('/workflow_task/rowDelete', 'POST', [
            'keys' => $key,
            'listed_at' => $actedAt->format($format),
        ]));
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, (clone $marks)->count(), 'a refused delete wrote a mark');

        // drawn after it: what the user saw is what is there, and it goes
        $this->assertSame(1, (new WorkflowTaskService())->hideSelected([$key], $actedAt->copy()->addSecond()->format($format)));

        foreach (['2026-02-31 10:00:00', '2026-09-29T10:00:00', 'yesterday', '', ['2026-01-01 00:00:00']] as $junk) {
            // put the task back on the list (DatabaseTransactions rolls all of this back)
            (clone $marks)->delete();

            $this->assertSame(1, (new WorkflowTaskService())->hideSelected([$key], $junk), 'a malformed moment ' . json_encode($junk) . ' stopped the delete');
        }
    }

    /**
     * A batch delete that leaves part of its selection alone - one of the records was acted on
     * after the list was drawn - says how many went and how many stayed. The confirm dialog
     * announced the whole selection; a plain "removed" would leave the user to find out by
     * counting rows which ones are still there.
     *
     * @return void
     */
    public function testPartlyRefusedBatchDeleteSaysHowManyStayed()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks()->take(2)->values();
        if ($tasks->count() < 2) {
            $this->markTestSkipped('needs two un-actioned workflow tasks for this user');
        }

        // the list was drawn a minute ago, and the second record was acted on since
        $listedAt = \Carbon\Carbon::now()->subMinute()->format('Y-m-d H:i:s');
        $this->actOnRecordNow($tasks[1]);

        $response = (new WorkflowTaskController())->rowDelete(Request::create('/workflow_task/rowDelete', 'POST', [
            'keys' => $tasks[0]['task_key'] . ',' . $tasks[1]['task_key'],
            'listed_at' => $listedAt,
        ]));
        $answer = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(exmtrans('workflow_task.message.delete_partial', 1, 1), array_get($answer, 'toastr'));

        $listed = (new WorkflowTaskService())->getTasks();
        $this->assertFalse($listed->contains('task_key', $tasks[0]['task_key']), 'the record nobody touched was not taken off');
        $this->assertTrue($listed->contains('task_key', $tasks[1]['task_key']), 'the record acted on after the list was drawn was taken off');

        // drawn after that action, the same record goes - and a batch that went through as a
        // whole keeps the plain answer
        $response = (new WorkflowTaskController())->rowDelete(Request::create('/workflow_task/rowDelete', 'POST', [
            'keys' => $tasks[1]['task_key'],
            'listed_at' => \Carbon\Carbon::now()->addSecond()->format('Y-m-d H:i:s'),
        ]));
        $this->assertSame(exmtrans('workflow_task.message.delete_succeeded'), array_get(json_decode((string)$response->getContent(), true), 'toastr'));
    }

    /**
     * A task taken off the list is found under 状態 = 削除済み, and put back from there as unread.
     *
     * Found in review: such a task came back only with the next action on its record. One that
     * returned to the user without an action - assigned away and back by editing the record - stayed
     * out of sight, on no list at all, while the record page asked them to act.
     *
     * @return void
     */
    public function testRemovedTaskIsFoundAndPutBackAsUnread()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->count() < 2) {
            $this->markTestSkipped('needs two un-actioned workflow tasks for this user');
        }
        $key = $tasks->first()['task_key'];
        $other = $tasks->last()['task_key'];
        $listed = function (array $filter = []) {
            return (new WorkflowTaskService($filter))->getTasks()->pluck('task_key')->all();
        };
        $removed = ['seen' => WorkflowTaskService::SEEN_REMOVED];
        $wasSeen = in_array($key, (new WorkflowTaskService())->seenKeys(), true);
        $unseen = (new WorkflowTaskService())->countUnseen();

        $this->assertSame(1, (new WorkflowTaskService())->hideSelected([$key]));
        $this->assertNotContains($key, $listed(), 'precondition: taken off the list');
        $this->assertContains($key, $listed($removed), 'a task taken off the list must be found under 削除済み');
        $this->assertNotContains($other, $listed($removed), 'and nothing else is');
        $this->assertSame(0, (new WorkflowTaskService($removed))->countUnseen(), 'a removed task is never an unread one');

        // put back: on the list again, unread - bold, and counted by the badge
        $this->assertSame(1, (new WorkflowTaskService())->restoreSelected([$key]));
        $this->assertContains($key, $listed());
        $this->assertNotContains($key, $listed($removed));
        $this->assertNotContains($key, (new WorkflowTaskService())->seenKeys(), 'a task put back is unread');
        $this->assertSame($unseen + ($wasSeen ? 1 : 0), (new WorkflowTaskService())->countUnseen());

        // nothing to put back a second time, nor what was never taken off, nor a key of nothing
        $this->assertSame(0, (new WorkflowTaskService())->restoreSelected([$key, $other]));
        $this->assertSame(0, (new WorkflowTaskService())->restoreSelected(['not a key', WorkflowTaskService::taskKey(4294967295, 1)]));
        $this->assertSame(0, (new WorkflowTaskService())->restoreSelected([]));
    }

    /**
     * The 削除済み list is a list screen like the others, and its button answers the script of the
     * list: a toastr on success, the "nothing to put back" answer when there was nothing.
     *
     * It is a list of its own, not a condition of the panel - the deleted data of a grid is a scope
     * of its filter button too: it does not open the panel, it offers no 状態 choice (its tasks are
     * neither read nor unread) and the panel form carries it instead, リセット keeps it, and
     * キャンセル leaves it with the rest of the filter.
     *
     * @return void
     */
    public function testRemovedListAndItsPutBackButton()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->isEmpty()) {
            $this->markTestSkipped('needs an un-actioned workflow task for this user');
        }
        $key = $tasks->first()['task_key'];
        $table = (string)$tasks->first()['custom_table_id'];
        $removed = (string)WorkflowTaskService::SEEN_REMOVED;
        (new WorkflowTaskService())->hideSelected([$key]);

        $list = $this->openList(['seen' => $removed]);
        $this->assertSame([$key], collect($list['rows'])->pluck('task_key')->all(), 'the 削除済み list shows what was taken off');
        $this->assertFalse($list['isFiltered'], 'the 削除済み list alone filters nothing');
        $this->assertTrue($list['removedView']);
        $this->assertFalse($list['expandFilter'], 'the 削除済み list is no condition of the panel');
        $this->assertSame($removed, (string)array_get($list['panelKeep'], 'seen'), 'a search in the panel must stay in the 削除済み list');

        $html = $this->drawList($list);
        $this->assertStringNotContainsString('class="workflow-task-filter-seen"', $html, 'the 削除済み list offers no 状態 choice');
        $this->assertStringContainsString('workflow-task-restore', $html);
        $this->assertStringContainsString('href="' . e($list['cancelUrl']) . '"', $html, 'キャンセル leads out of it');

        // narrowed in the panel: the table opens it, リセット drops the table and keeps the list,
        // キャンセル keeps the table and leaves the list
        $list = $this->openList(['seen' => $removed, 'custom_table_id' => $table, 'page' => '1']);
        $this->assertTrue($list['expandFilter']);
        $this->assertSame(['seen' => $removed], $this->queryOf($list['resetUrl']));
        $this->assertSame(['custom_table_id' => $table], $this->queryOf($list['cancelUrl']));

        $restore = function () use ($key) {
            return (new WorkflowTaskController())->rowRestore(Request::create('/workflow_task/rowRestore', 'POST', ['keys' => $key]));
        };
        $response = $restore();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(exmtrans('workflow_task.message.restore_succeeded', 1), array_get(json_decode((string)$response->getContent(), true), 'toastr'));

        $response = $restore();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(exmtrans('workflow_task.message.restore_notfound'), array_get(json_decode((string)$response->getContent(), true), 'toastr'));

        // emptied: it says so, not "nothing matches the filter"
        $list = $this->openList(['seen' => $removed]);
        $this->assertSame(0, $list['total']);
        $this->assertStringContainsString(e(exmtrans('workflow_task.empty_removed_list')), $this->drawList($list));
    }

    /**
     * Opening the list tells how many tasks were taken off it and leads there, under the rest of
     * the filter - also when nothing else is left on the list, which must then not read as if
     * nothing were waiting. 状態 offers unread, read and both (すべて); the 削除済み list is none of
     * them, the filter button leads to it like it leads to the deleted data of a grid.
     *
     * Found in review: 削除済み was a 状態 choice next to すべて (all), which does not hold it, and
     * the list said "no pending task" while the record page kept offering the button.
     *
     * @return void
     */
    public function testTheListLeadsToTheTasksTakenOffIt()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->isEmpty()) {
            $this->markTestSkipped('needs an un-actioned workflow task for this user');
        }
        $task = $tasks->first();
        $table = (string)$task['custom_table_id'];
        $removed = (string)WorkflowTaskService::SEEN_REMOVED;

        $list = $this->openList(['custom_table_id' => $table]);
        $this->assertSame(0, $list['removedTotal'], 'precondition: nothing taken off the list');
        $this->assertFalse($list['removedView']);
        $html = $this->drawList($list);
        $this->assertStringContainsString('class="workflow-task-filter-seen"', $html);
        $this->assertStringNotContainsString('name="seen" value="' . $removed . '"', $html, '状態 must not offer the 削除済み list');
        $this->assertStringContainsString('href="' . e($list['removedUrl']) . '"', $html, 'the filter button leads to it');

        (new WorkflowTaskService())->hideSelected([$task['task_key']]);

        // the header says how many, and the link takes the rest of the filter along - not the page
        $list = $this->openList(['custom_table_id' => $table, 'seen' => '0', 'page' => '1']);
        $this->assertSame(1, $list['removedTotal']);
        $this->assertSame(['custom_table_id' => $table, 'seen' => $removed], $this->queryOf($list['removedUrl']));
        $this->assertStringContainsString(e(exmtrans('workflow_task.removed_count', 1)), $this->drawList($list));

        // nothing else left on the list: the empty list still leads to the task
        $list = $this->openList(['custom_table_id' => $table, 'q' => '#' . $task['morph_id']]);
        $this->assertSame(0, $list['total']);
        $this->assertSame(1, $list['removedTotal']);
        $this->assertStringContainsString(e(exmtrans('workflow_task.empty_removed', 1)), $this->drawList($list));

        // the way back
        $this->assertSame(1, (new WorkflowTaskService())->restoreSelected([$task['task_key']]));
        $list = $this->openList(['custom_table_id' => $table]);
        $this->assertSame(0, $list['removedTotal']);
        $this->assertStringNotContainsString(e(exmtrans('workflow_task.removed_count', 0)), $this->drawList($list));
    }

    /**
     * workflow_task/read opens the records of the workflow tables the user works in, nothing else,
     * and leaves a mark only on an unread task of theirs.
     *
     * Found in review: it resolved a record of any table and relied on the permission scope, which
     * does not filter every table - a document answers to anybody. Its redirect gave away the file
     * address of any document, of records the user may not see included, and every probe left a
     * mark behind.
     *
     * @return void
     */
    public function testReadOpensRecordsOfTheWorkflowTablesOnly()
    {
        $this->init();

        $read = function (string $key): string {
            return (new WorkflowTaskController())->read(Request::create('/workflow_task/read', 'GET', ['key' => $key]))->getTargetUrl();
        };
        $list = admin_url('workflow_task');
        // WorkflowTaskRead reads the marks of the login user only
        $marks = function (): int {
            return WorkflowTaskRead::count();
        };

        // tables the list never reads: a document, a user - whatever the scope lets through
        $before = $marks();
        foreach ([SystemTableName::DOCUMENT, SystemTableName::USER] as $tableName) {
            $custom_table = CustomTable::getEloquent($tableName);
            $id = isset($custom_table) ? \DB::table(getDBTableName($custom_table))->whereNull('deleted_at')->value('id') : null;
            if (!isset($id)) {
                continue;
            }
            $this->assertSame($list, $read(WorkflowTaskService::taskKey($custom_table->id, $id)), "a record of {$tableName} is no task: back to the list");
        }
        $this->assertSame($before, $marks(), 'and nothing is marked');

        $tasks = (new WorkflowTaskService())->getTasksWithSeen();
        $unread = $tasks->first(function ($row) {
            return !$row['seen'];
        });
        if (!isset($unread)) {
            $this->markTestSkipped('needs an unread workflow task for this user');
        }

        // a record of the same workflow table that is no task of this user: opened, not marked
        $custom_table = CustomTable::getEloquent($unread['custom_table_id']);
        /** @var \Exceedone\Exment\Model\CustomValue|null $other */
        $other = $custom_table->getValueQuery()->whereNotIn('id', $tasks->where('custom_table_id', $unread['custom_table_id'])->pluck('morph_id')->all())->first();
        if (isset($other)) {
            $this->assertSame($other->getUrl(), $read(WorkflowTaskService::taskKey($custom_table->id, $other->id)));
            $this->assertSame($before, $marks(), 'a record that is no task of theirs is not marked');
        }

        // an unread task: opened, and marked
        $this->assertSame($unread['url'], $read($unread['task_key']));
        $this->assertContains($unread['task_key'], (new WorkflowTaskService())->seenKeys());
    }

    /**
     * countRemoved() counts the tasks taken off the list under the rest of the filter, whatever
     * 状態 says - such a task is neither read nor unread - and the 削除済み list counts itself. A
     * mark that outlived its task is none.
     *
     * @return void
     */
    public function testRemovedTasksAreCountedUnderTheRestOfTheFilter()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasks();
        if ($tasks->count() < 2) {
            $this->markTestSkipped('needs two un-actioned workflow tasks for this user');
        }
        $this->assertSame(0, (new WorkflowTaskService())->countRemoved(), 'precondition: nothing taken off the list');

        $first = $tasks->first();
        $last = $tasks->last();
        $this->assertSame(2, (new WorkflowTaskService())->hideSelected([$first['task_key'], $last['task_key']]));

        foreach (['', '0', '1', (string)WorkflowTaskService::SEEN_REMOVED] as $seen) {
            $this->assertSame(2, (new WorkflowTaskService(['seen' => $seen]))->countRemoved(), "状態 = '{$seen}'");
        }

        // narrowed like any list
        $sameTable = $first['custom_table_id'] == $last['custom_table_id'];
        $this->assertSame($sameTable ? 2 : 1, (new WorkflowTaskService(['custom_table_id' => (string)$first['custom_table_id']]))->countRemoved());
        $this->assertSame(1, (new WorkflowTaskService(['custom_table_id' => (string)$first['custom_table_id'], 'q' => '#' . $first['morph_id']]))->countRemoved());

        // a mark of a record that is nobody's task: there is no such record
        $userId = \Exment::getUserId();
        WorkflowTaskRead::insert([
            'target_user_id' => $userId,
            'custom_table_id' => $first['custom_table_id'],
            'morph_id' => 2147483647,
            'hidden_flg' => true,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
            'created_user_id' => $userId,
            'updated_user_id' => $userId,
        ]);
        $this->assertSame(2, (new WorkflowTaskService())->countRemoved());
    }

    /**
     * Somebody executes an action on the record of $task now.
     *
     * Every action writes a workflow value (WorkflowAction::forwardWorkflowValue()); this one is
     * not the record's latest, so its step - and with it this user's task - stays as it is, the
     * way another approver's approval leaves a step that waits for several of them. Only the
     * moment of the action is new. DatabaseTransactions rolls it back.
     *
     * @param array<string, mixed> $task a row of WorkflowTaskService::getTasks()
     * @return void
     */
    private function actOnRecordNow(array $task): void
    {
        $custom_table = CustomTable::getEloquent($task['custom_table_id']);
        $now = \Carbon\Carbon::now()->format('Y-m-d H:i:s');

        \DB::table(SystemTableName::WORKFLOW_VALUE)->insert([
            'suuid' => short_uuid(),
            'workflow_id' => Workflow::getWorkflowByTable($custom_table)->id,
            'morph_type' => $custom_table->table_name,
            'morph_id' => $task['morph_id'],
            'action_executed_flg' => 0,
            'latest_flg' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * A task is something the user can DO. An action whose execution condition (実行条件) does
     * not match the record has no button on the record page - so the record must not be listed
     * as a task either, although the work-user query, which only knows who an action is FOR,
     * still finds it.
     *
     * The test workflow's start action has two condition headers: one with a column condition
     * and one catch-all without any. Dropping the catch-all leaves an action that only runs
     * where the column condition matches - which is some of this user's records, not all.
     *
     * @return void
     */
    public function testRecordWhoseActionConditionFailsIsNotATask()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent('custom_value_edit');
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($workflow)) {
            $this->markTestSkipped('the test dataset has no workflow on custom_value_edit');
        }

        $catchAll = null;
        foreach ($workflow->workflow_actions_cache as $action) {
            if ($action->status_from != Define::WORKFLOW_START_KEYNAME) {
                continue;
            }
            $headers = collect($action->workflow_condition_headers_cache);
            $conditioned = $headers->filter(function ($header) {
                return count($header->workflow_conditions) > 0;
            });
            $unconditioned = $headers->filter(function ($header) {
                return count($header->workflow_conditions) == 0;
            });
            if ($conditioned->isNotEmpty() && $unconditioned->isNotEmpty()) {
                $catchAll = $unconditioned;
                break;
            }
        }
        if (!isset($catchAll)) {
            $this->markTestSkipped('the start action of the test workflow no longer has a conditioned header');
        }

        $filter = ['custom_table_id' => $custom_table->id];
        $before = (new WorkflowTaskService($filter))->getTasks()->pluck('morph_id')->all();
        if (empty($before)) {
            $this->markTestSkipped('this user has no task in custom_value_edit');
        }

        WorkflowConditionHeader::whereIn('id', $catchAll->pluck('id')->all())->delete();
        System::clearCache();

        // the record page is the reference: a record is a task exactly when it offers a button
        $executable = collect($before)->filter(function ($id) use ($custom_table) {
            return $custom_table->getValueModel($id)->getWorkflowActions(true, true)->isNotEmpty();
        })->values()->all();

        // otherwise the check below proves nothing
        $this->assertNotEmpty($executable, 'the dataset must keep some records whose action still runs');
        $this->assertLessThan(count($before), count($executable), 'the dataset must have records whose condition fails');

        $service = new WorkflowTaskService($filter);
        $listed = $service->getTasks()->pluck('morph_id')->all();
        sort($listed);
        sort($executable);

        $this->assertSame($executable, $listed, 'a record without an executable action is still listed as a task');
        $this->assertSame(count($executable), $service->countAll(), 'the total disagrees with the list');
        $this->assertSame(
            count($executable),
            (new WorkflowTaskService($filter))->getPage(1, 100)['total'],
            'the list screen disagrees with the list'
        );
        $this->assertSame(count($executable), (new WorkflowTaskService($filter))->countUnseen(), 'the badge still counts them');
    }

    /**
     * The list decides the execution condition in SQL, the record page in PHP - with the two
     * halves of the same view filter class (setFilter() / compareValue()). This pins them to
     * each other: for a spread of operators, column types (indexed or not, numbers stored as
     * JSON strings, dates, users) and header options, the records the list keeps must be
     * exactly the records whose page offers an action.
     *
     * @return void
     */
    public function testListAndRecordPageAgreeOnEveryKindOfCondition()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent('custom_value_edit');
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($workflow)) {
            $this->markTestSkipped('the test dataset has no workflow on custom_value_edit');
        }

        $start = collect($workflow->workflow_actions_cache)->first(function ($action) {
            return $action->status_from == Define::WORKFLOW_START_KEYNAME;
        });
        $headers = isset($start) ? collect($start->workflow_condition_headers_cache) : collect();
        if ($headers->isEmpty()) {
            $this->markTestSkipped('the start action of the test workflow has no condition header');
        }

        // Drafts of this user with EMPTY values - a missing key and an empty string - where the two
        // halves part ways: the record page drops an empty value before comparing (so "<>" and
        // "is not the login user" are true for it and a reversed header keeps it), while "<>" in
        // SQL drops NULL and NOT (NULL) is NULL. They are start-status tasks of their creator.
        $userId = \Exment::getUserId();
        $now = \Carbon\Carbon::now();
        foreach ([
            ['text' => 'workflow task test: no values', 'odd_even' => 'odd', 'multiples_of_3' => '1'],
            ['text' => 'workflow task test: no odd_even', 'user' => (string)$userId, 'date' => '2026-11-19', 'integer' => '1600', 'email' => 'test1@example.com'],
            ['text' => 'workflow task test: empty strings', 'odd_even' => '', 'email' => '', 'user' => ''],
        ] as $value) {
            \DB::table(getDBTableName($custom_table))->insert([
                'value' => json_encode($value),
                'created_user_id' => $userId,
                'updated_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $filter = ['custom_table_id' => $custom_table->id];
        $candidates = (new WorkflowTaskService($filter))->getTasks()->pluck('morph_id')->all();
        if (empty($candidates)) {
            $this->markTestSkipped('this user has no task in custom_value_edit');
        }

        // one header decides alone from here on
        $header = $headers->first();
        WorkflowConditionHeader::whereIn('id', $headers->pluck('id')->all())->where('id', '<>', $header->id)->delete();

        $column = function (string $name) use ($custom_table) {
            return \Exceedone\Exment\Model\CustomColumn::getEloquent($name, $custom_table)->id;
        };

        // [label, [[column, operator, value], ...], join, reverse]. Spelled out as a type: a list
        // this long is read as "array of anything", and every destructured value below with it.
        /** @var array<int, array{string, array<int, array{string, int, string|null}>, string, bool}> $cases */
        $cases = [
            ['yes/no =',              [['multiples_of_3', FilterOption::EQ, '1']], 'and', false],
            ['yes/no <>',             [['multiples_of_3', FilterOption::NE, '1']], 'and', false],
            ['indexed text =',        [['odd_even', FilterOption::EQ, 'odd']], 'and', false],
            ['indexed text like',     [['index_text', FilterOption::LIKE, 'index_002_00']], 'and', false],
            ['text like',             [['email', FilterOption::LIKE, 'test1']], 'and', false],
            ['text like, in the middle', [['email', FilterOption::LIKE, 'vartest']], 'and', false],
            ['text like, "_" is no wildcard', [['email', FilterOption::LIKE, 'test_']], 'and', false],
            ['text like, "%" is no wildcard', [['email', FilterOption::LIKE, 'foo%test']], 'and', false],
            ['text not like',         [['email', FilterOption::NOT_LIKE, 'test1']], 'and', false],
            ['indexed text not like', [['index_text', FilterOption::NOT_LIKE, '_00']], 'and', false],
            ['number stored as text >', [['integer', FilterOption::NUMBER_GT, '1500']], 'and', false],
            ['number stored as text >=', [['integer', FilterOption::NUMBER_GTE, '1600']], 'and', false],
            ['number stored as text <=', [['integer', FilterOption::NUMBER_LTE, '1600']], 'and', false],
            ['number stored as text =', [['integer', FilterOption::EQ, '1600']], 'and', false],
            ['decimal <',             [['decimal', FilterOption::NUMBER_LT, '1']], 'and', false],
            ['decimal <>',            [['decimal', FilterOption::NE, '0.22']], 'and', false],
            ['date on',               [['date', FilterOption::DAY_ON, '2026-11-19']], 'and', false],
            ['date on or after',      [['date', FilterOption::DAY_ON_OR_AFTER, '2026-11-01']], 'and', false],
            ['date on or before',     [['date', FilterOption::DAY_ON_OR_BEFORE, '2026-11-01']], 'and', false],
            ['date today or after',   [['date', FilterOption::DAY_TODAY_OR_AFTER, null]], 'and', false],
            ['date this month',       [['date', FilterOption::DAY_THIS_MONTH, null]], 'and', false],
            ['date next month',       [['date', FilterOption::DAY_NEXT_MONTH, null]], 'and', false],
            ['date not null',         [['date', FilterOption::DAY_NOT_NULL, null]], 'and', false],
            ['not null',              [['init_text', FilterOption::NOT_NULL, null]], 'and', false],
            ['null',                  [['init_text', FilterOption::NULL, null]], 'and', false],
            ['user is login user',    [['user', FilterOption::USER_EQ_USER, null]], 'and', false],
            ['user is not login user', [['user', FilterOption::USER_NE_USER, null]], 'and', false],
            ['user is user2',         [['user', FilterOption::USER_EQ, '3']], 'and', false],
            ['user is not user2',     [['user', FilterOption::USER_NE, '3']], 'and', false],
            ['user not null',         [['user', FilterOption::USER_NOT_NULL, null]], 'and', false],
            ['two conditions, or',    [['odd_even', FilterOption::EQ, 'odd'], ['multiples_of_3', FilterOption::EQ, '1']], 'or', false],
            ['two conditions, and',   [['odd_even', FilterOption::EQ, 'odd'], ['multiples_of_3', FilterOption::EQ, '1']], 'and', false],
            ['two conditions, and, reversed', [['odd_even', FilterOption::EQ, 'odd'], ['multiples_of_3', FilterOption::EQ, '1']], 'and', true],
            // the empty values of the drafts above
            ['indexed text <>, empty value', [['odd_even', FilterOption::NE, 'odd']], 'and', false],
            ['indexed text =, reversed', [['odd_even', FilterOption::EQ, 'odd']], 'and', true],
            ['text like, reversed',   [['email', FilterOption::LIKE, 'test1']], 'and', true],
            ['text not like, reversed', [['email', FilterOption::NOT_LIKE, 'test1']], 'and', true],
            ['number >, reversed',    [['integer', FilterOption::NUMBER_GT, '1500']], 'and', true],
            ['date on, reversed',     [['date', FilterOption::DAY_ON, '2026-11-19']], 'and', true],
            ['null, reversed',        [['init_text', FilterOption::NULL, null]], 'and', true],
            ['user is login user, reversed', [['user', FilterOption::USER_EQ_USER, null]], 'and', true],
            ['user is not login user, empty user', [['user', FilterOption::USER_NE_USER, null]], 'and', false],
            ['user is not user2, empty user', [['user', FilterOption::USER_NE, '3']], 'and', false],
            ['two conditions, or, reversed', [['odd_even', FilterOption::EQ, 'odd'], ['user', FilterOption::USER_EQ_USER, null]], 'or', true],
        ];

        // collected rather than asserted one by one, so a failure shows every operator that drifts
        $mismatches = [];
        foreach ($cases as [$label, $conditions, $join, $reverse]) {
            \Exceedone\Exment\Model\Condition::where('morph_type', 'workflow_condition_header')
                ->where('morph_id', $header->id)
                ->delete();
            foreach ($conditions as [$name, $operator, $value]) {
                \Exceedone\Exment\Model\Condition::create([
                    'morph_type' => 'workflow_condition_header',
                    'morph_id' => $header->id,
                    'condition_type' => \Exceedone\Exment\Enums\ConditionType::COLUMN,
                    'condition_key' => $operator,
                    'target_column_id' => $column($name),
                    'condition_value' => $value,
                ]);
            }
            \DB::table((new WorkflowConditionHeader())->getTable())->where('id', $header->id)->update([
                'options' => json_encode(['condition_join' => $join, 'condition_reverse' => $reverse ? '1' : '0']),
            ]);
            System::clearCache();

            $expected = collect($candidates)->filter(function ($id) use ($custom_table) {
                return $custom_table->getValueModel($id)->getWorkflowActions(true, true)->isNotEmpty();
            })->sort()->values()->all();

            $listed = (new WorkflowTaskService($filter))->getTasks()->pluck('morph_id')->sort()->values()->all();

            $mismatches[$label] = $expected === $listed ? null : ['page' => $expected, 'list' => $listed];
        }

        $this->assertSame([], array_filter($mismatches), 'the list and the record page disagree');
    }

    /**
     * The same pinning for the column types the workflow test table does not have - multi-value
     * select, user and organization columns, whose empty value is stored as [] - decided on
     * the one test table that has every type. It needs no workflow: the SQL of a condition header
     * (conditionHeaders() + whereExecutionCondition()) is set against the record page's check of
     * the same header (WorkflowConditionHeader::isMatchCondition()), record by record.
     *
     * @return void
     */
    public function testConditionSqlAgreesWithTheRecordPageOnEveryEmptyValue()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent('all_columns_table_fortest');
        if (!isset($custom_table)) {
            $this->markTestSkipped('the test dataset has no all_columns_table_fortest');
        }
        $tableName = getDBTableName($custom_table);

        // [column, operator, value]; "sample" = the first value the column holds
        $cases = [
            ['text', FilterOption::EQ, 'sample'],
            ['text', FilterOption::NE, 'sample'],
            ['text', FilterOption::NOT_LIKE, 'sample'],
            ['text', FilterOption::NULL, null],
            ['date', FilterOption::DAY_ON_OR_AFTER, 'sample'],
            ['date', FilterOption::DAY_NULL, null],
            ['user', FilterOption::USER_EQ_USER, null],
            ['user', FilterOption::USER_NE_USER, null],
            ['user', FilterOption::USER_NE, 'sample'],
            ['select', FilterOption::SELECT_NOT_EXISTS, 'sample'],
            ['select_multiple', FilterOption::SELECT_EXISTS, 'sample'],
            ['select_multiple', FilterOption::SELECT_NOT_EXISTS, 'sample'],
            ['select_multiple', FilterOption::NULL, null],
            ['select_multiple', FilterOption::NOT_NULL, null],
            ['select_table_multiple', FilterOption::NULL, null],
            ['user_multiple', FilterOption::USER_NE_USER, null],
            ['user_multiple', FilterOption::USER_NULL, null],
            ['organization_multiple', FilterOption::SELECT_NOT_EXISTS, 'sample'],
        ];

        // every record with an empty value in one of these columns, and a few without
        $records = getModelName($custom_table)::withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->get();
        $records = $records->filter(function ($record, $index) use ($cases) {
            foreach ($cases as [$name]) {
                if (is_nullorempty(array_get($record->value, $name))) {
                    return true;
                }
            }
            return $index < 5;
        })->values();
        if ($records->count() < 10) {
            $this->markTestSkipped('all_columns_table_fortest has too few empty values to test with');
        }
        $ids = $records->pluck('id')->all();

        $planHeaders = new \ReflectionMethod(WorkflowTaskService::class, 'conditionHeaders');
        $planHeaders->setAccessible(true);
        $whereHeaders = new \ReflectionMethod(WorkflowTaskService::class, 'whereExecutionCondition');
        $whereHeaders->setAccessible(true);
        $service = new WorkflowTaskService();

        $mismatches = [];
        foreach ($cases as [$name, $operator, $value]) {
            $custom_column = \Exceedone\Exment\Model\CustomColumn::getEloquent($name, $custom_table);
            if ($value === 'sample') {
                $value = $records->map(function ($record) use ($name) {
                    return collect((array)array_get($record->value, $name))->first();
                })->first(function ($value) {
                    return !is_nullorempty($value);
                });
                if (in_array(FilterOption::VALUE_TYPE($operator), [\Exceedone\Exment\Enums\FilterType::SELECT], true)) {
                    $value = [$value];
                }
            }

            foreach ([false, true] as $reverse) {
                $header = new WorkflowConditionHeader();
                $header->setOption('condition_join', 'and');
                $header->setOption('condition_reverse', $reverse ? '1' : '0');
                $header->setRelation('workflow_conditions', collect([new \Exceedone\Exment\Model\Condition([
                    'condition_type' => \Exceedone\Exment\Enums\ConditionType::COLUMN,
                    'condition_key' => $operator,
                    'target_column_id' => $custom_column->id,
                    'condition_value' => $value,
                ])]));
                // an action whose only header is the one above. No constructor of its own: ModelBase
                // is @phpstan-consistent-constructor, and booting the model registers its observers
                // with an instance built with no argument at all (HasEvents::registerObserver())
                $action = new class () extends WorkflowAction {
                    /** @var WorkflowConditionHeader */
                    public $testHeader;

                    /**
                     * @return \Illuminate\Support\Collection<int, WorkflowConditionHeader>
                     */
                    public function getWorkflowConditionHeadersCacheAttribute()
                    {
                        return new \Illuminate\Support\Collection([$this->testHeader]);
                    }
                };
                $action->testHeader = $header;

                $page = $records->filter(function ($record) use ($header) {
                    return $header->isMatchCondition($record);
                })->pluck('id')->map(function ($id) {
                    return (int)$id;
                })->sort()->values()->all();

                $headers = $planHeaders->invoke(null, $action, $custom_table, $tableName);
                $this->assertNotNull($headers, "{$name} {$operator} must be decided in SQL");
                $query = \DB::table($tableName)->whereIn($tableName . '.id', $ids);
                $query->where(function ($query) use ($whereHeaders, $service, $headers, $custom_table) {
                    $whereHeaders->invoke($service, $query, $headers, $custom_table);
                });
                $list = $query->pluck($tableName . '.id')->map(function ($id) {
                    return (int)$id;
                })->sort()->values()->all();

                $label = "{$name} {$operator} " . json_encode($value) . ($reverse ? ' reversed' : '');
                $mismatches[$label] = $page === $list ? null : ['page' => $page, 'list' => $list];
            }
        }

        $this->assertSame([], array_filter($mismatches), 'the list and the record page disagree');
    }

    /**
     * A step that needs several approvers keeps its status until the last of them acts. The one
     * who already approved is still a work user of that status, but the record page has nothing
     * left for them to press (WorkflowValue::isAlreadyExecuted) - so it is not their task any
     * more. The approvers who have not acted yet still have it.
     *
     * Built on the common test workflow: start -(creator)-> middle -(fixed users)-> ..., with the
     * second step switched to "two approvers: user1 and user2" for this test only.
     *
     * @return void
     */
    public function testApproverWhoAlreadyActedIsNotAskedAgain()
    {
        $this->init();

        $custom_table = CustomTable::getEloquent('custom_value_edit_all');
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($workflow)) {
            $this->markTestSkipped('the test dataset has no workflow on custom_value_edit_all');
        }

        $actions = collect($workflow->workflow_actions_cache);
        $first = $actions->first(function ($action) {
            return $action->status_from == Define::WORKFLOW_START_KEYNAME;
        });
        $firstHeader = isset($first) ? collect($first->workflow_condition_headers_cache)->first() : null;
        $firstStatusTo = isset($firstHeader) ? $firstHeader->status_to : null;
        $second = isset($firstStatusTo) ? $actions->first(function ($action) use ($firstStatusTo) {
            return (string)$action->status_from === (string)$firstStatusTo;
        }) : null;
        if (!isset($second)) {
            $this->markTestSkipped('the common test workflow no longer has two consecutive steps');
        }

        $user1 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        if (!isset($user2)) {
            $this->markTestSkipped('needs user2 in the test dataset');
        }

        // second step: two approvers out of user1 and user2 (rolled back after the test)
        \DB::table(SystemTableName::WORKFLOW_ACTION)->where('id', $second->id)->update([
            'options' => json_encode(array_merge((array)$second->options, [
                'work_target_type' => 'fix',
                'flow_next_type' => 'some',
                'flow_next_count' => '2',
            ])),
        ]);
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->where('workflow_action_id', $second->id)->delete();
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            ['related_id' => $user1->base_user_id, 'related_type' => 'user', 'workflow_action_id' => $second->id],
            ['related_id' => $user2->base_user_id, 'related_type' => 'user', 'workflow_action_id' => $second->id],
        ]);
        System::clearCache();

        // one of user1's own records, still at the start
        $filter = ['custom_table_id' => $custom_table->id];
        $task = (new WorkflowTaskService($filter))->getTasks()->first();
        if (is_null($task)) {
            $this->markTestSkipped('user1 has no task in custom_value_edit_all');
        }
        $key = $task['task_key'];

        // user1 starts the flow: the record moves to the two-approver step
        $custom_value = $custom_table->getValueModel($task['morph_id']);
        WorkflowAction::getEloquent($first->id)->executeAction($custom_value, ['comment' => 'workflow task test']);
        System::clearCache();

        $this->assertTrue(
            (new WorkflowTaskService($filter))->getTasks()->contains('task_key', $key),
            'precondition: user1 is one of the two approvers of the new step'
        );

        // user1 approves: one of two, so the status stays where it is
        $custom_value = $custom_table->getValueModel($task['morph_id']);
        WorkflowAction::getEloquent($second->id)->executeAction($custom_value, ['comment' => 'workflow task test']);
        System::clearCache();

        $this->assertSame(
            (string)$firstStatusTo,
            (string)$custom_table->getValueModel($task['morph_id'])->workflow_value->workflow_status_to_id,
            'precondition: one approval out of two must not move the status'
        );
        $this->assertFalse(
            (new WorkflowTaskService($filter))->getTasks()->contains('task_key', $key),
            'user1 already approved - the task must leave their list'
        );

        // user2 has not acted yet, so for them it is still a task
        $this->be($user2);
        System::clearCache();
        $this->assertTrue(
            (new WorkflowTaskService($filter))->getTasks()->contains('task_key', $key),
            'the approver who has not acted yet lost the task'
        );
    }

    /**
     * A special action (特殊なアクション, ignore_work) makes nobody a work user - on the start status
     * too. The settings screen says so, and the value view drops such actions; the start view does
     * not. Found in review: a special 代理申請 at the start put every record not started yet onto
     * the list and the badge of everybody allowed to run it. The record page offers them the
     * button - as an extra, not as work.
     *
     * @return void
     */
    public function testSpecialStartActionMakesNoTask()
    {
        $this->init(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        if (!isset($custom_table) || !isset($workflow)) {
            $this->markTestSkipped('the test dataset has no workflow on ' . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        }
        /** @var WorkflowStatus|null $status */
        $status = WorkflowStatus::where('workflow_id', $workflow->id)->orderBy('order')->first();
        if (!isset($status)) {
            $this->markTestSkipped('the test workflow has no status to lead to');
        }

        $filter = ['custom_table_id' => $custom_table->id];
        $listed = function () use ($filter) {
            return (new WorkflowTaskService($filter))->getTasks()->pluck('task_key')->sort()->values()->all();
        };
        $before = $listed();
        $unseenBefore = (new WorkflowTaskService($filter))->countUnseen();

        // a 代理申請 on the start status, for the login user alone
        $now = \Carbon\Carbon::now();
        $actionId = \DB::table(SystemTableName::WORKFLOW_ACTION)->insertGetId([
            'workflow_id' => $workflow->id,
            'status_from' => Define::WORKFLOW_START_KEYNAME,
            'action_name' => 'workflow task test: special',
            'ignore_work' => 1,
            'options' => json_encode(['flow_next_type' => 'some', 'flow_next_count' => '1', 'work_target_type' => 'fix']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \DB::table((new WorkflowConditionHeader())->getTable())->insert([
            'workflow_action_id' => $actionId,
            'status_to' => $status->id,
            'enabled_flg' => 1,
            'options' => json_encode(['condition_join' => 'and', 'condition_reverse' => '0']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            'related_id' => \Exment::getUserId(),
            'related_type' => 'user',
            'workflow_action_id' => $actionId,
        ]);
        System::clearCache();

        // the record page offers the button on records that have not started...
        $tableName = getDBTableName($custom_table);
        $notStarted = $custom_table->getValueQuery()
            ->whereNotExists(function ($query) use ($custom_table, $tableName) {
                $query->selectRaw('1')
                    ->from(SystemTableName::WORKFLOW_VALUE)
                    ->whereColumn(SystemTableName::WORKFLOW_VALUE . '.morph_id', $tableName . '.id')
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name);
            })
            ->take(5)
            ->pluck($tableName . '.id');
        $offered = $notStarted->filter(function ($id) use ($custom_table, $actionId) {
            return $custom_table->getValueModel($id)->getWorkflowActions(true)->contains(function ($action) use ($actionId) {
                return $action->id == $actionId;
            });
        });
        if ($offered->isEmpty()) {
            $this->markTestSkipped('this user sees no record that has not started');
        }

        // ...and none of them becomes a task
        $this->assertSame($before, $listed(), 'a special action must not put the records it may run on onto the list');
        $this->assertSame($unseenBefore, (new WorkflowTaskService($filter))->countUnseen(), 'nor onto the badge');
    }

    /**
     * The same two-approver step, with approvers the PREVIOUS step picked when it was executed
     * (work_target_type "action_select", 実行時に選択). Those picks are stored on the workflow value
     * that step wrote. The first approval writes a newer value, and the record page looks back past
     * it for the picks (WorkflowValue::getWorkflowValueAutorities()) - so the approver who has not
     * acted yet still gets the button, and must still find the task on the list.
     *
     * @return void
     */
    public function testApproversPickedByThePreviousStepKeepTheTaskUntilTheyAct()
    {
        $this->init();
        $scenario = $this->pickedApproversScenario();
        $recordPageOffers = $scenario['recordPageOffers'];
        $listed = $scenario['listed'];

        foreach ($scenario['approvers'] as $name => $user) {
            $this->be($user);
            $this->assertTrue($recordPageOffers(), "precondition: $name was picked as an approver of the third step");
            $this->assertTrue($listed(), "$name was picked as an approver, so the record is their task");
        }

        // user1 approves: one of two, so the status stays where it is
        $this->be($scenario['approvers']['user1']);
        $scenario['approve']();
        $this->assertSame($scenario['waitingStatus'], $scenario['status'](), 'precondition: one approval out of two must not move the status');

        $this->assertFalse($recordPageOffers(), 'precondition: user1 already approved');
        $this->assertFalse($listed(), 'user1 already approved - the task must leave their list');

        $this->be($scenario['approvers']['user2']);
        $this->assertTrue($recordPageOffers(), 'precondition: the record page still asks user2 to approve');
        $this->assertTrue($listed(), 'user2 has not approved yet, and lost the task after user1 approved');

        // user2 approves too: the step is done and nobody is asked any more
        $scenario['approve']();
        $this->assertNotSame($scenario['waitingStatus'], $scenario['status'](), 'precondition: the second approval moves the record on');
        foreach ($scenario['approvers'] as $name => $user) {
            $this->be($user);
            $this->assertFalse($listed(), "the step is done, yet $name still has the task");
        }
    }

    /**
     * A record that was half approved before the fix above has no picks on its newest workflow
     * value. exment:patchdata workflow_value_authorities (run by a migration) gives them back, and
     * running it again changes nothing.
     *
     * @return void
     */
    public function testRecordsHalfApprovedBeforeTheFixGetTheirApproversBack()
    {
        $this->init();
        $scenario = $this->pickedApproversScenario();

        $this->be($scenario['approvers']['user1']);
        $scenario['approve']();

        // what an older version left behind: the newest value carries no picks
        $newest = WorkflowValue::where('morph_type', $scenario['custom_table']->table_name)
            ->where('morph_id', $scenario['id'])
            ->where('latest_flg', true)
            ->first();
        \DB::table(SystemTableName::WORKFLOW_VALUE_AUTHORITY)->where('workflow_value_id', $newest->id)->delete();

        $this->be($scenario['approvers']['user2']);
        $this->assertTrue($scenario['recordPageOffers'](), 'precondition: the record page still asks user2 to approve');
        $this->assertFalse($scenario['listed'](), 'precondition: without the picks on the newest value, the list misses the task');

        $this->assertSame(0, \Artisan::call('exment:patchdata', ['action' => 'workflow_value_authorities']));
        $this->assertTrue($scenario['listed'](), 'the patch did not give user2 the task back');

        $picks = function () use ($scenario) {
            return \DB::table(SystemTableName::WORKFLOW_VALUE_AUTHORITY)
                ->join(SystemTableName::WORKFLOW_VALUE, SystemTableName::WORKFLOW_VALUE . '.id', SystemTableName::WORKFLOW_VALUE_AUTHORITY . '.workflow_value_id')
                ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $scenario['custom_table']->table_name)
                ->where(SystemTableName::WORKFLOW_VALUE . '.morph_id', $scenario['id'])
                ->orderBy(SystemTableName::WORKFLOW_VALUE_AUTHORITY . '.workflow_value_id')
                ->orderBy('related_type')
                ->orderBy('related_id')
                ->get(['workflow_value_id', 'related_type', 'related_id'])
                ->map(function ($row) {
                    return $row->workflow_value_id . ':' . $row->related_type . ':' . $row->related_id;
                })
                ->all();
        };
        $before = $picks();
        $this->assertSame(0, \Artisan::call('exment:patchdata', ['action' => 'workflow_value_authorities']));
        $this->assertSame($before, $picks(), 'running the patch again must change nothing');
    }

    /**
     * The common test workflow (custom_value_edit_all) turned into: user1 runs its second step and
     * picks user1 and user2 as the approvers of the third, two of whom must approve. One of user1's
     * records is taken through the first two steps. Rolled back with the test.
     *
     * @return array{custom_table: CustomTable, id: int, approvers: array<string, LoginUser>, waitingStatus: string, status: \Closure, approve: \Closure, recordPageOffers: \Closure, listed: \Closure}
     */
    private function pickedApproversScenario(): array
    {
        $custom_table = CustomTable::getEloquent('custom_value_edit_all');
        $workflow = isset($custom_table) ? Workflow::getWorkflowByTable($custom_table) : null;
        // both named: a set $workflow implies a set $custom_table, which the analyser cannot tell
        if (!isset($custom_table) || !isset($workflow)) {
            $this->markTestSkipped('the test dataset has no workflow on custom_value_edit_all');
        }

        // the three consecutive steps of the workflow
        $actions = collect($workflow->workflow_actions_cache);
        $after = function ($statusFrom) use ($actions) {
            return is_null($statusFrom) ? null : $actions->first(function ($action) use ($statusFrom) {
                return (string)$action->status_from === (string)$statusFrom;
            });
        };
        $statusTo = function ($action) {
            $header = isset($action) ? collect($action->workflow_condition_headers_cache)->first() : null;
            return isset($header) ? $header->status_to : null;
        };
        $first = $after(Define::WORKFLOW_START_KEYNAME);
        $second = $after($statusTo($first));
        $third = $after($statusTo($second));
        if (!isset($third)) {
            $this->markTestSkipped('the common test workflow no longer has three consecutive steps');
        }

        $user1 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        if (!isset($user1) || !isset($user2)) {
            $this->markTestSkipped('needs user1 and user2 in the test dataset');
        }

        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->where('workflow_action_id', $second->id)->delete();
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            ['related_id' => $user1->base_user_id, 'related_type' => 'user', 'workflow_action_id' => $second->id],
        ]);
        \DB::table(SystemTableName::WORKFLOW_ACTION)->where('id', $third->id)->update([
            'options' => json_encode(array_merge((array)$third->options, [
                'work_target_type' => 'action_select',
                'flow_next_type' => 'some',
                'flow_next_count' => '2',
            ])),
        ]);
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->where('workflow_action_id', $third->id)->delete();
        System::clearCache();

        // one of user1's own records, still at the start
        $filter = ['custom_table_id' => $custom_table->id];
        $task = (new WorkflowTaskService($filter))->getTasks()->first();
        if (is_null($task)) {
            $this->markTestSkipped('user1 has no task in custom_value_edit_all');
        }
        $key = $task['task_key'];
        $id = (int)$task['morph_id'];

        WorkflowAction::getEloquent($first->id)->executeAction($custom_table->getValueModel($id), ['comment' => 'workflow task test']);
        System::clearCache();
        WorkflowAction::getEloquent($second->id)->executeAction($custom_table->getValueModel($id), [
            'comment' => 'workflow task test',
            'next_work_users' => ['user_' . $user1->base_user_id, 'user_' . $user2->base_user_id],
        ]);
        System::clearCache();

        return [
            'custom_table' => $custom_table,
            'id' => $id,
            'approvers' => ['user1' => $user1, 'user2' => $user2],
            'waitingStatus' => (string)$statusTo($second),
            'status' => function () use ($custom_table, $id) {
                System::clearCache();
                return (string)$custom_table->getValueModel($id)->workflow_value->workflow_status_to_id;
            },
            // the login user executes the third step
            'approve' => function () use ($custom_table, $id, $third) {
                System::clearCache();
                WorkflowAction::getEloquent($third->id)->executeAction($custom_table->getValueModel($id), ['comment' => 'workflow task test']);
            },
            // what the record page offers the login user: the button of the third step, with a
            // submit in its dialog (WorkflowAction::actionModal() drops it for who already approved)
            'recordPageOffers' => function () use ($custom_table, $id, $third) {
                System::clearCache();
                $custom_value = $custom_table->getValueModel($id);

                return $custom_value->getWorkflowActions(true, true)->contains('id', $third->id)
                    && !WorkflowValue::isAlreadyExecuted($third->id, $custom_value, \Exment::user()->base_user);
            },
            'listed' => function () use ($filter, $key) {
                System::clearCache();

                return (new WorkflowTaskService($filter))->getTasks()->contains('task_key', $key);
            },
        ];
    }

    /**
     * Before anything is marked, the dropdown shows the same newest tasks whether it is asked
     * for the unseen ones or for all pending ones - the two must not drift apart.
     *
     * @return void
     */
    public function testTopPendingAndTopUnseenAgreeWhileNothingIsSeen()
    {
        $this->init();

        $service = new WorkflowTaskService();

        if ($service->countAll() < 1) {
            $this->markTestSkipped('this user has no workflow task');
        }
        if ($service->countUnseen() !== $service->countAll()) {
            $this->markTestSkipped('this user already has seen tasks');
        }

        $this->assertSame(
            $service->topUnseen()->pluck('task_key')->all(),
            (new WorkflowTaskService())->topPending()->pluck('task_key')->all(),
            'with nothing seen the two readers must return the same rows'
        );
    }

    /**
     * The batch "mark as seen" must pay for the SELECTION, not for the dataset: its queries
     * may touch the tables the submitted keys name - never the other workflow tables, whose
     * pending lists can be arbitrarily long.
     *
     * @return void
     */
    public function testMarkSeenSelectedOnlyQueriesTheTablesOfTheSelection()
    {
        $this->init();

        $tasks = (new WorkflowTaskService())->getTasksWithSeen()->filter(function ($row) {
            return !$row['seen'];
        });
        $byTable = $tasks->groupBy('custom_table_id');
        if ($byTable->count() < 2) {
            $this->markTestSkipped('needs unseen tasks in at least two workflow tables');
        }

        $picked = $byTable->first();
        $otherNames = $byTable->keys()->slice(1)->map(function ($customTableId) {
            return getDBTableName(CustomTable::getEloquent($customTableId));
        });

        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $marked = (new WorkflowTaskService())->markSeenSelected($picked->pluck('task_key')->all());
            $queries = collect($connection->getQueryLog())->pluck('query');
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame($picked->count(), $marked, 'every submitted, still-unseen task is marked');

        foreach ($otherNames as $otherName) {
            $matches = $queries->filter(function ($sql) use ($otherName) {
                return strpos($sql, $otherName) !== false;
            });
            $this->assertCount(0, $matches, "a selection inside one table must not read {$otherName}");
        }
    }

    /**
     * The navbar payload cache key is stable while nothing changes, and every own write moves
     * it - which is what guarantees the very next poll after "mark as seen" is fresh, while
     * idle polls keep answering from the cache.
     *
     * @return void
     */
    public function testNavbarCacheKeyMovesOnEveryOwnWrite()
    {
        $this->init();

        $key = WorkflowTaskService::navbarCacheKey();
        $this->assertNotNull($key);
        $this->assertSame($key, WorkflowTaskService::navbarCacheKey(), 'reading must not move the key');

        (new WorkflowTaskService())->markSeen([WorkflowTaskService::taskKey(4294967295, 1)]);
        $afterMark = WorkflowTaskService::navbarCacheKey();
        $this->assertNotSame($key, $afterMark, 'marking as seen must move the key');

        (new WorkflowTaskService())->markAllUnseen();
        $this->assertNotSame($afterMark, WorkflowTaskService::navbarCacheKey(), 'unmarking must move it again');
    }

    /**
     * An own write moves the navbar key on every cache store, and removes the payload it ends.
     *
     * Found in review. The version was moved with Cache::increment(), which the database and
     * memcached stores answer with false for a key that does not exist yet, storing nothing: the
     * version stayed 0 there, and the badge stale for a whole poll interval after "mark as seen".
     * On the file store the version did move, but every old payload stayed on disk for good - that
     * store deletes an expired entry only when the very same key is read again.
     *
     * The database store is Laravel's own, on a temporary table of the test connection: MySQL
     * creates and drops a temporary table without ending the transaction the test runs in.
     *
     * @return void
     */
    public function testNavbarCacheKeyMovesOnEveryStoreAndLeavesNothingBehind()
    {
        $this->init();

        // the store the tests run on, whichever it is
        $key = (string)WorkflowTaskService::navbarCacheKey();
        \Cache::put($key, ['count' => 1], 60);
        WorkflowTaskService::navbarCacheForget();
        $this->assertNotSame($key, WorkflowTaskService::navbarCacheKey(), 'an own write must move the key');
        $this->assertFalse(\Cache::has($key), 'the payload of the old version must not stay behind');

        if (!in_array(\DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('the database store part creates its table the MySQL way');
        }

        \DB::statement('CREATE TEMPORARY TABLE wf_task_cache_probe (`key` VARCHAR(255) NOT NULL PRIMARY KEY, `value` MEDIUMTEXT NOT NULL, `expiration` INT NOT NULL)');
        $store = new \Illuminate\Cache\DatabaseStore(\DB::connection(), 'wf_task_cache_probe');
        \Cache::extend('wf_task_database', function () use ($store) {
            return \Cache::repository($store);
        });
        config(['cache.stores.wf_task_database' => ['driver' => 'wf_task_database']]);
        $default = \Cache::getDefaultDriver();
        \Cache::setDefaultDriver('wf_task_database');

        try {
            $this->assertFalse(\Cache::increment('wf_task_probe'), 'this store must answer increment() the way the database store does');

            $key = (string)WorkflowTaskService::navbarCacheKey();
            \Cache::put($key, ['count' => 1], 60);
            WorkflowTaskService::navbarCacheForget();

            $this->assertNotSame($key, WorkflowTaskService::navbarCacheKey(), 'on the database store an own write must move the key too');
            $this->assertFalse(\Cache::has($key), 'and remove the payload of the old version');
        } finally {
            \Cache::setDefaultDriver($default);
            \DB::statement('DROP TEMPORARY TABLE IF EXISTS wf_task_cache_probe');
        }
    }

    /**
     * A write core makes inside a transaction - an action, a record delete - moves the navbar
     * version once the transaction has committed: not inside it, and not at all when it is rolled
     * back.
     *
     * Found in review. On the database cache store the version is a row of the cache table:
     * written inside the transaction of an action, two actions at once could deadlock on that table,
     * and InnoDB rolled the whole action back while the error was swallowed. On every store, a poll
     * between the move and the commit stored the old list under the new version.
     *
     * A request has no transaction around it; this test has one. Laravel's testing manager takes
     * that one for the wrapper - as its own DatabaseTransactions does - so the one opened here
     * commits the way an action does.
     *
     * @return void
     */
    public function testNavbarVersionOfAWriteInATransactionMovesOnCommit()
    {
        $this->init();

        $connection = \DB::connection();
        $original = app('db.transactions');
        // Laravel 11+ is told which connections the wrapper belongs to and skips that many
        $manager = new \Illuminate\Foundation\Testing\DatabaseTransactionsManager([$connection->getName()]);
        $manager->begin($connection->getName(), $connection->transactionLevel());
        $connection->setTransactionManager($manager);

        try {
            $key = WorkflowTaskService::navbarCacheKey();

            $connection->beginTransaction();
            WorkflowTaskService::navbarCacheForgetAfterCommit();
            $this->assertSame($key, WorkflowTaskService::navbarCacheKey(), 'not while the transaction is open');
            $connection->rollBack();
            $this->assertSame($key, WorkflowTaskService::navbarCacheKey(), 'a transaction rolled back changed nothing');

            $connection->beginTransaction();
            WorkflowTaskService::navbarCacheForgetAfterCommit();
            $this->assertSame($key, WorkflowTaskService::navbarCacheKey());
            $connection->commit();
            $this->assertNotSame($key, WorkflowTaskService::navbarCacheKey(), 'once it has committed');

            // outside a transaction: right away
            $key = WorkflowTaskService::navbarCacheKey();
            WorkflowTaskService::navbarCacheForgetAfterCommit();
            $this->assertNotSame($key, WorkflowTaskService::navbarCacheKey());
        } finally {
            $connection->setTransactionManager($original);
        }
    }

    /**
     * Two navbar polls inside one interval cost one scan: the second answers from the cache
     * without a single query. This is what keeps "every open browser, every few minutes" from
     * multiplying into a permanent background load as the tables grow - and one own write
     * later, the poll recomputes.
     *
     * @return void
     */
    public function testNavbarPollAnswersFromTheCacheWithinTheInterval()
    {
        $this->init();

        $controller = new \Exceedone\Exment\Controllers\ApiController();
        $request = Request::create('/webapi/workflowTaskPage', 'GET');

        $first = $controller->workflowTaskPage($request);

        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $second = $controller->workflowTaskPage($request);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame(json_encode($first), json_encode($second), 'the cached answer is the same answer');
        $this->assertCount(0, $queries, 'the second poll inside the interval must be served from the cache');

        (new WorkflowTaskService())->markAllUnseen();

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $controller->workflowTaskPage($request);
            $count = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertGreaterThan(0, $count, 'after an own write the poll must recompute');
    }

    /**
     * The page must not read the tables its own total already proved are empty.
     *
     * Every workflow table costs one full work-user scan, and an installation collects
     * workflow tables over time - most of which a given user has nothing pending in. Reading
     * them to fetch nothing is the part of the list screen that grows with the INSTALLATION
     * rather than with the user's work.
     *
     * @return void
     */
    public function testGetPageDoesNotReadTablesWithNothingPending()
    {
        $this->init();

        $service = new WorkflowTaskService();
        $counts = new \ReflectionMethod($service, 'countAllPerTable');
        $counts->setAccessible(true);
        $all = new \ReflectionMethod($service, 'workflowCustomTables');
        $all->setAccessible(true);

        /** @var array<int, int> $pending */
        $pending = $counts->invoke($service);
        /** @var \Illuminate\Support\Collection<int, CustomTable> $allTables */
        $allTables = $all->invoke($service);
        $empty = $allTables->except(array_keys($pending));
        if ($empty->isEmpty()) {
            $this->markTestSkipped('every workflow table has a pending task for this user');
        }

        $countQueries = function (callable $work) {
            $connection = \DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            try {
                $result = $work();
                $queries = collect($connection->getQueryLog())->pluck('query');
            } finally {
                $connection->disableQueryLog();
            }

            return [$result, $queries];
        };

        // the total has to ask every table how much it holds - that is how the empty ones are
        // found in the first place. What must NOT happen is reading them a second time.
        [, $countOnly] = $countQueries(function () {
            return (new WorkflowTaskService())->countAll();
        });
        [$rows, $wholePage] = $countQueries(function () {
            return (new WorkflowTaskService())->getPage(1, 20)['rows'];
        });

        $mentions = function ($queries, $name) {
            return $queries->filter(function ($sql) use ($name) {
                return strpos($sql, $name) !== false;
            })->count();
        };

        foreach ($empty as $custom_table) {
            $name = getDBTableName($custom_table);
            $this->assertSame(
                $mentions($countOnly, $name),
                $mentions($wholePage, $name),
                "{$name} holds nothing pending: counting it is enough, the page must not read it again"
            );
        }

        // ...while a table that DOES hold pending rows is read again, for its rows
        /** @var \Illuminate\Support\Collection<int, CustomTable> $allTables */
        $allTables = $all->invoke($service);
        $withRows = $allTables->only(array_keys($pending))->first();
        $withRowsName = getDBTableName($withRows);
        $this->assertGreaterThan(
            $mentions($countOnly, $withRowsName),
            $mentions($wholePage, $withRowsName),
            'a table holding pending rows must still be read for them'
        );

        // the page is still a full page of real tasks; that skipping empty tables changes
        // nothing about WHICH tasks are shown is what
        // testGetPageReturnsTheSameTasksAsTheFullList() walks every page to prove
        $this->assertSame(min(20, array_sum($pending)), $rows->count());
    }

    /**
     * With a "seen" filter the two totals are the same number (unread only) or zero (read
     * only) by construction, so the second set of COUNTs must not be run at all.
     *
     * @return void
     */
    public function testSeenFilterAnswersTheUnseenTotalWithoutQuerying()
    {
        $this->init();

        // read only: every unseen count is zero by construction
        $service = new WorkflowTaskService(['seen' => '1']);
        $connection = \DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $unseen = $service->countUnseen();
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame(0, $unseen);
        $this->assertCount(0, $queries, '"read only" cannot have unseen rows - no query may run');

        // unread only: the total the screen already asked for IS the unseen total
        $service = new WorkflowTaskService(['seen' => '0']);
        $total = $service->countAll();

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $unseen = $service->countUnseen();
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame($total, $unseen, 'under "unread only" both totals are the same rows');
        $this->assertCount(0, $queries, 'and the second set of COUNTs must not be run');
    }
}
