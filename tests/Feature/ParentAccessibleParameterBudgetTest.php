<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Database\ParameterBudget;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\Unit\UnitTestBase;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * The rows of a table attached to a record (documents, comments) are filtered by the access of the login user to that record
 * (CustomValueModelScope::applyParentAccessible()). On SQL Server that filter used to carry the ids of the shares of the user once
 * per restricted table, so the statement of a restricted user went far over the 2,100 bound parameters of the driver
 * (4,000 to 12,000 here: "Tried to bind parameter number 2101").
 *
 * Now the scope counts the bindings of the statement it would build (ParameterBudget): within the budget the statement is the same
 * as before; above it the same filter is built with the set-based form of the lookup before the statement is executed.
 */
class ParentAccessibleParameterBudgetTest extends UnitTestBase
{
    use DatabaseTransactions;

    /** @var array<string, array<int, int>> attached table name => ids of the rows made here */
    protected $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->login('1');
    }

    protected function login(string $id): void
    {
        $this->be(LoginUser::find($id));
        System::clearRequestSession();
    }

    protected function limited(): bool
    {
        return ParameterBudget::isLimited();
    }

    /**
     * One row of the attached table for each case of the filter.
     *
     * @return array<string, array<int, array{id: int, type: ?string, parent: mixed}>>
     */
    protected function makeRows(): array
    {
        $parents = [
            TestDefine::TESTDATA_TABLE_NAME_EDIT,
            TestDefine::TESTDATA_TABLE_NAME_VIEW,
            'parent_table',
            'child_table',
            TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
            SystemTableName::USER,
        ];
        $cases = [];
        foreach ($parents as $name) {
            $table = CustomTable::getEloquent($name);
            if (!$table || !hasTable(getDBTableName($table))) {
                continue;
            }
            foreach (DB::table(getDBTableName($table))->orderBy('id')->limit(6)->pluck('id') as $id) {
                $cases[] = [$name, $id];
            }
            $cases[] = [$name, 987654321];          // the parent record does not exist
        }
        $cases[] = [TestDefine::TESTDATA_TABLE_NAME_EDIT, null];     // no parent id
        $cases[] = [null, 1];                                       // no parent type
        $cases[] = ['no_such_table', 1];                            // not a table

        $rows = [];
        foreach ([SystemTableName::DOCUMENT, SystemTableName::COMMENT] as $attached) {
            $db = getDBTableName($attached);
            foreach ($cases as [$type, $parent]) {
                $now = \Carbon\Carbon::now();
                $id = (int)DB::table($db)->insertGetId([
                    'suuid' => short_uuid(),
                    'parent_id' => $parent,
                    'parent_type' => $type,
                    'value' => json_encode(['tag' => 'pab']),
                    'created_at' => $now, 'updated_at' => $now, 'created_user_id' => 1, 'updated_user_id' => 1,
                ]);
                $rows[$attached][] = ['id' => $id, 'type' => $type, 'parent' => $parent];
                $this->made[$attached][] = $id;
            }
        }
        return $rows;
    }

    /**
     * Whether the login user sees a row hanging off ($type, $parent): the definition of the filter, evaluated with the scope of each
     * parent table alone - not with the statement under test.
     *
     * @param mixed $parent
     */
    protected function expectedVisible(?string $type, $parent): bool
    {
        if (is_null($type) || is_null($parent)) {
            return true;
        }
        $table = CustomTable::getEloquent($type);
        if (!$table || !hasTable(getDBTableName($table))) {
            return true;
        }
        if (!DB::table(getDBTableName($table))->where('id', $parent)->exists()) {
            return true;
        }
        return $table->getValueQuery()->withoutGlobalScope(SoftDeletingScope::class)
            ->where(getDBTableName($table) . '.id', $parent)->exists();
    }

    /** @return array<int, int> the ids a login user is expected to see among the rows made here, sorted */
    protected function expectedIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($this->expectedVisible($row['type'], $row['parent'])) {
                $ids[] = $row['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    /** @return array<int, int> the bound parameters of every statement run on the table inside the callback */
    protected function bindingCounts(string $attached, \Closure $callback, &$result = null): array
    {
        $db = getDBTableName($attached);
        $counts = [];
        $listener = function ($query) use ($db, &$counts) {
            if (stripos($query->sql, $db) !== false) {
                $counts[] = count($query->bindings);
            }
        };
        DB::listen($listener);
        $result = $callback();
        return $counts;
    }

    public function testRestrictedUsersSeeTheSameRowsAndTheStatementFitsTheBudget(): void
    {
        $rows = $this->makeRows();
        $hardLimit = ParameterBudget::SQLSRV_LIMIT;
        $checkedUsers = 0;

        foreach (['3', '5', '7', '9', '10'] as $login) {
            $this->login($login);
            if (\Exment::user()->isAdministrator()) {
                continue;
            }
            foreach ([SystemTableName::DOCUMENT, SystemTableName::COMMENT] as $attached) {
                $expected = $this->expectedIds($rows[$attached]);
                $model = getModelName($attached);

                $actual = null;
                $counts = $this->bindingCounts($attached, function () use ($model, $attached) {
                    return $model::whereIn('id', $this->made[$attached])->orderBy('id')->pluck('id')->map(fn ($id) => (int)$id)->all();
                }, $actual);
                $this->assertSame($expected, $actual, "login $login, $attached: the rows of the restricted user");
                $this->assertNotEmpty($counts);
                if ($this->limited()) {
                    $this->assertLessThanOrEqual($hardLimit, max($counts), "login $login, $attached: the statement fits the bound parameters of the driver");
                }

                // the rows he must not see are really there for the administrator, so the filter is doing something
                $this->assertLessThan(count($this->made[$attached]), count($expected), "login $login: a restricted user does not see every row");
            }
            $checkedUsers++;
        }
        $this->assertGreaterThan(0, $checkedUsers, 'precondition: a login that is not administrator');

        // the administrator: no filter, every row
        $this->login('1');
        foreach ([SystemTableName::DOCUMENT, SystemTableName::COMMENT] as $attached) {
            $model = getModelName($attached);
            $this->assertSame(
                collect($this->made[$attached])->sort()->values()->all(),
                $model::whereIn('id', $this->made[$attached])->orderBy('id')->pluck('id')->map(fn ($id) => (int)$id)->all()
            );
        }
    }

    public function testThePageAndTheCountOfARestrictedUserAreThoseOfTheFilter(): void
    {
        $rows = $this->makeRows();
        $this->login('5');
        $model = getModelName(SystemTableName::DOCUMENT);
        $expected = $this->expectedIds($rows[SystemTableName::DOCUMENT]);
        $this->assertGreaterThan(6, count($expected), 'precondition: more than two pages');

        $page = $model::whereIn('id', $this->made[SystemTableName::DOCUMENT])->orderBy('id')->paginate(3, ['*'], 'page', 2);
        $this->assertSame(count($expected), $page->total(), 'the count query');
        $this->assertSame(array_slice($expected, 3, 3), $page->getCollection()->pluck('id')->map(fn ($id) => (int)$id)->all(), 'page 2 in the order of the ids');

        // the aggregates and the first / find of the same filter
        $this->assertSame(count($expected), $model::whereIn('id', $this->made[SystemTableName::DOCUMENT])->count());
        $this->assertSame($expected[0], (int)$model::whereIn('id', $this->made[SystemTableName::DOCUMENT])->orderBy('id')->first()->id);
        $hidden = array_values(array_diff($this->made[SystemTableName::DOCUMENT], $expected));
        $this->assertNotEmpty($hidden);
        $this->assertNull($model::find($hidden[0]), 'a row of a record he cannot access is not found by its id');
        $this->assertNotNull($model::find($expected[0]));
    }

    public function testTheSetBasedFormIsUsedOnlyAboveTheBudget(): void
    {
        if (!$this->limited()) {
            $this->markTestSkipped('only SQL Server has a budget: the other drivers keep the statement they always had');
        }
        $marker = 'select [id] from [custom_value_authoritables]';
        $statement = function (string $login) {
            $this->login($login);
            return getModelName(SystemTableName::DOCUMENT)::query()->toBase();
        };

        // a login whose statement was far over the budget: the set-based form, far under it now
        $big = $statement('3');
        $this->assertStringContainsString($marker, $big->toSql());
        $this->assertLessThan(ParameterBudget::limit(), count($big->getBindings()));
        $this->assertGreaterThan(50, count($big->getBindings()), 'it is still the filter of every restricted table');

        // a login that is within the budget keeps the statement it always had
        $small = $statement('10');
        $this->assertStringNotContainsString($marker, $small->toSql());
        $this->assertLessThanOrEqual(ParameterBudget::limit(), count($small->getBindings()));

        // the administrator: no filter
        $admin = $statement('1');
        $this->assertSame(0, substr_count($admin->toSql(), 'not exists'));
    }

    public function testBelowAtAndAboveTheBudgetWithTheBindingsOfOtherFilters(): void
    {
        if (!$this->limited()) {
            $this->markTestSkipped('only SQL Server has a budget');
        }
        $rows = $this->makeRows();
        $model = getModelName(SystemTableName::DOCUMENT);
        $this->login('3');
        $expected = $this->expectedIds($rows[SystemTableName::DOCUMENT]);
        $limit = ParameterBudget::limit();
        // what the filter carries by itself once it is set-based
        $own = count($model::query()->toBase()->getBindings());
        $this->assertLessThan($limit, $own);

        $run = function (int $extra) use ($model) {
            // the other filters of the caller: $extra bound parameters that match every row
            $values = array_fill(0, $extra, 'pab');
            $query = $model::whereIn('id', $this->made[SystemTableName::DOCUMENT])->orderBy('id');
            if ($extra > 0) {
                $query->where(function ($q) use ($values) {
                    $q->whereIn('suuid', $values)->orWhereNotNull('suuid');
                });
            }
            return $query;
        };
        $callerOwn = count($run(0)->getQuery()->getBindings());

        // below and at the budget: the statement runs and gives the rows of the filter
        $atBudget = $limit - $own - $callerOwn;
        foreach ([$atBudget - 10, $atBudget] as $extra) {
            $query = $run($extra);
            $total = $own + count($query->getQuery()->getBindings());
            $this->assertSame($extra === $atBudget ? $limit : $limit - 10, $total, "the statement carries $total bound parameters (budget $limit)");
            $this->assertSame($expected, $query->pluck('id')->map(fn ($id) => (int)$id)->all(), "with $extra more bound parameters");
        }

        // above the budget, even as subqueries: refused before any statement is sent, and said so; nothing is cut
        $above = $run($limit - $own - $callerOwn + 1);
        try {
            $above->pluck('id');
            $this->fail('a statement above the budget must be refused before it is executed');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('bound parameters', $e->getMessage());
            $this->assertStringNotContainsString('pab', $e->getMessage(), 'no value in the message');
        }
    }
}
