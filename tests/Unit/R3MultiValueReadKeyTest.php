<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 10 (R3): read keys of multi-value columns.
 *
 * View filters (production caller CustomView::filterModel()) on all_columns_table_fortest:
 * USER_EQ_USER / USER_NE_USER on user_multiple and SELECT_EXISTS / SELECT_NOT_EXISTS on
 * select_multiple - index-preferring key, matched element by element (whereInArrayString).
 * Runs on the seeded data alone. The workflow authority cases on multi-value columns, which need
 * an extra fixture table, are in R3MultiValueWorkflowAuthorityTest.
 */
class R3MultiValueReadKeyTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomViewTrait;

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

    protected function findInSet(string $key, bool $not = false): string
    {
        return $not
            ? "NOT FIND_IN_SET(?, IFNULL(REPLACE(REPLACE(REPLACE(REPLACE($key, '[', ''), ' ', ''), ']', ''), '\\\"', ''), ''))"
            : "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($key, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
    }

    // ----------------------------------------------------------- view filters

    protected function allColumns(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    /**
     * @return array{0: array<int>, 1: string, 2: array<mixed>}
     */
    protected function filtered(string $column, int $condition, $value = null): array
    {
        System::clearRequestSession();
        $setting = ['column_name' => $column, 'filter_condition' => $condition];
        if (isset($value)) {
            $setting['filter_value_text'] = $value;
        }
        [$table, $view] = $this->createCustomViewAll([
            'login_user_id' => strval(\Exment::getUserId()),
            'filter_settings' => [$setting],
        ]);
        $query = $table->getValueQuery();
        $view->filterModel($query);
        $ids = (clone $query)->pluck('id')->map(function ($id) {
            return intval($id);
        })->sort()->values()->all();
        return [$ids, $query->toSql(), $query->getBindings()];
    }

    /** @return array<int> ids whose list $column contains (or not) $value, as the current user sees them */
    protected function listOracle(string $column, string $value, bool $contains): array
    {
        return $this->allColumns()->getValueQuery()->get()->filter(function ($row) use ($column, $value, $contains) {
            $list = array_map('strval', (array)array_get($row->value, $column));
            return in_array($value, $list, true) === $contains;
        })->pluck('id')->map(function ($id) {
            return intval($id);
        })->sort()->values()->all();
    }

    protected function indexKey(CustomTable $table, string $name): string
    {
        return '`' . getDBTableName($table) . '`.`column_' . CustomColumn::getEloquent($name, $table)->suuid . '`';
    }

    protected function jsonKey(CustomTable $table, string $name): string
    {
        return 'json_unquote(json_extract(`' . getDBTableName($table) . "`.`value`, '$.\"" . $name . "\"'))";
    }

    public function testUserEqUserOnMultiValueColumnIndexedAndJson(): void
    {
        $user_id = strval(\Exment::getUserId());
        $eq = $this->listOracle('user_multiple', $user_id, true);
        $ne = $this->listOracle('user_multiple', $user_id, false);
        $this->assertNotEmpty($eq, 'precondition: some rows name the admin');
        $this->assertNotEmpty($ne, 'precondition: some rows do not');

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex($this->allColumns(), 'user_multiple');
            }
            $key = $json ? $this->jsonKey($this->allColumns(), 'user_multiple') : $this->indexKey($this->allColumns(), 'user_multiple');
            $mode = $json ? 'json' : 'indexed';

            [$ids, $sql, $bindings] = $this->filtered('user_multiple', FilterOption::USER_EQ_USER);
            $this->assertStringContainsString($this->findInSet($key), $sql, $mode);
            $this->assertContains($user_id, array_map('strval', $bindings));
            $this->assertSame($eq, $ids, "$mode: matching rows");

            [$ids, $sql] = $this->filtered('user_multiple', FilterOption::USER_NE_USER);
            $this->assertStringContainsString($this->findInSet($key, true), $sql, $mode);
            $this->assertSame($ne, $ids, "$mode: non-matching rows");
        }
    }

    public function testSelectExistsOnMultiValueColumnIndexedAndJson(): void
    {
        $value = 'bar';
        $exists = $this->listOracle('select_multiple', $value, true);
        $notExists = $this->listOracle('select_multiple', $value, false);
        $this->assertNotEmpty($exists);
        $this->assertNotEmpty($notExists);

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex($this->allColumns(), 'select_multiple');
            }
            $key = $json ? $this->jsonKey($this->allColumns(), 'select_multiple') : $this->indexKey($this->allColumns(), 'select_multiple');
            $mode = $json ? 'json' : 'indexed';

            [$ids, $sql, $bindings] = $this->filtered('select_multiple', FilterOption::SELECT_EXISTS, $value);
            $this->assertStringContainsString($this->findInSet($key), $sql, $mode);
            $this->assertContains($value, $bindings, $mode);
            $this->assertSame($exists, $ids, "$mode: exists");

            [$ids, $sql] = $this->filtered('select_multiple', FilterOption::SELECT_NOT_EXISTS, $value);
            $this->assertStringContainsString($this->findInSet($key, true), $sql, $mode);
            $this->assertSame($notExists, $ids, "$mode: not exists");
        }
    }

}
