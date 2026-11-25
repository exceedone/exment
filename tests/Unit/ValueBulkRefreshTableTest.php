<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\RefreshDataService;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 W2, O2: RefreshDataService::refreshTable() - after truncating a table it
 * removes the key of every select_table column pointing at that table, one JSON_REMOVE per column
 * over the rows whose key is not null (soft-deleted rows included).
 *
 * NOT transactional: refreshTable truncates (implicit commit) and deletes the table's attachment
 * directory. Run it alone, on a restored database snapshot and filesystem fixture.
 */
class ValueBulkRefreshTableTest extends UnitTestBase
{
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::clearRequestSession();
    }

    public function testRefreshTableRemovesTheSelectTableKeysPointingAtIt(): void
    {
        $target = CustomTable::getEloquent('custom_value_edit');
        $source = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $column = CustomColumn::getEloquent('select_table_2', $source);
        $this->assertSame($target->id, $column->select_target_table->id, 'precondition: select_table_2 points at custom_value_edit');
        $db = getDBTableName($source);

        $selected = $target->getSelectedTableColumns();
        $this->assertTrue($selected->contains(function ($c) use ($column) {
            return $c->id == $column->id;
        }));

        // rows: key set, key explicit null, key set and soft-deleted, key missing
        $ids = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->limit(4)->pluck('id')->map(function ($v) {
            return intval($v);
        })->all();
        [$live, $null, $trashed, $missing] = $ids;
        // the JSON is rewritten as text: the arrangement does not depend on a driver's JSON update grammar
        static::rawJsonWrite($db, $live, ['select_table_2' => '1']);
        static::rawJsonWrite($db, $null, ['select_table_2' => null]);
        static::rawJsonWrite($db, $trashed, ['select_table_2' => '2']);
        \DB::table($db)->where('id', $trashed)->update(['deleted_at' => now()]);
        static::rawJsonWrite($db, $missing, ['select_table_2' => '__unset__']);
        $before = $this->values($db);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            RefreshDataService::refreshTable(['custom_value_edit']);
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }

        // one key removal per pointing column, no binding: json_remove on MySQL / MariaDB, JSON_MODIFY(..., NULL) on SQL Server
        $needle = static::sqlServer() ? 'JSON_MODIFY' : 'json_remove';
        $removes = array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }));
        $this->assertCount($selected->count(), $removes);
        foreach ($selected as $c) {
            $t = getDBTableName($c->custom_table_cache);
            $n = $c->column_name;
            if (static::sqlServer()) {
                $expected = 'update ' . static::q($t) . ' set ' . static::q('value') . ' = JSON_MODIFY(' . static::q('value') . ", '$.\"$n\"', NULL) where " . static::jsonExtract(static::q('value'), $n) . ' is not null';
            } else {
                $expected = "update `$t` set `value` = json_remove(`value`, '$.\"$n\"') where (json_extract(`value`, '$.\"$n\"') is not null AND json_type(json_extract(`value`, '$.\"$n\"')) != 'NULL')";
            }
            $this->assertCount(1, array_filter($removes, function ($q) use ($expected) {
                return $q['query'] === $expected && $q['bindings'] === [];
            }), $expected . "\n" . json_encode(array_column($removes, 'query')));
        }

        $after = $this->values($db);
        // every row (soft-deleted too) whose pointing key is not null loses that key; nothing else changes
        $pointing = $selected->filter(function ($c) use ($source) {
            return $c->custom_table_id == $source->id;
        })->pluck('column_name')->all();
        $expected = $before;
        foreach ($expected as $id => $value) {
            foreach ($pointing as $n) {
                if (array_key_exists($n, $value) && !is_null($value[$n])) {
                    unset($expected[$id][$n]);
                }
            }
        }
        $this->assertArrayNotHasKey('select_table_2', $expected[$live]);
        $this->assertArrayNotHasKey('select_table_2', $expected[$trashed]);
        // whereNotNull on a JSON path also leaves out an explicit JSON null: that key stays (stale)
        $this->assertEquals($expected, $after, 'only the select_table_2 key of the not-null rows removed');
        $this->assertArrayHasKey('select_table_2', $after[$null]);
        $this->assertNull($after[$null]['select_table_2']);
        $this->assertSame(0, \DB::table(getDBTableName($target))->count(), 'the refreshed table is truncated');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function values(string $db): array
    {
        return \DB::table($db)->orderBy('id')->get()->mapWithKeys(function ($row) {
            return [intval($row->id) => json_decode($row->value, true) ?? []];
        })->all();
    }
}
