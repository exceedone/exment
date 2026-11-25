<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 slice 7: the "only mine" lookup KanbanGrid::lookupMyKeys().
 *
 * The board's "mine" column is a select_table column of child_table_select pointing at
 * parent_table_select, whose single user column ties a row to a login. The lookup matches the
 * logged in user in that user column with whereInArrayString() (FIND_IN_SET over the value with
 * '[', ' ', ']' and '"' removed), so a single value and a JSON list of several match alike.
 *
 * Driven through KanbanGrid::buildBoard() ('me') and getMyKeys(). Pins SQL, bindings, keys
 * (admin and a restricted user), empty / missing / multi values, indexed and non-indexed user
 * column, the index-column DDL, and the existing delimiter behaviour (characterized, not fixed).
 * Values are written with DB::table() inside the test transaction (no model events).
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class KanbanMineKeysTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    protected function target(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
    }

    protected function source(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
    }

    protected function mine(): CustomColumn
    {
        return CustomColumn::getEloquent('parent_select_table', $this->source());
    }

    protected function userColumn(): CustomColumn
    {
        return CustomColumn::getEloquent('user', $this->target());
    }

    /** whereInArrayString() of MySqlGrammar for the given wrapped key, as written there. */
    protected function findInSet(string $key): string
    {
        return "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($key, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
    }

    protected function expectedSql(string $key): string
    {
        $db = getDBTableName($this->target());
        return "select `$db`.`id` from `$db` where {$this->findInSet($key)} and `$db`.`deleted_at` is null";
    }

    protected function indexKey(): string
    {
        $db = getDBTableName($this->target());
        return "`$db`.`column_{$this->userColumn()->suuid}`";
    }

    protected function jsonKey(): string
    {
        $db = getDBTableName($this->target());
        return "json_unquote(json_extract(`$db`.`value`, '$.\"user\"'))";
    }

    /** index_enabled turned off in the options only (no DDL, no model events). */
    protected function unindex(): void
    {
        $column = $this->userColumn();
        $options = $column->options ?? [];
        unset($options['index_enabled']);
        \DB::table('custom_columns')->where('id', $column->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        System::clearRequestSession();
        $this->assertFalse((bool)$this->userColumn()->index_enabled, 'precondition: user column not indexed');
    }

    /** Writes the raw user value of a target row (null removes the key). */
    protected function setUserValue(int $id, $value): void
    {
        $db = getDBTableName($this->target());
        $json = (array)json_decode(\DB::table($db)->where('id', $id)->value('value'), true);
        if (is_null($value)) {
            unset($json['user']);
        } else {
            $json['user'] = $value;
        }
        \DB::table($db)->where('id', $id)->update(['value' => json_encode($json)]);
    }

    /** @return array<int> target row ids, in id order */
    protected function targetIds(): array
    {
        return $this->target()->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();
    }

    protected function grid(): KanbanGrid
    {
        $view = CustomView::create([
            'custom_table_id' => $this->source()->id,
            'view_view_name' => 'kanban-p7-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::KANBAN,
            'options' => [
                'kanban_group_column_id' => $this->mine()->id,
                'kanban_mine_column_id' => $this->mine()->id,
                'kanban_col_count' => 20,
            ],
        ]);
        System::clearRequestSession();
        System::clearCache();
        $view = CustomView::withoutGlobalScopes()->find($view->id);
        return new KanbanGrid($view->custom_table, $view);
    }

    protected function invoke($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    /**
     * getMyKeys() of a fresh board with the query log on.
     *
     * @return array{0: array<int, string>, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function myKeys(): array
    {
        $grid = $this->grid();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $keys = $this->invoke($grid, 'getMyKeys', [$this->mine()]);
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $log = array_values(array_filter($log, function ($q) {
            return strpos($q['query'], 'FIND_IN_SET') !== false;
        }));
        return [$keys, $log];
    }

    /**
     * Mixed values for the admin (user id 1). Row 0 keeps its single "1".
     *
     * @return array<int, string> ids that must match, in id order
     */
    protected function seedValues(): array
    {
        $ids = $this->targetIds();
        $this->assertGreaterThanOrEqual(10, count($ids), 'precondition: 10 target rows');
        $this->assertSame('1', strval(array_get($this->target()->getValueModel()->query()->withoutGlobalScopes()->find($ids[0])->value, 'user')));
        $this->setUserValue($ids[1], ['3', '1']);   // list of strings: match
        $this->setUserValue($ids[2], [2, 1]);       // list of numbers: match
        $this->setUserValue($ids[3], ['12']);       // "12" is not "1"
        $this->setUserValue($ids[4], ['11', '21']); // no element is "1"
        $this->setUserValue($ids[5], null);         // key missing
        $this->setUserValue($ids[6], '');           // blank
        $this->setUserValue($ids[7], []);           // empty list
        $this->setUserValue($ids[8], ' 1');         // non-canonical: the space is removed before matching
        return array_map('strval', [$ids[0], $ids[1], $ids[2], $ids[8]]);
    }

    // ---------------------------------------------------------------------

    public function testBoardMeHoldsTheRowsOfTheUserIndexed(): void
    {
        $grid = $this->grid();
        $board = $this->invoke($grid, 'buildBoard', [$this->mine(), null, collect(), []]);

        $expected = [];
        foreach ($this->target()->getValueModel()->query()->get() as $row) {
            if (strval(array_get($row->value, 'user')) === '1') {
                $expected[] = strval($row->id);
            }
        }
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $board['me']);
    }

    public function testSingleValueIndexedAndJson(): void
    {
        [$keys, $log] = $this->myKeys();
        $this->assertCount(1, $log);
        $this->assertSame($this->expectedSql($this->indexKey()), $log[0]['query']);
        $this->assertSame(['1'], $log[0]['bindings']);
        $this->assertSame([strval($this->targetIds()[0])], $keys);

        $this->unindex();
        [$json, $log] = $this->myKeys();
        $this->assertSame($this->expectedSql($this->jsonKey()), $log[0]['query']);
        $this->assertSame(['1'], $log[0]['bindings']);
        $this->assertSame($keys, $json);
    }

    public function testMultiEmptyAndMissingValuesIndexed(): void
    {
        $expected = $this->seedValues();
        [$keys, $log] = $this->myKeys();
        $this->assertSame($this->expectedSql($this->indexKey()), $log[0]['query']);
        $this->assertSame($expected, $keys);
    }

    public function testMultiEmptyAndMissingValuesJson(): void
    {
        $expected = $this->seedValues();
        $this->unindex();
        [$keys, $log] = $this->myKeys();
        $this->assertSame($this->expectedSql($this->jsonKey()), $log[0]['query']);
        $this->assertSame($expected, $keys);
    }

    /**
     * Existing delimiter behaviour, characterized (not fixed): the value is flattened to a
     * comma list before FIND_IN_SET, so an element holding a comma is read as several ids.
     */
    public function testElementWithACommaIsReadAsSeveralIdsIndexedAndJson(): void
    {
        $ids = $this->targetIds();
        $this->setUserValue($ids[0], '2');
        $this->setUserValue($ids[3], ['5,1']);
        $this->setUserValue($ids[4], '7,1');

        [$keys] = $this->myKeys();
        $this->assertSame(array_map('strval', [$ids[3], $ids[4]]), $keys, 'indexed: "5,1" and "7,1" match user 1');

        $this->unindex();
        [$json] = $this->myKeys();
        $this->assertSame($keys, $json, 'json: same as indexed');
    }

    public function testRestrictedUserOnlyGetsVisibleRows(): void
    {
        $ids = $this->targetIds();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $user_id = strval(\Exment::getUserId());
        $visible = $this->target()->getValueQuery()->pluck('id')->map(function ($id) {
            return intval($id);
        })->all();
        $this->assertLessThan(count($ids), count($visible), 'precondition: user2 sees only part of the lookup table');

        // the user in a list on every row, visible or not
        foreach ($ids as $id) {
            $this->setUserValue($id, ['99', $user_id]);
        }
        $expected = array_map('strval', array_values(array_intersect($ids, $visible)));
        $this->assertNotEmpty($expected);

        $db = getDBTableName($this->target());
        $scope = " and (exists (select * from `custom_value_authoritables` where `$db`.`id` = `custom_value_authoritables`.`parent_id`"
            . " and (`authoritable_user_org_type`, `authoritable_target_id`) in ((?, ?)) and `parent_type` = ?))";
        $bindings = [$user_id, 'user', $user_id, $this->target()->table_name];

        [$keys, $log] = $this->myKeys();
        $this->assertSame($this->expectedSql($this->indexKey()) . $scope, $log[0]['query']);
        $this->assertSame($bindings, $log[0]['bindings']);
        $this->assertSame($expected, $keys, 'indexed: the scopes still apply');

        $this->unindex();
        [$json, $log] = $this->myKeys();
        $this->assertSame($this->expectedSql($this->jsonKey()) . $scope, $log[0]['query']);
        $this->assertSame($bindings, $log[0]['bindings']);
        $this->assertSame($expected, $json, 'json: the scopes still apply');
    }

    /**
     * An indexed user column whose index column does not exist: alterColumn (here a counter)
     * runs once, before the lookup, and the lookup still names the index column.
     */
    public function testMissingIndexColumnRunsTheDdlOnce(): void
    {
        $user = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $user->custom_table_id = $this->target()->id;
        $user->column_name = 'p7_user';
        $user->column_type = ColumnType::USER;
        $user->suuid = 'p7missing0000000000';
        $user->options = ['index_enabled' => '1'];

        // a copy of the target table whose column cache holds only the counting user column
        $target = clone $this->target();
        $cache = new \ReflectionProperty(CustomTable::class, 'cached_custom_columns');
        $cache->setAccessible(true);
        $cache->setValue($target, collect([$user]));
        $mine = new class () extends CustomColumn {
            public $p7target;

            public function getSelectTargetTableAttribute()
            {
                return $this->p7target;
            }
        };
        $mine->custom_table_id = $this->source()->id;
        $mine->column_name = 'p7_mine';
        $mine->column_type = ColumnType::SELECT_TABLE;
        $mine->p7target = $target;

        $db = getDBTableName($this->target());
        try {
            $this->invoke($this->grid(), 'getMyKeys', [$mine]);
            $this->fail('expected the missing index column to fail the lookup');
        } catch (QueryException $ex) {
            $this->assertStringStartsWith($this->expectedSql("`$db`.`column_p7missing0000000000`"), $ex->getSql());
        }
        $this->assertSame(1, $user->alterCount);
    }
}
