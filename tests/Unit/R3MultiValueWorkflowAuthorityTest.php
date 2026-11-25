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
use Exceedone\Exment\Tests\Support\HybridP1Fixture;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 10 (R3 / R2-b): workflow authority "column" on multi-value user and
 * organization columns.
 *
 * Production caller WorkflowTaskService::getPage() -> RelationTable::setWorkflowWorkUsersSubQuery()
 * -> ColumnItem: unconditional index-column name, index_enabled gate.
 *
 * Needs the fixture table p10_workflow_multi (Tests\Support\HybridP1Fixture), provisioned outside
 * any transaction with tests/Support/provision-hybrid-p1-fixture.php. Without it the tests FAIL -
 * the coverage must not disappear silently. Run them on a database that has the fixture, and the
 * workflow-count tests (Feature\Api3WorkflowTest) on one that does not.
 * Workflow values, authorities and rows are written with DB::table() inside the transaction.
 */
class R3MultiValueWorkflowAuthorityTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected const FIXTURE = HybridP1Fixture::TABLE;

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

    /**
     * One element of a multi-valued text column: FIND_IN_SET on MySQL / MariaDB; on SQL Server STRING_SPLIT through
     * CROSS APPLY in an EXISTS over the same table (SqlServerGrammar::whereInArrayString).
     *
     * @param string $key the quoted, table-qualified column
     * @param string|null $db the database table (needed on SQL Server)
     */
    protected function findInSet(string $key, ?string $db = null): string
    {
        if (static::sqlServer()) {
            $exists = static::q($db . '_exists');
            return 'exists (select 1 from ' . static::q($db) . ' as ' . $exists . " CROSS APPLY STRING_SPLIT(REPLACE(REPLACE(REPLACE(REPLACE($key, '[', ''), ' ', ''), ']', ''), '\"', ''), ',') AS CROSS_APPLY_TABLE where "
                . $exists . '.id = ' . static::q($db) . '.id and ' . static::q('CROSS_APPLY_TABLE.value') . ' in (?))';
        }
        return "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($key, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
    }
    // ------------------------------------------------- workflow column authority

    protected function fixture(): CustomTable
    {
        $table = CustomTable::getEloquent(static::FIXTURE);
        if (!isset($table)) {
            $this->fail('fixture ' . static::FIXTURE . ' is missing: run tests/Support/provision-hybrid-p1-fixture.php --yes on the test database');
        }
        return $table;
    }

    protected function workflow(): Workflow
    {
        return Workflow::getWorkflowByTable($this->fixture());
    }

    protected function actionId(string $name): int
    {
        return intval($this->workflow()->workflow_actions->first(function ($a) use ($name) {
            return $a->action_name === $name;
        })->id);
    }

    protected function statusId(string $name): int
    {
        return intval($this->workflow()->workflow_statuses->first(function ($s) use ($name) {
            return $s->status_name === $name;
        })->id);
    }

    /** Authority "column" on p10_approve (from p10_waiting) for one fixture column. */
    protected function grantColumn(string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, $this->fixture());
        \DB::table(SystemTableName::WORKFLOW_AUTHORITY)->insert([
            'related_id' => $column->id,
            'related_type' => 'column',
            'workflow_action_id' => $this->actionId('p10_approve'),
        ]);
        System::clearCache();
        return $column;
    }

    /** A fixture row standing on p10_waiting. */
    protected function row(array $value): int
    {
        $now = \Carbon\Carbon::now();
        $id = \DB::table(getDBTableName($this->fixture()))->insertGetId([
            'suuid' => short_uuid(),
            'value' => json_encode($value + ['index_text' => 'p10-' . uniqid()]),
            'created_at' => $now, 'updated_at' => $now, 'created_user_id' => 1, 'updated_user_id' => 1,
        ]);
        \DB::table(SystemTableName::WORKFLOW_VALUE)->insert([
            'suuid' => substr(md5(uniqid('p10', true)), 0, 20),
            'workflow_id' => $this->workflow()->id,
            'morph_type' => static::FIXTURE,
            'morph_id' => $id,
            'workflow_action_id' => $this->actionId('p10_send'),
            'workflow_status_from_id' => null,
            'workflow_status_to_id' => $this->statusId('p10_waiting'),
            'action_executed_flg' => 0,
            'latest_flg' => 1,
            'created_at' => $now, 'updated_at' => $now, 'created_user_id' => 1, 'updated_user_id' => 1,
        ]);
        return intval($id);
    }

    /**
     * Task rows of the current user on the fixture table and the authority queries.
     *
     * @return array{0: array<int>, 1: string, 2: array<string>}
     */
    protected function tasks(): array
    {
        System::clearRequestSession();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = (new WorkflowTaskService(['custom_table_id' => $this->fixture()->id]))->getPage(1, 500);
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $log = array_values(array_filter($log, function ($q) {
            return strpos($q['query'], 'authority_related_type') !== false;
        }));
        $ids = collect($result['rows'])->pluck('morph_id')->map(function ($id) {
            return intval($id);
        })->unique()->sort()->values()->all();
        $bindings = [];
        foreach ($log as $q) {
            foreach ($q['bindings'] as $b) {
                $bindings[] = strval($b);
            }
        }
        return [$ids, implode("\n", array_column($log, 'query')), $bindings];
    }

    /** A non-admin login and the organizations ColumnItem compares with (belong_organizations). */
    protected function loginWithOrganization(): array
    {
        foreach (['2', '3', '5', '6', '7', '8', '9', '10', '4'] as $login) {
            $this->loginAs($login);
            $orgs = \Exment::user()->base_user->belong_organizations->pluck('id')->map(function ($id) {
                return strval($id);
            })->all();
            if (!empty($orgs)) {
                return [$login, $orgs];
            }
        }
        $this->fail('precondition: a non-admin login that belongs to an organization');
    }

    protected function otherOrganization(array $orgs): string
    {
        $other = \DB::table(getDBTableName(SystemTableName::ORGANIZATION))->whereNotIn('id', $orgs)->orderBy('id')->value('id');
        $this->assertNotNull($other, 'precondition: an organization the login is not in');
        return strval($other);
    }

    public function testMultiValueUserColumnAuthority(): void
    {
        $this->fixture();
        $column = $this->grantColumn('users_multi');
        $match = $this->row(['users_multi' => ['3', '2']]);
        $noTwelve = $this->row(['users_multi' => ['12', '22']]);
        $other = $this->row(['users_multi' => ['5']]);
        $empty = $this->row(['users_multi' => []]);
        $missing = $this->row([]);

        $this->loginAs('2');
        [$tasks, $sql, $bindings] = $this->tasks();
        $this->assertContains($match, $tasks, 'matching: the list names the login user');
        foreach ([$noTwelve, $other, $empty, $missing] as $id) {
            $this->assertNotContains($id, $tasks, "non-matching row $id");
        }
        $db = getDBTableName($this->fixture());
        $this->assertStringContainsString(
            static::q('authority_related_id') . ' = ? and ' . static::q('authority_related_type') . ' = ? and ' . $this->findInSet(static::q("$db.column_{$column->suuid}"), $db),
            $sql
        );
        $this->assertContains(strval($column->id), $bindings);
        $this->assertContains('2', $bindings);

        // another login named in no list: nothing
        $this->loginAs('10');
        [$tasks] = $this->tasks();
        foreach ([$match, $noTwelve, $other, $empty, $missing] as $id) {
            $this->assertNotContains($id, $tasks);
        }
    }

    /**
     * Existing rule, characterized: a multi-value user column without index_enabled grants
     * nothing, although Exment created a physical column_<suuid> for it.
     */
    public function testMultiValueUserColumnWithoutIndexEnabledGrantsNothing(): void
    {
        $table = $this->fixture();
        $column = $this->grantColumn('users_multi_noidx');
        $this->assertFalse((bool)$column->index_enabled);
        $this->assertTrue(hasColumn(getDBTableName($table), 'column_' . $column->suuid), 'precondition: the physical column exists');
        $row = $this->row(['users_multi_noidx' => ['2']]);

        $this->loginAs('2');
        [$tasks, $sql] = $this->tasks();
        $this->assertNotContains($row, $tasks);
        $this->assertStringNotContainsString("column_{$column->suuid}", $sql);
        $this->assertStringNotContainsString('users_multi_noidx', $sql);
    }

    public function testOrganizationColumnAuthority(): void
    {
        $this->fixture();
        $column = $this->grantColumn('org');
        [$login, $orgs] = $this->loginWithOrganization();
        $other = $this->otherOrganization($orgs);
        $this->loginAs('1');
        $match = $this->row(['org' => $orgs[0]]);
        $nonMatch = $this->row(['org' => $other]);
        $missing = $this->row([]);

        $this->loginAs($login);
        [$tasks, $sql, $bindings] = $this->tasks();
        $this->assertContains($match, $tasks, 'matching: the login belongs to the organization');
        $this->assertNotContains($nonMatch, $tasks);
        $this->assertNotContains($missing, $tasks);
        $db = getDBTableName($this->fixture());
        $in = implode(', ', array_fill(0, count($orgs), '?'));
        $this->assertStringContainsString(static::q('authority_related_id') . ' = ? and ' . static::q('authority_related_type') . ' = ? and ' . static::q("$db.column_{$column->suuid}") . " in ($in)", $sql);
        foreach ($orgs as $org) {
            $this->assertContains($org, $bindings);
        }
    }

    /**
     * Multi-value organization column (batch D2). Before the fix ColumnItem compared the list text of the
     * index column with whereIn() - exactly as for a single value - so the authority never matched, and SQL
     * Server failed converting '["1"]' to int. Characterized originally as: only / both / none => "no task",
     * and on SQL Server "Conversion failed" (04_Progress.md, D2). Now matched element by element
     * (whereInArrayString), the same as a multi-value user column.
     */
    public function testMultiValueOrganizationColumnAuthorityMatchesExactElements(): void
    {
        $this->fixture();
        $column = $this->grantColumn('orgs_multi');
        $this->grantColumn('users_multi');
        [$login, $orgs] = $this->loginWithOrganization();
        $other = $this->otherOrganization($orgs);
        $mine = $orgs[0];
        // ids that merely contain the login's organization id (1 vs 11, 21, 10): never a match
        $partials = [$mine . '1', '1' . $mine, $mine . '0'];
        foreach ($partials as $p) {
            $this->assertNotContains($p, $orgs, 'precondition: a partial id is not another organization of the login');
        }
        $this->loginAs('1');
        $only = $this->row(['orgs_multi' => [$mine]]);
        $both = $this->row(['orgs_multi' => [$other, $mine]]);
        $none = $this->row(['orgs_multi' => [$other]]);
        $partial = $this->row(['orgs_multi' => $partials]);
        $partialBeside = $this->row(['orgs_multi' => [$partials[0], $other]]);
        $empty = $this->row(['orgs_multi' => []]);
        $missing = $this->row([]);
        // unrelated record: matches the login's id in a column that is not the granted one
        $unrelated = $this->row(['orgs_multi' => [$other], 'users_multi' => ['99999']]);

        $this->loginAs($login);
        [$tasks, $sql, $bindings] = $this->tasks();
        $db = getDBTableName($this->fixture());
        $key = static::q("$db.column_{$column->suuid}");
        // one element match per organization of the login: MySQL / MariaDB OR the FIND_IN_SETs inside one group;
        // SQL Server uses one EXISTS over STRING_SPLIT with an IN list
        $one = $this->findInSet($key, $db);
        $element = static::sqlServer()
            ? str_replace('in (?))', 'in (' . implode(', ', array_fill(0, count($orgs), '?')) . '))', $one)
            : '(' . implode(' or ', array_fill(0, count($orgs), $one)) . ')';
        $this->assertStringContainsString(static::q('authority_related_id') . ' = ? and ' . static::q('authority_related_type') . ' = ? and ' . $element, $sql);
        $this->assertStringNotContainsString("$key in (", $sql, 'the list text is no longer compared with whereIn');
        foreach ($orgs as $org) {
            $this->assertContains($org, $bindings);
        }
        foreach ([$only, $both] as $id) {
            $this->assertContains($id, $tasks, "row $id names an organization of the login");
        }
        foreach ([$none, $partial, $partialBeside, $empty, $missing, $unrelated] as $id) {
            $this->assertNotContains($id, $tasks, "row $id must not be a task");
        }
    }

    /** A login belonging to no organization is granted nothing by a multi-value organization column. */
    public function testMultiValueOrganizationColumnDeniesALoginWithoutOrganization(): void
    {
        $this->fixture();
        $this->grantColumn('orgs_multi');
        $row = null;
        $login = null;
        foreach (['2', '3', '5', '6', '7', '8', '9', '10', '4'] as $candidate) {
            $this->loginAs($candidate);
            if (\Exment::user()->base_user->belong_organizations->isEmpty()) {
                $login = $candidate;
                break;
            }
        }
        if (is_null($login)) {
            $this->markTestSkipped('precondition: no test login without an organization in this data set');
        }
        $this->loginAs('1');
        $any = \DB::table(getDBTableName(SystemTableName::ORGANIZATION))->orderBy('id')->pluck('id')->map(fn ($id) => strval($id))->all();
        $row = $this->row(['orgs_multi' => $any]);
        $this->loginAs($login);
        [$tasks] = $this->tasks();
        $this->assertNotContains($row, $tasks);
    }
}
