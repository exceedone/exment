<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiTableController;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Hybrid storage P1 slice 3: "find one record by an equality on one column".
 *
 * Pins, through the production callers CustomTable::findValue(), SelectTable::getImportValue()
 * (target_column_name without datalist) and ApiTableController::quickAdd(), the SQL, bindings
 * and selected columns of the lookup query, and when the index-column DDL (alterColumn) runs.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class CustomColumnLookupQueryTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function countingColumn(string $name, bool $indexed): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->custom_table_id = CustomTable::getEloquent(SystemTableName::USER)->id;
        $column->column_name = $name;
        $column->column_type = ColumnType::TEXT;
        $column->suuid = 'p3test' . substr(md5($name . ($indexed ? 'i' : 'n')), 0, 14);
        $column->options = array_filter(['index_enabled' => $indexed ? '1' : null]);
        return $column;
    }

    /**
     * Run $callback and return [sql, bindings] of every executed query on $dbTable.
     *
     * @return array<int, array{0: string, 1: array<mixed>}>
     */
    protected function queriesOn(string $dbTable, callable $callback): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $result = [];
        foreach ($log as $q) {
            if (strpos($q['query'], "from `{$dbTable}`") !== false) {
                $result[] = [$q['query'], $q['bindings']];
            }
        }
        return $result;
    }

    protected function jsonEq(string $name): string
    {
        return "json_unquote(json_extract(`value`, '$.\"{$name}\"')) = ?";
    }

    // ---------------------------------------------------------------- CustomTable::findValue

    public function testFindValueOfJsonColumn(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $db = getDBTableName($table);
        $column = $this->countingColumn('p3_plain', false);

        $queries = $this->queriesOn($db, function () use ($table, $column) {
            $this->assertNull($table->findValue($column, 'no such value'));
        });

        $this->assertSame([[
            "select * from `{$db}` where {$this->jsonEq('p3_plain')} and `{$db}`.`deleted_at` is null limit 1",
            ['no such value'],
        ]], $queries);
        $this->assertSame(0, $column->alterCount);
    }

    public function testFindValueOfExistingIndexColumn(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $db = getDBTableName($table);
        $userCode = CustomColumn::getEloquent('user_code', $table);
        $expected = $table->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->first();
        $code = $expected->getValue('user_code');

        $found = null;
        $queries = $this->queriesOn($db, function () use ($table, $code, &$found) {
            $found = $table->findValue('user_code', $code);
        });

        $this->assertSame($expected->id, $found->id);
        $this->assertSame([[
            "select * from `{$db}` where `column_{$userCode->suuid}` = ? and `{$db}`.`deleted_at` is null limit 1",
            [$code],
        ]], $queries);
    }

    public function testFindValueOfIndexedColumnKeepsAutoDdl(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $db = getDBTableName($table);
        $column = $this->countingColumn('p3_missing', true);
        $this->assertFalse(hasColumn($db, 'column_' . $column->suuid), 'precondition: index column missing');

        try {
            $table->findValue($column, 'x');
            $this->fail('the index column does not exist (alterColumn is a counter), so the query must fail');
        } catch (QueryException $ex) {
            $this->assertSame("select * from `{$db}` where `column_{$column->suuid}` = ? and `{$db}`.`deleted_at` is null limit 1", $ex->getSql());
            $this->assertSame(['x'], $ex->getBindings());
        }
        $this->assertSame(1, $column->alterCount, 'findValue() keeps the auto-DDL of getQueryKey()');
    }

    // ---------------------------------------------------------------- SelectTable::getImportValue

    /**
     * @return array{0: array<mixed>, 1: array<int, array{0: string, 1: array<mixed>}>}
     */
    protected function importLookup(string $targetColumnName, $value): array
    {
        $target = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $column = new CustomColumn([
            'column_name' => 'p3_select_table',
            'column_view_name' => 'p3_select_table',
            'column_type' => ColumnType::SELECT_TABLE,
            'options' => ['select_target_table' => $target->id],
        ]);
        $column->custom_table_id = CustomTable::getEloquent('information')->id;
        $item = $column->column_item;

        $result = null;
        $queries = $this->queriesOn(getDBTableName($target), function () use ($item, $value, $targetColumnName, &$result) {
            $result = $item->getImportValue($value, ['target_column_name' => $targetColumnName]);
        });
        return [$result, $queries];
    }

    public function testImportLookupOfJsonColumn(): void
    {
        $target = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($target);
        $row = $target->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->first();
        $text = $row->getValue('text');

        [$result, $queries] = $this->importLookup('text', $text);

        $this->assertTrue($result['result']);
        $first = $target->getValueModel()->query()->withoutGlobalScopes()->where('value->text', $text)->orderBy('id')->first();
        $this->assertSame($first->id, $result['value']);
        $this->assertSame([[
            "select `id` from `{$db}` where {$this->jsonEq('text')} and `{$db}`.`deleted_at` is null limit 1",
            [$text],
        ]], $queries);
    }

    public function testImportLookupOfIndexColumn(): void
    {
        $target = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($target);
        $index = CustomColumn::getEloquent('index_text', $target);
        $row = $target->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->first();
        $label = $row->getValue('index_text');

        [$result, $queries] = $this->importLookup('index_text', $label);

        $this->assertTrue($result['result']);
        $this->assertSame([[
            "select `id` from `{$db}` where `column_{$index->suuid}` = ? and `{$db}`.`deleted_at` is null limit 1",
            [$label],
        ]], $queries);

        [$missing, $queries] = $this->importLookup('index_text', 'p3 no such label');
        $this->assertFalse($missing['result']);
        $this->assertCount(1, $queries);
    }

    // ---------------------------------------------------------------- ApiTableController::quickAdd

    public function testQuickAddLooksUpTheExistingLabel(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($table);
        $index = CustomColumn::getEloquent('index_text', $table);
        $row = $table->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->first();
        $label = $row->getValue('index_text');

        System::clearRequestSession();
        $request = Request::create('/admin/webapi/' . $table->table_name . '/quickadd', 'POST', ['label' => $label]);
        $this->app->instance('request', $request);

        $response = null;
        $queries = $this->queriesOn($db, function () use ($table, $request, &$response) {
            $response = (new ApiTableController($table, $request))->quickAdd($request);
        });

        $body = json_decode((string)$response->getContent(), true);
        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertFalse($body['created']);
        $lookups = array_values(array_filter($queries, function ($q) use ($index) {
            return strpos($q[0], "`column_{$index->suuid}` = ?") !== false;
        }));
        $this->assertCount(1, $lookups, json_encode($queries));
        $this->assertSame([$label], $lookups[0][1]);
        $this->assertStringStartsWith("select * from `{$db}` where `column_{$index->suuid}` = ?", $lookups[0][0]);
        $this->assertSame($row->id, $body['id']);
    }

    /**
     * The fixture's label column (index_text) with index_enabled turned off in its options only
     * (no DDL, no model events): the lookup reads the JSON value.
     */
    public function testQuickAddLooksUpTheExistingLabelInJson(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($table);
        $index = CustomColumn::getEloquent('index_text', $table);
        $options = $index->options ?? [];
        unset($options['index_enabled']);
        \DB::table('custom_columns')->where('id', $index->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        System::clearRequestSession();
        $this->assertFalse((bool)CustomColumn::getEloquent('index_text', $table)->index_enabled, 'precondition: label column not indexed');

        $row = $table->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->first();
        $label = $row->getValue('index_text');

        $request = Request::create('/admin/webapi/' . $table->table_name . '/quickadd', 'POST', ['label' => $label]);
        $this->app->instance('request', $request);

        $response = null;
        $queries = $this->queriesOn($db, function () use ($table, $request, &$response) {
            $response = (new ApiTableController($table, $request))->quickAdd($request);
        });

        $body = json_decode((string)$response->getContent(), true);
        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertFalse($body['created']);
        $lookups = array_values(array_filter($queries, function ($q) {
            return strpos($q[0], $this->jsonEq('index_text')) !== false;
        }));
        $this->assertCount(1, $lookups, json_encode($queries));
        $this->assertSame([$label], $lookups[0][1]);
        $this->assertStringStartsWith("select * from `{$db}` where {$this->jsonEq('index_text')}", $lookups[0][0]);
        $this->assertStringNotContainsString("column_{$index->suuid}", $lookups[0][0]);
        $this->assertSame($row->id, $body['id']);
    }

    // ---------------------------------------------------------------- resolver contract

    public function testLookupPurposeResolvesToTheQueryKey(): void
    {
        $plain = $this->countingColumn('p3_purpose', false);
        $this->assertSame('value->p3_purpose', $plain->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_LOOKUP));
        $this->assertSame(0, $plain->alterCount);

        $indexed = $this->countingColumn('p3_purpose_idx', true);
        $this->assertSame('column_' . $indexed->suuid, $indexed->getQueryKeyFor(CustomColumn::QUERY_PURPOSE_LOOKUP));
        $this->assertSame(1, $indexed->alterCount);
    }
}
