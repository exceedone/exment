<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 slice 8: the two R1 business-table read paths.
 *
 * - SelectTable::getPureValue() -> setSelectTableQuery(): label LIKE search on the target table
 *   of a select_table column (all_columns_table_fortest.select_table / select_table_multiple ->
 *   custom_value_view_all, label column index_text).
 * - CustomValue::getSum(): sum of a child-table column over the 1:n children of a record
 *   (parent_table -> child_table), also through CustomItem with the summary_child option.
 *
 * Pins SQL, bindings, return values (empty set, NULL / blank / missing, numeric), indexed and
 * non-indexed (index_enabled turned off in the options only), a restricted user and when the
 * index-column DDL runs. Rows are written with DB::table() inside the test transaction.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class R1ReadPathQueryTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    protected function source(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    protected function viewAll(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
    }

    protected function parentTable(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
    }

    protected function childTable(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
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

    /**
     * @return array{0: mixed, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function logged(\Closure $callback, string $needle): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        return [$result, array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }))];
    }

    protected function counting(CustomTable $table, string $name, string $type): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->id = 999998;
        $column->custom_table_id = $table->id;
        $column->column_name = $name;
        $column->column_type = $type;
        $column->suuid = 'p8missing' . substr(md5($name), 0, 11);
        $column->options = ['index_enabled' => '1'];
        return $column;
    }

    // ------------------------------------------------------- SelectTable label

    protected function pureValue(string $column_name, string $label)
    {
        System::clearRequestSession();
        $column = CustomColumn::getEloquent($column_name, $this->source());
        return CustomItem::getItem($column)->getPureValue($label);
    }

    protected function labelSql(string $key): string
    {
        $db = getDBTableName($this->viewAll());
        return "select `id` from `$db` where $key LIKE ? and `$db`.`deleted_at` is null";
    }

    protected function labelIndexKey(): string
    {
        return '`column_' . CustomColumn::getEloquent('index_text', $this->viewAll())->suuid . '`';
    }

    protected function labelJsonKey(): string
    {
        return "json_unquote(json_extract(`value`, '$.\"index_text\"'))";
    }

    /** @return array<int> ids of view_all whose label matches, as the current user sees them */
    protected function labelMatches(string $label, bool $all): array
    {
        return $this->viewAll()->getValueQuery()->get()->filter(function ($row) use ($label, $all) {
            $text = strval(array_get($row->value, 'index_text'));
            return $all ? mb_stripos($text, $label) !== false : mb_stripos($text, $label) === 0;
        })->pluck('id')->map(function ($id) {
            return intval($id);
        })->sort()->values()->all();
    }

    protected function someLabel(): string
    {
        $row = $this->viewAll()->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->get()->first(function ($row) {
            return !is_nullorempty(array_get($row->value, 'index_text'));
        });
        return strval(array_get($row->value, 'index_text'));
    }

    public function testLabelForwardSearchIndexedAndJson(): void
    {
        $label = $this->someLabel();
        $expected = $this->labelMatches($label, false);
        $this->assertNotEmpty($expected);

        [$id, $log] = $this->logged(function () use ($label) {
            return $this->pureValue('select_table', $label);
        }, ' LIKE ');
        $this->assertCount(1, $log);
        $this->assertSame($this->labelSql($this->labelIndexKey()), $log[0]['query']);
        $this->assertSame([$label . '%'], $log[0]['bindings']);
        $this->assertContains(intval($id), $expected);

        $this->unindex($this->viewAll(), 'index_text');
        [$json, $log] = $this->logged(function () use ($label) {
            return $this->pureValue('select_table', $label);
        }, ' LIKE ');
        $this->assertSame($this->labelSql($this->labelJsonKey()), $log[0]['query']);
        $this->assertSame([$label . '%'], $log[0]['bindings']);
        $this->assertSame($id, $json);
    }

    public function testLabelPartialSearchAndMultipleColumn(): void
    {
        System::filter_search_type(FilterSearchType::ALL);
        System::clearCache();
        System::clearRequestSession();
        $this->assertSame(FilterSearchType::ALL, System::filter_search_type());

        $label = mb_substr($this->someLabel(), 1);
        $expected = $this->labelMatches($label, true);
        $this->assertNotEmpty($expected);

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex($this->viewAll(), 'index_text');
            }
            [$ids, $log] = $this->logged(function () use ($label) {
                return $this->pureValue('select_table_multiple', $label);
            }, ' LIKE ');
            $this->assertSame($this->labelSql($json ? $this->labelJsonKey() : $this->labelIndexKey()), $log[0]['query']);
            $this->assertSame(['%' . $label . '%'], $log[0]['bindings']);
            $this->assertIsArray($ids, 'a multiple column returns every match');
            $ids = array_map('intval', $ids);
            sort($ids);
            $this->assertSame($expected, $ids, $json ? 'json' : 'indexed');
        }
    }

    public function testLabelWithoutMatchIsNull(): void
    {
        $this->assertNull($this->pureValue('select_table', 'p8-no-such-label'));
        $this->unindex($this->viewAll(), 'index_text');
        $this->assertNull($this->pureValue('select_table', 'p8-no-such-label'));
    }

    public function testLabelSearchForRestrictedUser(): void
    {
        // select_table_2 points at custom_value_edit, of which user2 sees only part
        $target = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $rows = $target->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $visible = $target->getValueQuery()->get();
        $this->assertLessThan($rows->count(), $visible->count(), 'precondition: user2 sees only part of the target table');

        $startsAny = function ($label) use ($visible) {
            return $visible->contains(function ($row) use ($label) {
                return mb_stripos(strval(array_get($row->value, 'index_text')), $label) === 0;
            });
        };
        $hidden = $rows->first(function ($row) use ($visible, $startsAny) {
            $label = strval(array_get($row->value, 'index_text'));
            return $label !== '' && !$visible->contains('id', $row->id) && !$startsAny($label);
        });
        $this->assertNotNull($hidden, 'precondition: a label only rows hidden from user2 carry');
        $hiddenLabel = strval(array_get($hidden->value, 'index_text'));
        $shown = $visible->first(function ($row) use ($rows) {
            $label = strval(array_get($row->value, 'index_text'));
            return $label !== '' && $rows->filter(function ($r) use ($label) {
                return mb_stripos(strval(array_get($r->value, 'index_text')), $label) === 0;
            })->count() === 1;
        });
        $this->assertNotNull($shown, 'precondition: a visible row with a label of its own');
        $shownLabel = strval(array_get($shown->value, 'index_text'));

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex($target, 'index_text');
            }
            $mode = $json ? 'json' : 'indexed';
            $this->assertNull($this->pureValue('select_table_2', $hiddenLabel), "$mode: hidden rows are not matched");
            $this->assertSame(intval($shown->id), intval($this->pureValue('select_table_2', $shownLabel)), "$mode: visible row is matched");
        }

        // the admin finds the hidden row: the null above comes from the scope, not the label
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
        $this->assertNotNull($this->pureValue('select_table_2', $hiddenLabel));
    }

    /**
     * An indexed label column whose index column does not exist: alterColumn (here a counter)
     * runs once while the condition is built, and the condition names the index column.
     */
    public function testLabelMissingIndexColumnRunsTheDdlOnce(): void
    {
        $label = $this->counting($this->viewAll(), 'p8_label', ColumnType::TEXT);
        $item = CustomItem::getItem(CustomColumn::getEloquent('select_table', $this->source()));
        $query = $this->viewAll()->getValueModel()::query();
        $method = new \ReflectionMethod($item, 'setSelectTableQuery');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($item, $query, $label, 'abc'));
        $this->assertSame(1, $label->alterCount);
        $db = getDBTableName($this->viewAll());
        $this->assertSame("select * from `$db` where `{$this->idxName($label)}` LIKE ? and `$db`.`deleted_at` is null", $query->toSql());
    }

    protected function idxName(CustomColumn $column): string
    {
        return 'column_' . $column->suuid;
    }

    // ------------------------------------------------------------------ getSum

    protected function parentWithChildren(array $values): CustomValue
    {
        $now = \Carbon\Carbon::now();
        $parent_id = \DB::table(getDBTableName($this->parentTable()))->insertGetId([
            'value' => json_encode(['text' => 'p8-parent']), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($values as $value) {
            \DB::table(getDBTableName($this->childTable()))->insert([
                'value' => json_encode($value), 'parent_id' => $parent_id, 'parent_type' => $this->parentTable()->table_name,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        System::clearRequestSession();
        return $this->parentTable()->getValueModel()->query()->find($parent_id);
    }

    protected function sumSql(string $key): string
    {
        $db = getDBTableName($this->childTable());
        return "select sum($key) as aggregate from `$db` where `$db`.`parent_type` = ? and `$db`.`parent_id` = ?"
            . " and `$db`.`parent_id` is not null and `$db`.`deleted_at` is null";
    }

    protected function childColumn(string $name): CustomColumn
    {
        return CustomColumn::getEloquent($name, $this->childTable());
    }

    public function testSumOfJsonColumnWithNullBlankAndMissingValues(): void
    {
        $parent = $this->parentWithChildren([
            ['integer' => '5', 'decimal' => '1.25'],
            ['integer' => '7', 'decimal' => '2.5'],
            ['integer' => null, 'decimal' => null],
            ['integer' => '', 'decimal' => ''],
            ['text' => 'no numbers'],
        ]);
        [$sum, $log] = $this->logged(function () use ($parent) {
            return $parent->getSum($this->childColumn('integer'));
        }, 'aggregate');
        $this->assertCount(1, $log);
        $this->assertSame($this->sumSql("json_unquote(json_extract(`value`, '$.\"integer\"'))"), $log[0]['query']);
        $this->assertSame([$this->parentTable()->table_name, $parent->id], $log[0]['bindings']);
        // the aggregate comes back as the driver's string
        $this->assertSame('12', $sum);
        $this->assertSame('3.75', $parent->getSum($this->childColumn('decimal')));
    }

    public function testSumOfIndexedColumn(): void
    {
        $parent = $this->parentWithChildren([
            ['multiples_of_3' => '1'], ['multiples_of_3' => '1'], ['multiples_of_3' => '0'], ['multiples_of_3' => null],
        ]);
        $column = $this->childColumn('multiples_of_3');
        [$sum, $log] = $this->logged(function () use ($parent, $column) {
            return $parent->getSum($column);
        }, 'aggregate');
        $this->assertSame($this->sumSql('`column_' . $column->suuid . '`'), $log[0]['query']);
        $this->assertSame('2', $sum);

        $this->unindex($this->childTable(), 'multiples_of_3');
        [$json, $log] = $this->logged(function () use ($parent) {
            return $parent->getSum($this->childColumn('multiples_of_3'));
        }, 'aggregate');
        $this->assertSame($this->sumSql("json_unquote(json_extract(`value`, '$.\"multiples_of_3\"'))"), $log[0]['query']);
        $this->assertSame('2', $json);
    }

    public function testSumOfEmptySetIsZero(): void
    {
        $parent = $this->parentWithChildren([]);
        $this->assertSame(0, $parent->getSum($this->childColumn('integer')));
        $this->assertSame(0, $parent->getSum($this->childColumn('multiples_of_3')));
    }

    public function testSumThroughSummaryChildItem(): void
    {
        $parent = $this->parentWithChildren([['integer' => '4'], ['integer' => '6']]);
        $item = CustomItem::getItem($this->childColumn('integer'))->options(['summary_child' => true]);
        $item->setCustomValue($parent);
        $this->assertSame('10', $item->value());
        $this->assertSame('10', $parent->getSum($this->childColumn('integer')));
    }

    public function testSumForRestrictedUserCoversVisibleChildrenOnly(): void
    {
        $column = $this->childColumn('integer');
        $parents = $this->parentTable()->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();

        $checked = 0;
        foreach ($parents as $parent) {
            $children = $this->childTable()->getValueModel()->query()->withoutGlobalScopes()
                ->where('parent_id', $parent->id)->where('parent_type', $this->parentTable()->table_name)->get();
            $visible = $this->childTable()->getValueQuery()
                ->where('parent_id', $parent->id)->where('parent_type', $this->parentTable()->table_name)->get();
            if ($visible->count() === 0 || $visible->count() === $children->count()) {
                continue;
            }
            $expected = $visible->sum(function ($row) {
                return floatval(array_get($row->value, 'integer'));
            });
            $this->assertEquals($expected, $parent->getSum($column), "parent {$parent->id}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'precondition: user2 sees only part of the children of some parent');
    }

    /**
     * An indexed child column whose index column does not exist: alterColumn (here a counter)
     * runs once, before the aggregate, and the aggregate names the index column.
     */
    public function testSumMissingIndexColumnRunsTheDdlOnce(): void
    {
        $column = $this->counting($this->childTable(), 'p8_amount', ColumnType::INTEGER);
        $parent = $this->parentWithChildren([['integer' => '1']]);
        try {
            $parent->getSum($column);
            $this->fail('expected the missing index column to fail the aggregate');
        } catch (QueryException $ex) {
            $this->assertSame($this->sumSql('`' . $this->idxName($column) . '`'), $ex->getSql());
        }
        $this->assertSame(1, $column->alterCount);
    }
}
