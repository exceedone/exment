<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\ColumnItems\ParentItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Jobs\SyncMeiliReferencesJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 11 (R4): business-table lookups that build an explicit JSON path.
 *
 * Production callers, on the seeded data only:
 * - ParentItem::getImportValue() - "value-><target_column_name>" from the import setting;
 * - CustomTable::getMatchedCustomValues() - index column of an indexed "value.<c>" key, else the
 *   JSON path (import primary key);
 * - CustomTable::validateValueDestroy() -> checkReferenced() - "value-><column>" whereIn on the
 *   tables whose select_table columns point at the deleted rows;
 * - SelectTable::getKeyAndIdList() - index column of an indexed target key, else the JSON path
 *   (import by label);
 * - SyncMeiliReferencesJob::referencingQuery() - JSON contains for a multi-value column, index
 *   column when indexed and present (never DDL), else the JSON path; no scope.
 *
 * Pins SQL, bindings, results, scope behaviour, exceptions and the absence of DDL. Rows are
 * written with DB::table() inside the test transaction.
 */
class R4ExplicitJsonLookupTest extends UnitTestBase
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

    protected function table(string $name): CustomTable
    {
        return CustomTable::getEloquent($name);
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

    /** The first query carrying $needle; fails with every logged query otherwise. */
    protected function one(array $log, string $needle): array
    {
        $found = $this->matching($log, $needle);
        $this->assertNotEmpty($found, "no query with [$needle] in:\n" . implode("\n", array_column($log, 'query')));
        return $found[0];
    }

    /** Distinct text values "p11-<tag>-<id>" on the given rows, so a lookup by text is unique. */
    protected function uniqueTexts(CustomTable $table, array $ids, string $tag, string $key = 'text'): array
    {
        $texts = [];
        foreach ($ids as $id) {
            $texts[] = $text = "p11-$tag-$id";
            $this->setValueKey($table, $id, $key, $text);
        }
        return $texts;
    }

    protected function jsonKey(string $name, ?string $table = null): string
    {
        return static::jsonText(static::q(($table ? "$table." : '') . 'value'), $name);
    }

    protected function idx(CustomTable $table, string $name): string
    {
        return static::q('column_' . CustomColumn::getEloquent($name, $table)->suuid);
    }

    /** The scoped (soft-delete only) select an Eloquent query on a business table produces, in the current driver's dialect. */
    protected function scoped(string $db, string $condition, string $columns = '*', bool $first = false): string
    {
        $t = static::q($db);
        $top = $first && static::sqlServer() ? 'top 1 ' : '';
        $limit = $first && !static::sqlServer() ? ' limit 1' : '';
        return "select {$top}{$columns} from $t where $condition and $t." . static::q('deleted_at') . ' is null' . $limit;
    }

    protected function value(CustomTable $table, int $id, string $key)
    {
        return array_get(json_decode(\DB::table(getDBTableName($table))->where('id', $id)->value('value'), true), $key);
    }

    protected function setValueKey(CustomTable $table, int $id, string $key, $value): void
    {
        $db = getDBTableName($table);
        $json = (array)json_decode(\DB::table($db)->where('id', $id)->value('value'), true);
        $json[$key] = $value;
        \DB::table($db)->where('id', $id)->update(['value' => json_encode($json)]);
    }

    protected function insertRow(CustomTable $table, array $value): int
    {
        $now = \Carbon\Carbon::now();
        return intval(\DB::table(getDBTableName($table))->insertGetId([
            'suuid' => short_uuid(), 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now,
            'created_user_id' => 1, 'updated_user_id' => 1,
        ]));
    }

    /** First login (user2, dev users) that sees only part of $table, with the ids it sees. */
    protected function restrictedLogin(CustomTable $table): array
    {
        $all = \DB::table(getDBTableName($table))->whereNull('deleted_at')->count();
        foreach (['3', '6', '7', '8'] as $login) {
            $this->loginAs($login);
            $visible = $table->getValueQuery()->pluck('id')->map(function ($v) { return intval($v); })->all();
            if (count($visible) > 0 && count($visible) < $all) {
                return [$login, $visible];
            }
        }
        $this->fail('precondition: a login that sees only part of ' . $table->table_name);
    }

    // ------------------------------------------------------- R4-a ParentItem

    public function testParentImportLookupUsesTheJsonPathOfTheSettingKey(): void
    {
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $item = ParentItem::getItem($child);
        $childId = intval(\DB::table(getDBTableName($child))->whereNull('deleted_at')->orderBy('id')->value('id'));
        $label = strval($this->value($child, $childId, 'index_text'));
        $this->assertNotSame('', $label);

        [$result, $log] = $this->logged(function () use ($item, $label) {
            return $item->getImportValue($label, ['target_column_name' => 'index_text']);
        });
        $q = $this->matching($log, "'$.\"index_text\"'");
        $this->assertCount(1, $q);
        $this->assertSame($this->scoped(getDBTableName($child), $this->jsonKey('index_text') . ' = ?', '*', true), $q[0]['query'], 'explicit JSON path although index_text is indexed');
        $this->assertSame([$label], $q[0]['bindings']);
        // the item resolves against the table it was built for (the real import caller builds it on the parent table)
        $this->assertSame(['result' => true, 'value' => $childId], ['result' => $result['result'], 'value' => intval($result['value'])]);

        [$miss] = $this->logged(function () use ($item) {
            return $item->getImportValue('p11-no-such-value', ['target_column_name' => 'index_text']);
        });
        $this->assertSame(['result' => false, 'value' => 'p11-no-such-value'], $miss);

        // a key that is no column: the path is built from the setting, no column lookup decides it
        [$unknown, $log] = $this->logged(function () use ($item) {
            return $item->getImportValue('x', ['target_column_name' => 'p11_not_a_column']);
        });
        $this->assertFalse($unknown['result']);
        $this->assertCount(1, $this->matching($log, $this->jsonKey('p11_not_a_column')));
        $this->assertCount(0, $this->matching($log, 'custom_columns'));

        // no setting: the value passes through untouched, nothing is read
        [$none, $log] = $this->logged(function () use ($item) {
            return $item->getImportValue('42', []);
        });
        $this->assertSame(['result' => true, 'value' => '42'], $none);
        $this->assertCount(0, $this->matching($log, getDBTableName($child)));
    }

    public function testParentImportLookupKeepsTheScopeOfARestrictedUser(): void
    {
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        [$login, $visible] = $this->restrictedLogin($child);
        $hiddenId = intval(\DB::table(getDBTableName($child))->whereNull('deleted_at')->whereNotIn('id', $visible)->orderBy('id')->value('id'));
        $shownId = $visible[0];
        [$hidden, $shown] = $this->uniqueTexts($child, [$hiddenId, $shownId], 'parent');

        $item = ParentItem::getItem($child);
        $this->assertFalse($item->getImportValue($hidden, ['target_column_name' => 'text'])['result'], 'hidden row is not found');
        $found = $item->getImportValue($shown, ['target_column_name' => 'text']);
        $this->assertTrue($found['result']);
        $this->assertSame($shownId, intval($found['value']));
    }

    // ------------------------------------------- R4-b getMatchedCustomValues

    public function testMatchedCustomValuesIndexedJsonAndPlainKeys(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($table);
        $ids = \DB::table($db)->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id')->map(function ($v) { return intval($v); })->all();
        $labels = array_map(function ($id) use ($table) {
            return strval($this->value($table, $id, 'index_text'));
        }, $ids);
        $texts = $this->uniqueTexts($table, $ids, 'matched');

        [$byLabel, $log] = $this->logged(function () use ($table, $labels) {
            return $table->getMatchedCustomValues($labels, 'value.index_text');
        });
        $q = [$this->one($log, ' in (?, ?, ?)')];
        $this->assertSame($this->scoped($db, "{$this->idx($table, 'index_text')} in (?, ?, ?)"), $q[0]['query']);
        $this->assertSame($labels, $q[0]['bindings']);
        $this->assertSame(array_combine($labels, $ids), array_map(function ($m) {
            return intval($m->id);
        }, $byLabel));

        [$byText, $log] = $this->logged(function () use ($table, $texts) {
            return $table->getMatchedCustomValues($texts, 'value.text');
        });
        $q = [$this->one($log, ' in (?, ?, ?)')];
        $this->assertSame($this->scoped($db, "{$this->jsonKey('text')} in (?, ?, ?)"), $q[0]['query']);
        $this->assertSame(array_combine($texts, $ids), array_map(function ($m) {
            return intval($m->id);
        }, $byText));

        [$byId, $log] = $this->logged(function () use ($table, $ids) {
            return $table->getMatchedCustomValues($ids, 'id');
        });
        $q = [$this->one($log, ' in (?, ?, ?)')];
        $this->assertSame($this->scoped($db, static::q('id') . ' in (?, ?, ?)'), $q[0]['query']);
        $this->assertSame($ids, array_map('intval', array_keys($byId)));

        // the same JSON path for an indexed column once index_enabled is off
        $this->unindex($table, 'index_text');
        [$json, $log] = $this->logged(function () use ($table, $labels) {
            return $table->getMatchedCustomValues($labels, 'value.index_text');
        });
        $this->assertSame($this->scoped($db, "{$this->jsonKey('index_text')} in (?, ?, ?)"), $this->one($log, ' in (?, ?, ?)')['query']);
        $this->assertSame(array_keys($byLabel), array_keys($json));
    }

    public function testMatchedCustomValuesWithTrashedAndUnknownColumn(): void
    {
        $table = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $db = getDBTableName($table);
        $id = intval(\DB::table($db)->whereNull('deleted_at')->orderBy('id')->value('id'));
        [$text] = $this->uniqueTexts($table, [$id], 'trashed');
        \DB::table($db)->where('id', $id)->update(['deleted_at' => \Carbon\Carbon::now()]);

        $this->assertSame([], $table->getMatchedCustomValues([$text], 'value.text'));
        $trashed = $table->getMatchedCustomValues([$text], 'value.text', true);
        $this->assertSame([$text], array_keys($trashed));
        $this->assertSame($id, intval($trashed[$text]->id));

        // existing behaviour, pinned: an unknown column fails on the missing metadata
        try {
            $table->getMatchedCustomValues(['x'], 'value.p11_not_a_column');
            $this->fail('expected the existing error for an unknown column');
        } catch (\Throwable $ex) {
            $this->assertInstanceOf(\ErrorException::class, $ex);
            $this->assertStringContainsString('index_enabled', $ex->getMessage());
        }
    }

    // ---------------------------------------------- R4-c checkReferenced

    public function testDestroyValidationFindsSingleValueReferencesThroughTheJsonPath(): void
    {
        $parent = $this->table(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
        $child = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
        $referenced = intval($this->value($child, intval(\DB::table(getDBTableName($child))->whereNull('deleted_at')->whereNotNull('value')->orderBy('id')->value('id')), 'parent_select_table'));
        $this->assertGreaterThan(0, $referenced, 'precondition: a referenced parent row');
        $free = $this->insertRow($parent, ['text' => 'p11-free']);

        [$result, $log] = $this->logged(function () use ($parent, $referenced) {
            return $parent->validateValueDestroy($referenced);
        });
        $this->assertSame(false, $result['status']);
        $q = $this->matching($log, $this->jsonKey('parent_select_table'));
        $this->assertNotEmpty($q);
        $cdb = getDBTableName($child);
        $inner = $this->scoped($cdb, $this->jsonKey('parent_select_table') . ' in (?)', '1 ' . static::q('exists'), true);
        $this->assertSame(static::sqlServer() ? $inner : 'select exists(' . $this->scoped($cdb, $this->jsonKey('parent_select_table') . ' in (?)') . ') as ' . static::q('exists'), $q[0]['query'], 'explicit JSON path although the column is indexed');
        $this->assertSame([strval($referenced)], array_map('strval', $q[0]['bindings']));

        $this->assertNull($parent->validateValueDestroy($free), 'an unreferenced row may be deleted');
    }

    /**
     * A reference held only in a multi-value select_table list blocks the delete (each element is matched,
     * not the list text); a row that no list or single value references can still be deleted.
     */
    public function testDestroyValidationFindsMultiValueReferences(): void
    {
        $target = $this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $source = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $rows = \DB::table(getDBTableName($source))->whereNull('deleted_at')->orderBy('id')->limit(2)->pluck('id')->map(function ($v) { return intval($v); })->all();

        $inList = $this->insertRow($target, ['text' => 'p11-in-list']);
        $other = $this->insertRow($target, ['text' => 'p11-other']);
        $this->setValueKey($source, $rows[0], 'select_table_multiple', [strval($other), strval($inList)]);
        $single = $this->insertRow($target, ['text' => 'p11-single']);
        $this->setValueKey($source, $rows[1], 'select_table', strval($single));
        $free = $this->insertRow($target, ['text' => 'p11-free']);

        $this->assertSame(false, $target->validateValueDestroy($single)['status'], 'single-value reference is found');
        $this->assertSame(false, $target->validateValueDestroy($inList)['status'], 'a reference only in a multi-value list is found');
        $this->assertSame(false, $target->validateValueDestroy($other)['status'], 'every element of the list is found');
        $this->assertNull($target->validateValueDestroy($free), 'an unreferenced row may be deleted');
        // a list element must match whole: an id that is only a prefix of a stored id is not a reference
        $this->assertNull($target->validateValueDestroy(intval($inList . '9')), 'a longer id is not matched by prefix');
    }

    // --------------------------------------------- R4-d getKeyAndIdList

    public function testKeyAndIdListIndexedAndJsonTargetKeys(): void
    {
        $source = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $target = $this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $tdb = getDBTableName($target);
        $ids = \DB::table($tdb)->whereNull('deleted_at')->orderBy('id')->limit(2)->pluck('id')->map(function ($v) { return intval($v); })->all();
        $item = CustomItem::getItem(CustomColumn::getEloquent('select_table', $source));
        $this->uniqueTexts($target, $ids, 'key');

        foreach (['index_text' => $this->idx($target, 'index_text'), 'text' => $this->jsonKey('text')] as $key => $expr) {
            $labels = array_map(function ($id) use ($target, $key) {
                return strval($this->value($target, $id, $key));
            }, $ids);
            System::clearRequestSession();
            [$list, $log] = $this->logged(function () use ($item, $labels, $key) {
                return $item->getKeyAndIdList(array_map(function ($l) {
                    return ['value' => ['select_table' => $l]];
                }, $labels), $key);
            });
            $q = [$this->one($log, ' in (?, ?)')];
            $this->assertSame($this->scoped($tdb, "$expr in (?, ?)", static::q('value') . ', ' . static::q('id')), $q[0]['query'], $key);
            $this->assertSame($labels, $q[0]['bindings']);
            $this->assertSame(array_combine($labels, $ids), array_map('intval', collect($list)->all()), $key);
        }

        // a key that is no column of the target: nothing is read
        System::clearRequestSession();
        [$none, $log] = $this->logged(function () use ($item) {
            return $item->getKeyAndIdList([['value' => ['select_table' => 'x']]], 'p11_not_a_column');
        });
        $this->assertSame([], $none);
        $this->assertCount(0, $this->matching($log, 'from ' . static::q($tdb)));
    }

    public function testKeyAndIdListKeepsTheScopeOfARestrictedUser(): void
    {
        $source = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $target = $this->table(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        [$login, $visible] = $this->restrictedLogin($target);
        $hidden = intval(\DB::table(getDBTableName($target))->whereNull('deleted_at')->whereNotIn('id', $visible)->orderBy('id')->value('id'));
        $shown = $visible[0];
        $item = CustomItem::getItem(CustomColumn::getEloquent('select_table_2', $source));
        $this->uniqueTexts($target, [$hidden, $shown], 'scope');

        foreach (['index_text', 'text'] as $key) {
            System::clearRequestSession();
            $labels = [strval($this->value($target, $hidden, $key)), strval($this->value($target, $shown, $key))];
            $list = collect($item->getKeyAndIdList(array_map(function ($l) {
                return ['value' => ['select_table_2' => $l]];
            }, $labels), $key))->all();
            $this->assertArrayNotHasKey($labels[0], $list, "$key: hidden row is not mapped");
            $this->assertSame($shown, intval($list[$labels[1]] ?? 0), "$key: visible row is mapped");
        }
    }

    // ------------------------------------------ R4-e SyncMeiliReferencesJob

    protected function referencing(CustomTable $table, array $columns, int $valueId)
    {
        $method = new \ReflectionMethod(SyncMeiliReferencesJob::class, 'referencingQuery');
        $method->setAccessible(true);
        return $method->invoke(null, $table, $columns, $valueId);
    }

    public function testMeiliReferencingQueryKeysAndNoScope(): void
    {
        $source = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $target = $this->table(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $sdb = getDBTableName($source);
        $rows = \DB::table($sdb)->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id')->map(function ($v) { return intval($v); })->all();
        $valueId = $this->insertRow($target, ['text' => 'p11-meili']);
        $this->setValueKey($source, $rows[0], 'select_table_multiple', [strval($valueId)]);
        $this->setValueKey($source, $rows[1], 'select_table', strval($valueId));
        $this->setValueKey($source, $rows[2], 'select_table_multiple', [$valueId]);

        $multi = CustomColumn::getEloquent('select_table_multiple', $source);
        $single = CustomColumn::getEloquent('select_table', $source);

        // a restricted login: the job query has no scope
        $this->restrictedLogin($source);
        $query = $this->referencing($source, [$multi, $single], $valueId);
        $multiSql = $this->sqlServer()
            ? "? in (select [value] from openjson([value], '$.\"select_table_multiple\"'))"
            : "json_contains(`value`, ?, '$.\"select_table_multiple\"')";
        $this->assertSame(
            'select * from ' . $this->q($sdb) . " where ($multiSql or $multiSql or {$this->idx($source, 'select_table')} = ?) and " . $this->q($sdb) . '.' . $this->q('deleted_at') . ' is null',
            $query->toSql()
        );
        $this->assertSame(
            $this->sqlServer() ? [strval($valueId), strval($valueId), strval($valueId)] : ['"' . $valueId . '"', strval($valueId), strval($valueId)],
            array_map('strval', $query->getBindings())
        );
        $this->assertSame($rows, $query->pluck('id')->map(function ($v) { return intval($v); })->sort()->values()->all());

        $this->unindex($source, 'select_table');
        $single = CustomColumn::getEloquent('select_table', $source);
        $query = $this->referencing($source, [$single], $valueId);
        $this->assertSame($this->scoped($sdb, "({$this->jsonKey('select_table')} = ?)"), $query->toSql());
        $this->assertSame([$rows[1]], $query->pluck('id')->map(function ($v) { return intval($v); })->all());
    }

    public function testMeiliReferencingQueryFallsBackToJsonWithoutDdl(): void
    {
        $source = $this->table(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $sdb = getDBTableName($source);
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->custom_table_id = $source->id;
        $column->column_name = 'p11_link';
        $column->column_type = ColumnType::SELECT_TABLE;
        $column->suuid = 'p11missing0000000000';
        $column->options = ['index_enabled' => '1'];

        $query = $this->referencing($source, [$column], 5);
        $this->assertSame($this->scoped($sdb, "({$this->jsonKey('p11_link')} = ?)"), $query->toSql());
        $this->assertSame(0, $column->alterCount, 'the job never creates the index column');
    }
}
