<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Dashboard\FilterValue;

/**
 * FIXED: on MariaDB and SQL Server a multiple-value column (select / select_valtext /
 * select_table) stores its list as json_encode writes it — every non-ASCII character as
 * \uXXXX and "/" as "\/". The dashboard filter bar and the chart filters searched the list
 * for the value escaped by unicode_encode(), which escapes 3-byte characters only and leaves
 * "/" alone: "東京" matched, but "café" or "東京/大阪" never did — the option was listed,
 * picking it emptied the chart. The value is now searched for as json_encode writes it.
 *
 * On MySQL the list is stored unescaped; the test passes there before and after the fix.
 * The rows are saved into the test data's existing table (no DDL) and rolled back.
 */
class DashboardMultipleFilterEscapeFixedTest extends SecurityRegressionTestCase
{
    public function test_a_multiple_column_value_with_a_slash_or_an_accent_is_found(): void
    {
        $login = LoginUser::find(1);
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1');
        }
        $this->actingAs($login, 'admin');
        System::clearRequestSession();

        $table = CustomTable::getEloquent('all_columns_table_fortest');
        $column = $table ? CustomColumn::getEloquent('select_multiple', $table) : null;
        if ($column === null) {
            $this->markTestSkipped('needs all_columns_table_fortest.select_multiple (exment:inittest)');
        }
        $this->assertTrue(FilterValue::isMultiple($column));

        $ids = [
            'slash' => $this->saveRow($table, ['東京/大阪', 'foo']),
            'accent' => $this->saveRow($table, ['café']),
            'kanji' => $this->saveRow($table, ['東京']),
        ];

        $this->assertSame([$ids['slash']], $this->idsMatching($table, $column, $ids, ['東京/大阪']), 'a value with "/"');
        $this->assertSame([$ids['accent']], $this->idsMatching($table, $column, $ids, ['café']), 'a value with a 2-byte character');
        $this->assertSame([$ids['kanji']], $this->idsMatching($table, $column, $ids, ['東京']), 'a 3-byte value, as before');
        $this->assertSame([$ids['slash']], $this->idsMatching($table, $column, $ids, ['foo']), 'an ASCII value, as before');
        $this->assertSame([$ids['accent'], $ids['kanji']], $this->idsMatching($table, $column, $ids, ['café', '東京']), 'several values: any of them');
        $this->assertSame([], $this->idsMatching($table, $column, $ids, ['大阪']), 'a part of a value is no match');
    }

    /**
     * @param list<string> $values
     */
    private function saveRow(CustomTable $table, array $values): int
    {
        $row = $table->getValueModel();
        $row->setValue('select_multiple', $values);
        $row->saved_notify(false);
        $row->save();
        return (int) $row->id;
    }

    /**
     * @param array<string, int> $ids  the rows this test saved
     * @param list<string> $values
     * @return array<int, int>
     */
    private function idsMatching(CustomTable $table, CustomColumn $column, array $ids, array $values): array
    {
        $query = $table->getValueQuery()->whereIn(getDBTableName($table) . '.id', array_values($ids));
        FilterValue::apply($query, $column, ['in' => $values]);
        return $query->orderBy(getDBTableName($table) . '.id')->pluck(getDBTableName($table) . '.id')->map(function ($id) {
            return (int) $id;
        })->values()->all();
    }
}
