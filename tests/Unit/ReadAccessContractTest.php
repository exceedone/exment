<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 13: read paths moved onto the shared access contract that had no
 * production-caller coverage yet, and the shared uniqueness check as LoginService uses it.
 *
 * - CustomValue::getChildrenValues() / getSum(): the select_table branch with an indexed column
 *   (unconditional index-column name).
 * - SelectTrait::getSelectFilterQuery(): public, no caller in the package (unconditional index
 *   name, element match).
 * - LoginService::validateUniques() -> CustomTable::validatorUniques() on the user table
 *   (system table, never converted under R6, but the method is shared).
 */
class ReadAccessContractTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    /**
     * @return array{0: mixed, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function logged(\Closure $callback): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        return [$result, $log];
    }

    protected function matching(array $log, string $needle): array
    {
        return array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }));
    }

    // -------------------------------------------- getChildrenValues (select_table)

    public function testChildrenThroughAnIndexedSelectTableColumn(): void
    {
        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
        $column = CustomColumn::getEloquent('parent_select_table', $child);
        $this->assertTrue((bool)$column->index_enabled, 'precondition: indexed select_table column');
        $cdb = getDBTableName($child);
        $parentId = intval(array_get(json_decode(\DB::table($cdb)->whereNull('deleted_at')->orderBy('id')->value('value'), true), 'parent_select_table'));
        $record = $parent->getValueModel()->query()->find($parentId);

        [$query, $log] = $this->logged(function () use ($record, $column) {
            return $record->getChildrenValues($column, true);
        });
        $this->assertSame('select * from ' . static::q($cdb) . ' where ' . static::q('column_' . $column->suuid) . ' = ? and ' . static::q("$cdb.deleted_at") . ' is null', $query->toSql());
        $this->assertSame([$parentId], array_map('intval', $query->getBindings()));

        $expected = \DB::table($cdb)->whereNull('deleted_at')->get()->filter(function ($row) use ($parentId) {
            return intval(array_get(json_decode($row->value, true), 'parent_select_table')) === $parentId;
        })->pluck('id')->map(function ($v) {
            return intval($v);
        })->sort()->values()->all();
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $record->getChildrenValues($column)->pluck('id')->map(function ($v) {
            return intval($v);
        })->sort()->values()->all());
    }

    // ------------------------------------------------- SelectTrait (no caller)

    public function testSelectFilterQueryUsesTheIndexColumnName(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $column = CustomColumn::getEloquent('select_multiple', $table);
        $query = $table->getValueModel()->query();
        CustomItem::getItem($column)->getSelectFilterQuery($query, 'foo');
        $db = getDBTableName($table);
        // one element of the multi-valued text: FIND_IN_SET on MySQL / MariaDB, STRING_SPLIT + CROSS APPLY on SQL Server
        if (static::sqlServer()) {
            $exists = static::q($db . '_exists');
            $expected = 'select * from ' . static::q($db) . ' where exists (select 1 from ' . static::q($db) . ' as ' . $exists
                . " CROSS APPLY STRING_SPLIT(REPLACE(REPLACE(REPLACE(REPLACE(" . static::q('column_' . $column->suuid) . ", '[', ''), ' ', ''), ']', ''), '\"', ''), ',') AS CROSS_APPLY_TABLE where "
                . "{$exists}.id = " . static::q($db) . '.id and ' . static::q('CROSS_APPLY_TABLE.value') . ' in (?)) and ' . static::q("$db.deleted_at") . ' is null';
        } else {
            $expected = "select * from `$db` where FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE(`column_{$column->suuid}`, '[', ''), ' ', ''), ']', ''), '\\\"', '')) and `$db`.`deleted_at` is null";
        }
        $this->assertSame($expected, $query->toSql());
        $this->assertSame(['foo'], $query->getBindings());
        $expected = \DB::table($db)->whereNull('deleted_at')->get()->filter(function ($row) {
            return in_array('foo', (array)array_get(json_decode($row->value, true), 'select_multiple'), true);
        })->count();
        $this->assertSame($expected, $query->count());
    }

    // ----------------------------------------- LoginService::validateUniques()

    protected function loginUser(string $login_id, array $values, array $settings): CustomLoginUserBase
    {
        $setting = new LoginSetting();
        foreach ($settings as $key => $value) {
            $setting->setOption($key, $value);
        }
        $user = new class () extends CustomLoginUserBase {
        };
        $user->login_setting = $setting;
        $user->mapping_user_column = 'user_code';
        $user->login_id = $login_id;
        $user->mapping_values = $values;
        return $user;
    }

    /** @return array<string, string> column name => stored value, of a seeded user */
    protected function userValues(string $user_code): array
    {
        $db = getDBTableName(SystemTableName::USER);
        foreach (\DB::table($db)->get() as $row) {
            $value = json_decode($row->value, true);
            if (array_get($value, 'user_code') === $user_code) {
                return $value + ['id' => intval($row->id)];
            }
        }
        $this->fail("precondition: seeded user $user_code");
    }

    protected function uniqueMessage(string $column_name): string
    {
        $column = CustomColumn::getEloquent($column_name, SystemTableName::USER);
        return exmtrans('custom_value.help.multiple_uniques', $column->column_view_name);
    }

    protected function uniqueCounts(array $log): array
    {
        return $this->matching($log, 'select count(*) as aggregate from ' . static::q(getDBTableName(SystemTableName::USER)));
    }

    public function testLoginUniquesForANewUser(): void
    {
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $uniqueNames = $userTable->custom_columns->filter(function ($c) {
            return boolval($c->getOption('unique')) && !boolval($c->getOption('multiple_enabled'));
        })->pluck('column_name')->values()->all();
        $this->assertContains('user_code', $uniqueNames, 'precondition: user_code is unique');
        $this->assertContains('email', $uniqueNames, 'precondition: email is unique');
        $user1 = $this->userValues('user1');

        // just-in-time creation: duplicates are reported, flattened to messages
        $duplicate = $this->loginUser('p13-new', ['user_code' => 'user1', 'user_name' => 'p13', 'email' => 'p13-new@example.test'], ['sso_jit' => '1']);
        [$errors, $log] = $this->logged(function () use ($duplicate) {
            return LoginService::validateUniques($duplicate);
        });
        $this->assertSame([$this->uniqueMessage('user_code')], $errors);
        $counts = $this->uniqueCounts($log);
        $this->assertCount(count($uniqueNames), $counts, 'one count per unique column');
        $code = CustomColumn::getEloquent('user_code', $userTable);
        $codeKey = $code->index_enabled ? static::q('column_' . $code->suuid) : static::jsonText(static::q('value'), 'user_code');
        $codeQuery = $this->matching($counts, $codeKey);
        $this->assertCount(1, $codeQuery);
        $this->assertSame('select count(*) as aggregate from ' . static::q(getDBTableName($userTable)) . " where $codeKey = ? and " . static::q('deleted_at') . ' is null', $codeQuery[0]['query']);
        $this->assertSame(['user1'], $codeQuery[0]['bindings']);

        $fresh = $this->loginUser('p13-new', ['user_code' => 'p13-new', 'user_name' => 'p13', 'email' => 'p13-new@example.test'], ['sso_jit' => '1']);
        $this->assertSame([], LoginService::validateUniques($fresh));

        $both = $this->loginUser('p13-new', ['user_code' => 'user1', 'user_name' => 'p13', 'email' => $user1['email']], ['sso_jit' => '1']);
        $this->assertEqualsCanonicalizing([$this->uniqueMessage('user_code'), $this->uniqueMessage('email')], LoginService::validateUniques($both));

        // without just-in-time creation nothing is checked
        $noJit = $this->loginUser('p13-new', ['user_code' => 'user1', 'email' => $user1['email']], []);
        [$errors, $log] = $this->logged(function () use ($noJit) {
            return LoginService::validateUniques($noJit);
        });
        $this->assertSame([], $errors);
        $this->assertCount(0, $this->uniqueCounts($log));
    }

    public function testLoginUniquesForAnExistingUserExcludeItself(): void
    {
        $user1 = $this->userValues('user1');
        $user2 = $this->userValues('user2');

        $own = $this->loginUser('user1', ['user_code' => 'user1', 'user_name' => 'x', 'email' => $user1['email']], ['update_user_info' => '1']);
        [$errors, $log] = $this->logged(function () use ($own) {
            return LoginService::validateUniques($own);
        });
        $this->assertSame([], $errors, 'its own values are no duplicate');
        foreach ($this->uniqueCounts($log) as $q) {
            $this->assertStringEndsWith(' = ? and ' . static::q('id') . ' <> ? and ' . static::q('deleted_at') . ' is null', $q['query']);
            $this->assertSame($user1['id'], intval(end($q['bindings'])));
        }

        $taken = $this->loginUser('user1', ['user_code' => 'user1', 'user_name' => 'x', 'email' => $user2['email']], ['update_user_info' => '1']);
        $this->assertSame([$this->uniqueMessage('email')], LoginService::validateUniques($taken));

        // existing user without update_user_info: nothing is checked
        $this->assertSame([], LoginService::validateUniques($this->loginUser('user1', ['email' => $user2['email']], [])));
    }
}
