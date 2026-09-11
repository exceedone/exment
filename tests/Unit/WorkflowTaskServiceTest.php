<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\WorkflowTaskController;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\WorkflowTaskRead;
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
     * @return void
     */
    protected function init()
    {
        if (!\Schema::hasTable('workflow_task_reads')) {
            $this->markTestSkipped('table workflow_task_reads is missing - run "php artisan migrate" first');
        }

        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
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
     * A workflow whose active period has ended must NOT produce tasks.
     *
     * Exment decides "is this workflow active" in Workflow::getWorkflowByTable(), which
     * checks active_flg AND active_start_date AND active_end_date. WorkflowTaskService
     * must use the same rule, otherwise the badge keeps counting tasks of a workflow the
     * rest of the product already considers finished.
     *
     * @return void
     */
    public function testTasksRespectWorkflowActivePeriod()
    {
        $this->init();

        $service = new WorkflowTaskService();

        if ($service->getTasks()->isEmpty()) {
            $this->markTestSkipped('no un-actioned workflow task for this user in the test dataset');
        }

        // push every workflow out of its active period (rolled back after the test)
        \DB::table(SystemTableName::WORKFLOW_TABLE)->update([
            'active_start_date' => \Carbon\Carbon::today()->subDays(10)->toDateString(),
            'active_end_date'   => \Carbon\Carbon::today()->subDays(5)->toDateString(),
        ]);
        \Exceedone\Exment\Model\System::clearCache();

        $this->assertCount(
            0,
            (new WorkflowTaskService())->getTasks(),
            'tasks of an expired workflow must not be listed (see Workflow::getWorkflowByTable)'
        );
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
        foreach (['custom_table_id', 'seen', 'from', 'to', 'q'] as $key) {
            $this->assertNull($empty[$key], $key . ' must default to "no filter"');
        }

        $junk = WorkflowTaskService::normalizeFilter([
            'custom_table_id' => '1 OR 1=1',
            'seen'            => 'yes',
            'from'            => '2026-02-31',
            'to'              => "'; DROP TABLE users; --",
            'q'               => str_repeat('x', 500),
            'sort'            => 'updated_at desc',
        ]);

        $this->assertNull($junk['custom_table_id'], 'a table id that is not a number must be dropped');
        $this->assertNull($junk['seen'], 'an unknown seen value must be dropped');
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
     * The delete button is only drawn where Exment would actually allow the delete. The answer
     * has to be the same one CustomValueController asks for before deleting - a button that is
     * shown and then refused is worse than no button.
     *
     * @return void
     */
    public function testCanDeleteMatchesEnableDelete()
    {
        $this->init();

        $page = (new WorkflowTaskService())->getPage(1, 20);
        if ($page['rows']->isEmpty()) {
            $this->markTestSkipped('this user has no workflow task');
        }

        foreach ($page['rows'] as $row) {
            $this->assertArrayHasKey('can_delete', $row, 'the view needs the flag on every row');

            $custom_table = CustomTable::getEloquent($row['custom_table_id']);
            $custom_value = $custom_table->getValueModel($row['morph_id']);

            $this->assertSame(
                $custom_value->enableDelete(true) === true,
                $row['can_delete'],
                'the button disagrees with enableDelete() for ' . $row['task_key']
            );
        }
    }

    /**
     * A multi-row delete has to be grouped by table: CustomValueController@destroy takes a
     * comma separated id list, but an id only means something inside one table. table_url is
     * what the checkboxes are grouped by, so it must be exactly the base the single-row delete
     * url is built on - otherwise deleting three rows at once would hit a different endpoint
     * (or the wrong table) than deleting them one by one.
     *
     * @return void
     */
    public function testTableUrlIsTheBaseOfTheSingleDeleteUrl()
    {
        $this->init();

        $page = (new WorkflowTaskService())->getPage(1, 100);
        if ($page['rows']->isEmpty()) {
            $this->markTestSkipped('this user has no workflow task');
        }

        $perTable = [];

        foreach ($page['rows'] as $row) {
            $this->assertArrayHasKey('table_url', $row, 'the checkboxes are grouped by this');

            // base + '/' + id is the url the single delete button uses
            $this->assertSame(
                $row['url'],
                $row['table_url'] . '/' . $row['morph_id'],
                'the batch url and the single url disagree for ' . $row['task_key']
            );

            // and a batch of ids only makes sense if every row of one table shares one base
            $perTable[$row['custom_table_id']][] = $row['table_url'];
        }

        $bases = [];
        foreach ($perTable as $customTableId => $urls) {
            $unique = array_values(array_unique($urls));
            $this->assertCount(1, $unique, 'table ' . $customTableId . ' has more than one base url');
            $bases[$customTableId] = $unique[0];
        }

        $this->assertSame(
            count($bases),
            count(array_unique($bases)),
            'two different tables share a base url - a batch would delete from the wrong table'
        );
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
