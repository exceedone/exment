<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 W2: set-based writes of the JSON `value` on business tables, through their
 * production callers (refreshTable is in ValueBulkRefreshTableTest: it truncates).
 *
 * O1 column deletion: one JSON_REMOVE over every row of the table - soft-deleted rows and rows the
 *    current user cannot see included (the Eloquent scopes are not applied), no binding.
 * O3 exment:patchdata rmcomma: rows chosen through the value model (permission scope applies,
 *    soft-deleted rows included), one JSON_SET per row by id.
 * Unrelated keys and rows without the key stay as they were.
 */
class ValueBulkWriteTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::clearRequestSession();
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION);
    }

    /**
     * @return array<int, array<string, mixed>>  id => value
     */
    protected function values(string $db): array
    {
        return \DB::table($db)->orderBy('id')->get()->mapWithKeys(function ($row) {
            return [intval($row->id) => json_decode($row->value, true) ?? []];
        })->all();
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

    /**
     * @return array<int, array{query: string, bindings: array<mixed>}>
     */
    protected function updatesOf(array $log, string $db): array
    {
        return array_values(array_filter($log, function ($q) use ($db) {
            return stripos($q['query'], 'update ' . static::q($db)) === 0;
        }));
    }

    /**
     * Set (or, with '__unset__', remove) keys of one row's JSON, rewriting the JSON text.
     *
     * @param array<string, mixed> $changes
     */
    protected function rawJson(string $db, int $id, array $changes): void
    {
        $value = json_decode((string)\DB::table($db)->where('id', $id)->value('value'), true) ?? [];
        foreach ($changes as $k => $v) {
            if ($v === '__unset__') {
                unset($value[$k]);
            } else {
                $value[$k] = $v;
            }
        }
        \DB::table($db)->where('id', $id)->update(['value' => json_encode($value)]);
    }

    /** Four rows: key set, key explicit null, key set but soft-deleted, key missing. */
    protected function seedKey(string $db, string $key, array $set): array
    {
        $ids = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->limit(4)->pluck('id')->map(function ($v) {
            return intval($v);
        })->all();
        $this->assertCount(4, $ids);
        [$live, $null, $trashed, $missing] = $ids;
        // the JSON is rewritten as text: the arrangement does not depend on a driver's JSON update grammar
        $this->rawJson($db, $live, [$key => $set[0]]);
        $this->rawJson($db, $null, [$key => null]);
        $this->rawJson($db, $trashed, [$key => $set[1]]);
        \DB::table($db)->where('id', $trashed)->update(['deleted_at' => now()]);
        $this->rawJson($db, $missing, [$key => '__unset__']);
        $this->assertArrayNotHasKey($key, json_decode(\DB::table($db)->where('id', $missing)->value('value'), true) ?? []);
        $this->assertArrayHasKey($key, json_decode(\DB::table($db)->where('id', $null)->value('value'), true));
        return [$live, $null, $trashed, $missing];
    }

    /**
     * Every row equals $before except $changes (id => [key => value|unset]).
     */
    protected function assertOnly(array $before, array $after, array $changes): void
    {
        $expected = $before;
        foreach ($changes as $id => $keys) {
            foreach ($keys as $k => $v) {
                if ($v === '__unset__') {
                    unset($expected[$id][$k]);
                } else {
                    $expected[$id][$k] = $v;
                }
            }
        }
        $this->assertEquals($expected, $after);
    }

    /** Log in as a test user who sees no row of the table; fail when there is none. */
    protected function beUserSeeingNoRow(CustomTable $table): void
    {
        foreach ([TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_DEV_USERB, TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC, TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD] as $id) {
            $this->be(LoginUser::find($id));
            System::clearRequestSession();
            if ($table->getValueQuery()->count() === 0) {
                return;
            }
        }
        $this->fail('no test user without access to ' . $table->table_name);
    }

    // ------------------------------------------------------------- O1

    public function testDeletingAColumnRemovesItsKeyFromEveryRow(): void
    {
        $table = $this->table();
        $db = getDBTableName($table);
        $column = CustomColumn::create([
            'custom_table_id' => $table->id,
            'column_name' => 'w2_col',
            'column_view_name' => 'w2_col',
            'column_type' => ColumnType::TEXT,
            'options' => [],
        ]);
        [$live, $null, $trashed, $missing] = $this->seedKey($db, 'w2_col', ['a', 'c']);
        $before = $this->values($db);

        // a user who sees none of these rows deletes the column (scope would hide them all)
        $this->beUserSeeingNoRow($table);

        [, $log] = $this->logged(function () use ($column) {
            return CustomColumn::find($column->id)->delete();
        });
        $updates = $this->updatesOf($log, $db);
        $this->assertCount(1, $updates);
        // MySQL / MariaDB: json_remove; SQL Server: JSON_MODIFY with NULL (lax mode removes the property). No binding either way.
        $expected = static::sqlServer()
            ? 'update ' . static::q($db) . ' set ' . static::q('value') . ' = JSON_MODIFY(' . static::q('value') . ", '$.\"w2_col\"', NULL)"
            : "update `$db` set `value` = json_remove(`value`, '$.\"w2_col\"')";
        $this->assertSame($expected, $updates[0]['query']);
        $this->assertSame([], $updates[0]['bindings']);

        $this->assertOnly($before, $this->values($db), [
            $live => ['w2_col' => '__unset__'],
            $null => ['w2_col' => '__unset__'],
            $trashed => ['w2_col' => '__unset__'],
        ]);
        $this->assertArrayNotHasKey('w2_col', $this->values($db)[$missing]);
    }

    // ------------------------------------------------------------- O3

    public function testRmcommaSetsEachCommaValueByIdIncludingSoftDeletedRows(): void
    {
        $table = $this->table();
        $db = getDBTableName($table);
        [$live, $null, $trashed, $missing] = $this->seedKey($db, 'decimal', ['1,234.50', '2,000']);
        $plain = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->skip(4)->value('id');
        $this->rawJson($db, intval($plain), ['decimal' => '12.5', 'currency' => '3,000']);
        $before = $this->values($db);

        [, $log] = $this->logged(function () {
            return $this->artisan('exment:patchdata', ['action' => 'rmcomma'])->run();
        });

        $updates = $this->updatesOf($log, $db);
        if (static::sqlServer()) {
            // JSON_MODIFY(value, <path>, <value>): path and value are bindings
            $statement = 'update ' . static::q($db) . ' set ' . static::q('value') . ' = JSON_MODIFY(' . static::q('value') . ', ?, ?) where ' . static::q('id') . ' = ?';
            $expected = [
                [$statement, ['$."decimal"', '1234.50', $live]],
                [$statement, ['$."decimal"', '2000', $trashed]],
                [$statement, ['$."currency"', '3000', intval($plain)]],
            ];
            $actual = array_map(function ($q) {
                return [$q['query'], [$q['bindings'][0], $q['bindings'][1], intval($q['bindings'][2])]];
            }, $updates);
        } else {
            $sql = "update `$db` set `value` = json_set(`value`, '$.\"%s\"', ?) where `id` = ?";
            $expected = [
                [sprintf($sql, 'decimal'), ['1234.50', $live]],
                [sprintf($sql, 'decimal'), ['2000', $trashed]],
                [sprintf($sql, 'currency'), ['3000', intval($plain)]],
            ];
            $actual = array_map(function ($q) {
                return [$q['query'], [$q['bindings'][0], intval($q['bindings'][1])]];
            }, $updates);
        }
        $this->assertEqualsCanonicalizing($expected, $actual, 'one statement per comma row, soft-deleted included');

        $this->assertOnly($before, $this->values($db), [
            $live => ['decimal' => '1234.50'],
            $trashed => ['decimal' => '2000'],
            intval($plain) => ['currency' => '3000'],
        ]);
        $this->assertArrayHasKey('decimal', $this->values($db)[$null], 'explicit null untouched');
        $this->assertNull($this->values($db)[$null]['decimal']);
        $this->assertArrayNotHasKey('decimal', $this->values($db)[$missing]);
    }

    public function testRmcommaSkipsRowsTheLoggedInUserCannotSee(): void
    {
        $table = $this->table();
        $db = getDBTableName($table);
        $id = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->rawJson($db, intval($id), ['decimal' => '9,999']);

        $this->beUserSeeingNoRow($table);

        [, $log] = $this->logged(function () {
            return $this->artisan('exment:patchdata', ['action' => 'rmcomma'])->run();
        });
        $this->assertSame([], $this->updatesOf($log, $db), 'the permission scope chooses the rows');
        $this->assertSame('9,999', $this->values($db)[intval($id)]['decimal']);
    }
}
