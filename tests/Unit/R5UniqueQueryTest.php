<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 slice 12 (R5): uniqueness queries of CustomTable::validatorUniques().
 *
 * Production callers CustomValue::validateSaving() (create = new model, update = loaded model)
 * and CustomTable::validateValue(), on all_columns_table_fortest whose seeded MULTI_UNIQUES
 * setting is (integer, time, yesno), all indexed. Pins the count query (key per column, no table
 * prefix, no scope, deleted_at, self exclusion, ignore ids), NULL / missing / empty / array values,
 * the skipped multi-value settings, outcomes and messages, and when the index-column DDL runs.
 * Rows and options are written with DB::table() inside the test transaction.
 */
class R5UniqueQueryTest extends UnitTestBase
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

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    protected function db(): string
    {
        return getDBTableName($this->table());
    }

    protected function column(string $name): CustomColumn
    {
        return CustomColumn::getEloquent($name, $this->table());
    }

    protected function idx(string $name): string
    {
        return static::q('column_' . $this->column($name)->suuid);
    }

    protected function jsonKey(string $name): string
    {
        return static::jsonText(static::q('value'), $name);
    }

    protected function unindex(string $name): void
    {
        $column = $this->column($name);
        $options = $column->options ?? [];
        unset($options['index_enabled']);
        \DB::table('custom_columns')->where('id', $column->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        System::clearRequestSession();
        $this->assertFalse((bool)$this->column($name)->index_enabled, "precondition: $name not indexed");
    }

    /** Raw stored values of a row. */
    protected function raw(int $id): array
    {
        return (array)json_decode(\DB::table($this->db())->where('id', $id)->value('value'), true);
    }

    /** @return array<int> */
    protected function rowIds(int $count): array
    {
        return \DB::table($this->db())->whereNull('deleted_at')->orderBy('id')->limit($count)->pluck('id')->map(function ($v) {
            return intval($v);
        })->all();
    }

    protected function triple(int $id): array
    {
        $raw = $this->raw($id);
        return ['integer' => $raw['integer'] ?? null, 'time' => $raw['time'] ?? null, 'yesno' => $raw['yesno'] ?? null];
    }

    protected function message(array $names): string
    {
        $texts = array_map(function ($n) {
            return $this->column($n)->column_view_name;
        }, $names);
        return exmtrans('custom_value.help.multiple_uniques', implode(exmtrans('common.separate_word'), $texts));
    }

    /**
     * validateSaving() of a new (create) or loaded (update) record, with the count queries.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{query: string, bindings: array<mixed>}>}
     */
    protected function saving(array $values, ?int $id = null, array $options = []): array
    {
        System::clearRequestSession();
        $model = isset($id) ? $this->table()->getValueModel()->query()->withoutGlobalScopes()->find($id) : $this->table()->getValueModel();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $model->validateSaving(['value' => $values], $options);
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $errors = $result === true ? [] : $result;
        $log = array_values(array_filter($log, function ($q) {
            return strpos($q['query'], 'select count(*) as aggregate from ' . static::q($this->db())) === 0;
        }));
        return [$errors, $log];
    }

    protected function countSql(array $conditions, array $extra = []): string
    {
        return 'select count(*) as aggregate from ' . static::q($this->db()) . ' where ' . implode(' and ', array_merge($conditions, $extra, [static::q('deleted_at') . ' is null']));
    }

    protected function isUniqueError(array $errors, string $key, array $names): bool
    {
        return in_array($this->message($names), (array)($errors[$key] ?? []), true);
    }

    // ---------------------------------------------------------------- create

    public function testCreateDuplicateAndDistinctIndexed(): void
    {
        [$r] = $this->rowIds(1);
        $values = $this->triple($r);
        $this->assertNotContains(null, $values, 'precondition: the row has all three values');
        $names = ['integer', 'time', 'yesno'];

        [$errors, $log] = $this->saving($values);
        $this->assertCount(1, $log);
        $this->assertSame($this->countSql(["{$this->idx('integer')} = ?", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"]), $log[0]['query']);
        $this->assertSame(array_values($values), $log[0]['bindings']);
        foreach ($names as $name) {
            $this->assertTrue($this->isUniqueError($errors, $name, $names), "duplicate reported on $name (appendErrorAllColumn)");
        }

        $values['integer'] = 987654321;
        [$errors, $log] = $this->saving($values);
        $this->assertCount(1, $log);
        $this->assertFalse($this->isUniqueError($errors, 'integer', $names), 'distinct combination');
    }

    public function testCreateThroughValidateValueReportsTheFirstColumnOnly(): void
    {
        [$r] = $this->rowIds(1);
        $validator = $this->table()->validateValue(['value' => $this->triple($r)], null, ['checkCustomValueExists' => false]);
        // the uniqueness errors are appended messages, read through getMessages() as the callers do
        $this->assertTrue($validator->fails());
        $messages = $validator->getMessages();
        $names = ['integer', 'time', 'yesno'];
        $this->assertTrue($this->isUniqueError($messages, 'integer', $names), json_encode($messages, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($this->isUniqueError($messages, 'time', $names), 'without appendErrorAllColumn only the first column');
    }

    public function testCreateDuplicateWithJsonKey(): void
    {
        [$r] = $this->rowIds(1);
        $values = $this->triple($r);
        $this->unindex('integer');
        [$errors, $log] = $this->saving($values);
        $this->assertSame($this->countSql(["{$this->jsonKey('integer')} = ?", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"]), $log[0]['query']);
        $this->assertTrue($this->isUniqueError($errors, 'integer', ['integer', 'time', 'yesno']));
    }

    // ---------------------------------------------------------------- update

    public function testUpdateExcludesItselfAndIgnoredIds(): void
    {
        [$r, $s] = $this->rowIds(2);
        $names = ['integer', 'time', 'yesno'];

        [$errors, $log] = $this->saving($this->triple($r), $r);
        $this->assertSame($this->countSql(["{$this->idx('integer')} = ?", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"], [static::q('id') . ' <> ?']), $log[0]['query']);
        $this->assertSame(array_merge(array_values($this->triple($r)), [$r]), $log[0]['bindings']);
        $this->assertFalse($this->isUniqueError($errors, 'integer', $names), 'its own values are no duplicate');

        [$errors] = $this->saving($this->triple($s), $r);
        $this->assertTrue($this->isUniqueError($errors, 'integer', $names), 'the values of another row are');

        [$errors, $log] = $this->saving($this->triple($s), $r, ['uniqueCheckIgnoreIds' => [$s]]);
        $this->assertSame($this->countSql(["{$this->idx('integer')} = ?", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"], [static::q('id') . ' <> ?', static::q('id') . ' not in (?)']), $log[0]['query']);
        $this->assertFalse($this->isUniqueError($errors, 'integer', $names), 'ignored ids do not count');
    }

    /** Keys missing from the input of an update come from the stored record. */
    public function testUpdateTakesMissingKeysFromTheRecord(): void
    {
        [$r, $s] = $this->rowIds(2);
        $other = $this->triple($s);
        // integer of row s, the other two missing: time and yesno come from row r itself
        [$errors, $log] = $this->saving(['integer' => $other['integer']], $r);
        $mine = $this->triple($r);
        // the stored values are read back through getValue(PURE_VALUE), so their PHP types may differ
        $this->assertEquals([$other['integer'], $mine['time'], $mine['yesno'], $r], array_map(function ($b) {
            return $b;
        }, $log[0]['bindings']));
    }

    // ------------------------------------------------- soft delete and scope

    public function testSoftDeletedRowsAreNoDuplicate(): void
    {
        [$r] = $this->rowIds(1);
        $values = $this->triple($r);
        \DB::table($this->db())->where('id', $r)->update(['deleted_at' => \Carbon\Carbon::now()]);
        [$errors] = $this->saving($values);
        $this->assertFalse($this->isUniqueError($errors, 'integer', ['integer', 'time', 'yesno']));
    }

    /** Existing behaviour: the count reads the whole table, rows the user cannot see included. */
    public function testRestrictedUserSeesDuplicatesOfHiddenRows(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $visible = $this->table()->getValueQuery()->pluck('id')->map(function ($v) {
            return intval($v);
        })->all();
        $hidden = intval(\DB::table($this->db())->whereNull('deleted_at')->whereNotIn('id', $visible)->orderBy('id')->value('id'));
        $this->assertGreaterThan(0, $hidden, 'precondition: a row user2 cannot see');

        [$errors, $log] = $this->saving($this->triple($hidden));
        $this->assertStringNotContainsString('custom_value_authoritables', $log[0]['query']);
        $this->assertTrue($this->isUniqueError($errors, 'integer', ['integer', 'time', 'yesno']));
    }

    // -------------------------------------------------- null / empty / arrays

    public function testNullMissingAndEmptyValues(): void
    {
        [$r] = $this->rowIds(1);
        $values = $this->triple($r);

        // null on create: "is null" on the index column
        [$errors, $log] = $this->saving(['integer' => null, 'time' => $values['time'], 'yesno' => $values['yesno']]);
        $this->assertSame($this->countSql(["{$this->idx('integer')} is null", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"]), $log[0]['query']);

        // missing on create: the same - no stored record to read it from
        [, $missing] = $this->saving(['time' => $values['time'], 'yesno' => $values['yesno']]);
        $this->assertSame($log[0]['query'], $missing[0]['query']);

        // empty string: compared as ''
        [, $empty] = $this->saving(['integer' => '', 'time' => $values['time'], 'yesno' => $values['yesno']]);
        $this->assertSame($this->countSql(["{$this->idx('integer')} = ?", "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?"]), $empty[0]['query']);
        $this->assertSame('', $empty[0]['bindings'][0]);

        // every value empty: no query at all
        [$errors, $none] = $this->saving(['integer' => null, 'time' => '', 'yesno' => null]);
        $this->assertCount(0, $none);

        // null with the JSON key: Laravel's JSON null check
        $this->unindex('integer');
        [, $json] = $this->saving(['integer' => null, 'time' => $values['time'], 'yesno' => $values['yesno']]);
        // MySQL / MariaDB distinguish a missing key from JSON null (json_type 'NULL'); SQL Server's json_value() is NULL
        // for both a missing key and a JSON null, so a single IS NULL covers the same rows
        $nullCheck = static::sqlServer()
            ? static::jsonExtract(static::q('value'), 'integer') . ' is null'
            : "(json_extract(`value`, '$.\"integer\"') is null OR json_type(json_extract(`value`, '$.\"integer\"')) = 'NULL')";
        $this->assertSame($this->countSql([
            $nullCheck,
            "{$this->idx('time')} = ?", "{$this->idx('yesno')} = ?",
        ]), $json[0]['query']);
    }

    public function testArrayValueIsComparedAsItsJsonText(): void
    {
        [$r] = $this->rowIds(1);
        $values = $this->triple($r);
        [, $log] = $this->saving(['integer' => ['5', '', null, '7'], 'time' => $values['time'], 'yesno' => $values['yesno']]);
        $this->assertSame(json_encode(array_filter(['5', '', null, '7'])), $log[0]['bindings'][0]);
    }

    // ---------------------------------------------- single and multi-value

    /** Injects the MULTI_UNIQUES settings of the table for this request. */
    protected function multiUniques(array $sets): void
    {
        $multis = [];
        foreach ($sets as $set) {
            $multi = new CustomColumnMulti();
            $multi->custom_table_id = $this->table()->id;
            $multi->multisetting_type = MultisettingType::MULTI_UNIQUES;
            foreach (array_values($set) as $i => $column) {
                $multi->{'unique' . ($i + 1)} = $column;
            }
            $multis[] = $multi;
        }
        System::clearRequestSession();
        System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, CustomColumnMulti::getTableName()), collect($multis));
    }

    /** A setting holding a multi-value column is dropped whole: no uniqueness query at all. */
    public function testSettingWithAMultiValueColumnIsSkipped(): void
    {
        $this->multiUniques([[$this->column('select_multiple')->id, $this->column('integer')->id]]);
        $model = $this->table()->getValueModel();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $result = $model->validateSaving(['value' => ['select_multiple' => ['foo'], 'integer' => 1]]);
        $log = \DB::getQueryLog();
        \DB::disableQueryLog();
        $this->assertTrue($result === true || !isset($result['integer']));
        $this->assertCount(0, array_filter($log, function ($q) {
            return strpos($q['query'], 'select count(*) as aggregate from `' . $this->db() . '`') === 0;
        }));
    }

    public function testSingleUniqueColumnOption(): void
    {
        $column = $this->column('text');
        $options = $column->options ?? [];
        $options['unique'] = '1';
        \DB::table('custom_columns')->where('id', $column->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        $this->multiUniques([]);

        // a row that has a text value (the fixture data is random: not every row carries the key)
        $r = collect($this->rowIds(100))->first(function ($id) {
            return ($this->raw($id)['text'] ?? '') !== '';
        });
        $this->assertNotNull($r, 'precondition: a row with a text value');
        $text = $this->raw($r)['text'];
        [$errors, $log] = $this->saving(['text' => $text]);
        $this->assertSame($this->countSql(["{$this->idx('text')} = ?"]), $log[0]['query']);
        $this->assertTrue($this->isUniqueError($errors, 'text', ['text']));
        [$errors] = $this->saving(['text' => 'p12-no-such-text']);
        $this->assertFalse($this->isUniqueError($errors, 'text', ['text']));
    }

    // --------------------------------------------------------- missing index

    public function testMissingIndexColumnRunsTheDdlOnce(): void
    {
        $counting = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $counting->id = 999997;
        $counting->custom_table_id = $this->table()->id;
        $counting->column_name = 'p12_code';
        $counting->column_view_name = 'p12_code';
        $counting->column_type = ColumnType::TEXT;
        $counting->suuid = 'p12missing000000000';
        $counting->options = ['index_enabled' => '1', 'unique' => '1'];

        // no multi settings; the table's column cache holds only the counting column, as a
        // single unique column (getUniqueColumns() reads custom_columns_cache)
        $this->multiUniques([]);
        $table = $this->table();
        $cache = new \ReflectionProperty(CustomTable::class, 'cached_custom_columns');
        $cache->setAccessible(true);
        $cache->setValue($table, collect([$counting]));
        $this->assertSame($table, $this->table(), 'precondition: the request keeps one table instance');

        try {
            $table->getValueModel()->validateSaving(['value' => ['p12_code' => 'x']]);
            $this->fail('expected the missing index column to fail the count');
        } catch (QueryException $ex) {
            $this->assertSame($this->countSql([static::q('column_p12missing000000000') . ' = ?']), $ex->getSql());
        } finally {
            $cache->setValue($table, []);
        }
        $this->assertSame(1, $counting->alterCount);
    }
}
