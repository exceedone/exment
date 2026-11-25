<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 9 (R2): the read conditions of the workflow task list.
 *
 * Driven through WorkflowTaskService::getPage() (filter custom_table_id, q), which builds
 * RelationTable::setWorkflowWorkUsersSubQuery() with the authority classes:
 * - keyword: WorkflowTaskService::applyFilter() LIKE on the label column (getQueryKey(): index
 *   column or JSON path, prefixed with the table);
 * - ColumnItem (authority "column"): unconditional index-column name of an index_enabled
 *   user column of the workflow table; a column without index_enabled grants nothing;
 * - LoginUserColumnItem (authority "login_user_column"): explicit JSON path on the user table
 *   through the alias last_executed_user / created_user, also when the user column is indexed.
 *
 * Pins SQL fragments, bindings and the task rows of matching and non-matching users, with the
 * columns indexed and not (index_enabled turned off in the options only). Workflow values and
 * authorities are written with DB::table() inside the test transaction.
 */
class WorkflowReadConditionQueryTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    protected function loginAs(string $id): void
    {
        $this->be(LoginUser::find($id));
        System::clearRequestSession();
    }

    /** index_enabled turned off in the options only (no DDL, no model events). */
    protected function unindex(CustomTable $table, string $name): void
    {
        $column = CustomColumn::getEloquent($name, $table);
        $options = $column->options ?? [];
        unset($options['index_enabled']);
        \DB::table('custom_columns')->where('id', $column->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        System::clearRequestSession();
        $this->assertFalse((bool)CustomColumn::getEloquent($name, $table)->index_enabled, "precondition: $name not indexed");
    }

    protected function table(string $name): CustomTable
    {
        return CustomTable::getEloquent($name);
    }

    /**
     * Task rows of the current user for one table, and the queries that carried $needle.
     *
     * @return array{0: array<int>, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function tasks(CustomTable $table, ?string $q = null, string $needle = 'authority_related_type'): array
    {
        System::clearRequestSession();
        $filter = ['custom_table_id' => $table->id];
        if (isset($q)) {
            $filter['q'] = $q;
        }
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = (new WorkflowTaskService($filter))->getPage(1, 500);
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $ids = collect($result['rows'])->pluck('morph_id')->map(function ($id) {
            return intval($id);
        })->unique()->sort()->values()->all();
        $log = array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }));
        return [$ids, $log];
    }

    protected function joinedSql(array $log): string
    {
        return implode("\n", array_column($log, 'query'));
    }

    protected function allBindings(array $log): array
    {
        return array_merge(...array_map(function ($q) {
            return array_map('strval', $q['bindings']);
        }, $log ?: [['bindings' => []]]));
    }

    /** Record ids of a table that have no workflow value yet, in id order. */
    protected function freshIds(CustomTable $table, int $count): array
    {
        $used = \DB::table(SystemTableName::WORKFLOW_VALUE)->where('morph_type', $table->table_name)->pluck('morph_id')->map(function ($id) {
            return intval($id);
        })->all();
        $ids = [];
        foreach (\DB::table(getDBTableName($table))->whereNull('deleted_at')->orderBy('id')->pluck('id') as $id) {
            if (!in_array(intval($id), $used, true)) {
                $ids[] = intval($id);
            }
            if (count($ids) === $count) {
                break;
            }
        }
        $this->assertCount($count, $ids, 'precondition: records without workflow value');
        return $ids;
    }

    /** A first (= last) executed workflow value: the record stands at $status_to. */
    protected function seedValue(Workflow $workflow, CustomTable $table, int $id, int $action_id, int $status_to, int $executor): void
    {
        $now = \Carbon\Carbon::now();
        \DB::table(SystemTableName::WORKFLOW_VALUE)->insert([
            'suuid' => substr(md5(uniqid('p9', true)), 0, 20),
            'workflow_id' => $workflow->id,
            'morph_type' => $table->table_name,
            'morph_id' => $id,
            'workflow_action_id' => $action_id,
            'workflow_status_from_id' => null,
            'workflow_status_to_id' => $status_to,
            'action_executed_flg' => 0,
            'latest_flg' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_user_id' => $executor,
            'updated_user_id' => $executor,
        ]);
    }

    protected function actionId(Workflow $workflow, string $name): int
    {
        return intval($workflow->workflow_actions->first(function ($a) use ($name) {
            return $a->action_name === $name;
        })->id);
    }

    protected function statusId(Workflow $workflow, string $name): int
    {
        return intval($workflow->workflow_statuses->first(function ($s) use ($name) {
            return $s->status_name === $name;
        })->id);
    }

    protected function setValueKey(CustomTable $table, int $id, string $key, $value): void
    {
        $db = getDBTableName($table);
        $json = (array)json_decode(\DB::table($db)->where('id', $id)->value('value'), true);
        $json[$key] = $value;
        \DB::table($db)->where('id', $id)->update(['value' => json_encode($json)]);
    }

    /** @return array<int> */
    protected function visibleIds(CustomTable $table, array $ids): array
    {
        System::clearRequestSession();
        return $table->getValueQuery()->whereIn('id', $ids)->pluck('id')->map(function ($id) {
            return intval($id);
        })->sort()->values()->all();
    }

    // ------------------------------------------------- login_user_column (R2-c)

    /**
     * workflow1: from status "waiting", action send2 belongs to the boss (user.boss) of whoever
     * executed the record last. Executors 4, 6, 7, 10 have bosses 2, 3, 6 and none.
     *
     * @return array<int, int> record id => executor
     */
    protected function seedWorkflow1(): array
    {
        $table = $this->table('workflow1');
        $workflow = Workflow::getWorkflowByTable($table);
        $ids = $this->freshIds($table, 4);
        $executors = array_combine($ids, [4, 6, 7, 10]);
        foreach ($executors as $id => $executor) {
            $this->seedValue($workflow, $table, $id, $this->actionId($workflow, 'send'), $this->statusId($workflow, 'waiting'), $executor);
        }
        return $executors;
    }

    /** @return array<int, int> login id => the record ids whose last executor has that boss */
    protected function bossOracle(array $executors): array
    {
        $bosses = [];
        foreach (\DB::table(getDBTableName(SystemTableName::USER))->get() as $user) {
            $bosses[intval($user->id)] = array_get(json_decode($user->value, true), 'boss');
        }
        $expected = [];
        foreach ([2, 3, 6, 10] as $login) {
            $expected[$login] = [];
            foreach ($executors as $id => $executor) {
                if (strval($bosses[$executor] ?? '') === strval($login)) {
                    $expected[$login][] = $id;
                }
            }
        }
        return $expected;
    }

    public function testLoginUserColumnMatchesTheBossOfTheLastExecutor(): void
    {
        $table = $this->table('workflow1');
        $executors = $this->seedWorkflow1();
        $oracle = $this->bossOracle($executors);
        $this->assertSame([], $oracle[10], 'precondition: user 10 is nobody\'s boss');

        $matched = 0;
        foreach ($oracle as $login => $ids) {
            $this->loginAs(strval($login));
            [$tasks, $log] = $this->tasks($table);
            $expected = $this->visibleIds($table, $ids);
            $this->assertSame($expected, array_values(array_intersect($tasks, array_keys($executors))), "user $login");
            $matched += count($expected);

            $sql = $this->joinedSql($log);
            $this->assertStringContainsString(static::jsonText(static::q('last_executed_user.value'), 'boss') . ' = ?', $sql);
            $this->assertStringNotContainsString(substr(static::q('last_executed_user.column_'), 0, -1), $sql, 'explicit JSON even though user.boss is indexed');
            $this->assertContains(strval($login), $this->allBindings($log));
        }
        $this->assertGreaterThan(0, $matched, 'precondition: at least one matching task is visible');
    }

    public function testLoginUserColumnStaysJsonWhenTheUserColumnIsNotIndexed(): void
    {
        $table = $this->table('workflow1');
        $executors = $this->seedWorkflow1();
        $oracle = $this->bossOracle($executors);
        $this->loginAs('2');
        [$indexed] = $this->tasks($table);

        $this->unindex($this->table(SystemTableName::USER), 'boss');
        $this->loginAs('2');
        [$json, $log] = $this->tasks($table);
        $this->assertSame($indexed, $json);
        $this->assertSame($this->visibleIds($table, $oracle[2]), array_values(array_intersect($json, array_keys($executors))));
        $this->assertStringContainsString(static::jsonText(static::q('last_executed_user.value'), 'boss') . ' = ?', $this->joinedSql($log));
    }

    public function testLoginUserColumnWithCreatedUserBaseUsesThatAlias(): void
    {
        $table = $this->table('workflow1');
        $workflow = Workflow::getWorkflowByTable($table);
        $options = $workflow->options ?? [];
        $options['get_by_userinfo_base'] = 'created_user';
        \DB::table(SystemTableName::WORKFLOW)->where('id', $workflow->id)->update(['options' => json_encode($options)]);
        System::clearCache();

        $executors = $this->seedWorkflow1();
        // the record creators are now what counts: 4, 6, 7, 10 in reverse
        $creators = array_combine(array_keys($executors), [10, 7, 6, 4]);
        foreach ($creators as $id => $creator) {
            \DB::table(getDBTableName($table))->where('id', $id)->update(['created_user_id' => $creator]);
        }
        $oracle = $this->bossOracle($creators);

        foreach ([2, 10] as $login) {
            $this->loginAs(strval($login));
            [$tasks, $log] = $this->tasks($table);
            $this->assertSame($this->visibleIds($table, $oracle[$login]), array_values(array_intersect($tasks, array_keys($executors))), "user $login");
            $sql = $this->joinedSql($log);
            $this->assertStringContainsString(static::jsonText(static::q('created_user.value'), 'boss') . ' = ?', $sql);
            $this->assertStringNotContainsString(static::jsonExtract(static::q('last_executed_user.value'), 'boss'), $sql);
        }
    }

    // ---------------------------------------------------------- column (R2-b)

    /**
     * custom_value_edit: action2 (from status action1) gets an extra authority "column" on the
     * table's user column. Record A names the login user, record B someone else.
     *
     * @return array{0: string, 1: int, 2: int} login id, record A, record B
     */
    protected function seedColumnAuthority(): array
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $workflow = Workflow::getWorkflowByTable($table);
        $userColumn = CustomColumn::getEloquent('user', $table);
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            'related_id' => $userColumn->id,
            'related_type' => 'column',
            'workflow_action_id' => $this->actionId($workflow, 'action2'),
        ]);
        System::clearCache();

        // a login outside organization 2 (which action2 already belongs to) that sees two fresh records
        foreach (['3', '6', '7', '10', '2'] as $login) {
            $this->loginAs($login);
            if (\Exment::user()->base_user->belong_organizations->pluck('id')->map('intval')->contains(2)) {
                continue;
            }
            $candidates = $this->freshIds($table, 60);
            $visible = $this->visibleIds($table, $candidates);
            if (count($visible) >= 2) {
                [$a, $b] = $visible;
                $this->setValueKey($table, $a, 'user', $login);
                $this->setValueKey($table, $b, 'user', $login === '10' ? '9' : '10');
                foreach ([$a, $b] as $id) {
                    $this->seedValue($workflow, $table, $id, $this->actionId($workflow, 'action1'), $this->statusAfter($workflow, 'action1'), 1);
                }
                return [$login, $a, $b];
            }
        }
        $this->fail('precondition: a login outside organization 2 that sees two records');
    }

    protected function statusAfter(Workflow $workflow, string $action): int
    {
        $action_id = $this->actionId($workflow, $action);
        return intval(\DB::table(SystemTableName::WORKFLOW_VALUE)->where('workflow_action_id', $action_id)->whereNotNull('workflow_status_to_id')->value('workflow_status_to_id'));
    }

    public function testColumnAuthorityMatchesTheUserColumnThroughItsIndexColumn(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        [$login, $a, $b] = $this->seedColumnAuthority();
        $userColumn = CustomColumn::getEloquent('user', $table);
        $db = getDBTableName($table);

        $this->loginAs($login);
        [$tasks, $log] = $this->tasks($table);
        $this->assertContains($a, $tasks, 'matching: the record names the login user');
        $this->assertNotContains($b, $tasks, 'non-matching: the record names someone else');

        $sql = $this->joinedSql($log);
        $this->assertStringContainsString(static::q('authority_related_id') . ' = ? and ' . static::q('authority_related_type') . ' = ? and ' . static::q("$db.column_{$userColumn->suuid}") . ' = ?', $sql);
        $bindings = $this->allBindings($log);
        $this->assertContains(strval($userColumn->id), $bindings);
        $this->assertContains('column', $bindings);
    }

    /**
     * Existing rule, characterized: a user column without index_enabled grants nothing, even
     * when its value names the login user (ColumnItem only walks indexed columns).
     */
    public function testColumnAuthorityIgnoresAColumnWithoutIndex(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        [$login, $a, $b] = $this->seedColumnAuthority();
        $userColumn = CustomColumn::getEloquent('user', $table);
        $db = getDBTableName($table);
        $this->unindex($table, 'user');

        $this->loginAs($login);
        [$tasks, $log] = $this->tasks($table);
        $this->assertNotContains($a, $tasks);
        $this->assertNotContains($b, $tasks);
        $sql = $this->joinedSql($log);
        $this->assertStringNotContainsString(static::q("$db.column_{$userColumn->suuid}") . ' = ?', $sql);
        $this->assertStringNotContainsString(static::jsonExtract(static::q("$db.value"), 'user'), $sql);
    }

    // --------------------------------------------------------- keyword (R2-a)

    public function testKeywordSearchIndexedAndJsonForMatchingAndNonMatchingUsers(): void
    {
        $table = $this->table('workflow1');
        $db = getDBTableName($table);
        $executors = $this->seedWorkflow1();
        $oracle = $this->bossOracle($executors);

        // a login with a visible task, and its record's label
        $login = null;
        $record = null;
        foreach ([2, 3, 6] as $candidate) {
            $this->loginAs(strval($candidate));
            $visible = $this->visibleIds($table, $oracle[$candidate]);
            if (!empty($visible)) {
                $login = strval($candidate);
                $record = $visible[0];
                break;
            }
        }
        $this->assertNotNull($login, 'precondition: a login with a visible task');
        $label = strval(array_get(json_decode(\DB::table($db)->where('id', $record)->value('value'), true), 'index_text'));
        $this->assertNotSame('', $label);
        $labelColumn = CustomColumn::getEloquent('index_text', $table);

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex($table, 'index_text');
                $this->loginAs($login);
            }
            $mode = $json ? 'json' : 'indexed';
            [$hits, $log] = $this->tasks($table, $label, ' LIKE ');
            $this->assertContains($record, $hits, "$mode: the label finds its task");
            $key = $json
                ? static::jsonText(static::q("$db.value"), 'index_text')
                : static::q("$db.column_{$labelColumn->suuid}");
            $this->assertStringContainsString("$key LIKE ?", $this->joinedSql($log), $mode);
            // the product escapes the wildcard characters the way the engine reads them: a backslash on MySQL / MariaDB,
            // brackets on SQL Server (WorkflowTaskService::containsPattern)
            $escaped = static::sqlServer()
                ? str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $label)
                : str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $label);
            $this->assertContains('%' . $escaped . '%', $this->allBindings($log), $mode);

            [$none] = $this->tasks($table, 'p9-no-such-label');
            $this->assertSame([], $none, "$mode: a keyword matching nothing");

            // user 10 is nobody's boss: the same keyword finds no task of this table
            $this->loginAs('10');
            [$other] = $this->tasks($table, $label);
            $this->assertNotContains($record, $other, "$mode: non-matching user");
            $this->loginAs($login);
        }
    }
}
