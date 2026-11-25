<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\ColumnQueryKey;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Hybrid storage P1 slice 13: the read-access contract ColumnQueryKey itself.
 *
 * Each policy builds the same string the callers built before, carries its context (policy,
 * key, column, qualifier, owning table) and runs index-column DDL exactly where the legacy call
 * did: index_preferring and index may run it, index_if_present and json never do. Creating a key
 * looks no metadata up.
 */
class ColumnQueryKeyTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        System::clearRequestSession();
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    protected function counting(bool $indexed, string $name = 'p13_col'): CustomColumn
    {
        $column = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $column->custom_table_id = $this->table()->id;
        $column->column_name = $name;
        $column->column_type = ColumnType::TEXT;
        $column->suuid = 'p13missing000000000';
        $column->options = $indexed ? ['index_enabled' => '1'] : [];
        return $column;
    }

    public function testExpressionsEqualTheLegacyStrings(): void
    {
        $text = CustomColumn::getEloquent('text', $this->table());
        $textarea = CustomColumn::getEloquent('textarea', $this->table());

        $this->assertSame($text->getQueryKey(), ColumnQueryKey::indexPreferring($text, CustomColumn::QUERY_PURPOSE_LOOKUP)->expression());
        $this->assertSame($textarea->getQueryKey(), ColumnQueryKey::indexPreferring($textarea, CustomColumn::QUERY_PURPOSE_LOOKUP)->expression());
        $this->assertSame($text->getIndexColumnName(), ColumnQueryKey::index($text)->expression());
        $this->assertSame('exm__t.' . $text->getIndexColumnName(), ColumnQueryKey::index($text, 'exm__t')->expression());
        $this->assertSame('value->text', ColumnQueryKey::json($text)->expression());
        $this->assertSame('value->any_key', ColumnQueryKey::json('any_key', $this->table())->expression());
        $this->assertSame('alias.value->text', (string)ColumnQueryKey::json($text, null, 'alias'));

        $db = getDBTableName($this->table());
        $this->assertSame($text->getIndexColumnName(false), ColumnQueryKey::indexIfPresent($text, $db)->expression());
        $this->assertSame('value->textarea', ColumnQueryKey::indexIfPresent($textarea, $db)->expression());
    }

    public function testContext(): void
    {
        $text = CustomColumn::getEloquent('text', $this->table());
        $key = ColumnQueryKey::index($text, 'exm__t');
        $this->assertSame(ColumnQueryKey::POLICY_INDEX, $key->policy());
        $this->assertSame('text', $key->key());
        $this->assertSame($text, $key->column());
        $this->assertSame('exm__t', $key->qualifier());
        $this->assertSame($this->table()->id, $key->table()->id, 'owning table from the column, on demand');

        $setting = ColumnQueryKey::json('from_setting', $this->table());
        $this->assertSame(ColumnQueryKey::POLICY_JSON, $setting->policy());
        $this->assertNull($setting->column());
        $this->assertSame($this->table(), $setting->table());
        $this->assertNull(ColumnQueryKey::json('no_table')->table());

        $this->assertSame(ColumnQueryKey::POLICY_INDEX_PREFERRING, ColumnQueryKey::indexPreferring($text, CustomColumn::QUERY_PURPOSE_GENERAL)->policy());
        $this->assertSame(ColumnQueryKey::POLICY_INDEX_IF_PRESENT, ColumnQueryKey::indexIfPresent($text, getDBTableName($this->table()))->policy());
    }

    public function testDdlOnlyWhereTheLegacyCallRanIt(): void
    {
        $db = getDBTableName($this->table());

        $preferring = $this->counting(true);
        $this->assertSame('column_p13missing000000000', ColumnQueryKey::indexPreferring($preferring, CustomColumn::QUERY_PURPOSE_GENERAL)->expression());
        $this->assertSame(1, $preferring->alterCount);

        $index = $this->counting(true);
        ColumnQueryKey::index($index);
        $this->assertSame(1, $index->alterCount);

        $present = $this->counting(true);
        $this->assertSame('value->p13_col', ColumnQueryKey::indexIfPresent($present, $db)->expression(), 'missing column: JSON, no DDL');
        $this->assertSame(0, $present->alterCount);

        $json = $this->counting(true);
        ColumnQueryKey::json($json);
        $this->assertSame(0, $json->alterCount);

        $unindexed = $this->counting(false);
        $this->assertSame('value->p13_col', ColumnQueryKey::indexPreferring($unindexed, CustomColumn::QUERY_PURPOSE_GENERAL)->expression());
        $this->assertSame(0, $unindexed->alterCount);
    }

    public function testIndexWithoutDdl(): void
    {
        $text = CustomColumn::getEloquent('text', $this->table());
        $key = ColumnQueryKey::indexWithoutDdl($text, 'exm__t');
        $this->assertSame('exm__t.' . $text->getIndexColumnName(false), $key->expression());
        $this->assertSame(ColumnQueryKey::POLICY_INDEX_WITHOUT_DDL, $key->policy());

        // the name whatever index_enabled says or whether the column exists, never DDL
        $missing = $this->counting(true);
        $this->assertSame('column_p13missing000000000', ColumnQueryKey::indexWithoutDdl($missing)->expression());
        $this->assertSame(0, $missing->alterCount);
        $unindexed = $this->counting(false);
        $this->assertSame('column_p13missing000000000', ColumnQueryKey::indexWithoutDdl($unindexed)->expression());
        $this->assertSame(0, $unindexed->alterCount);
    }

    public function testAMissingColumnFailsAsTheLegacyCallDid(): void
    {
        $column = null;
        try {
            // @phpstan-ignore-next-line
            $column->getIndexColumnName();
            $this->fail('legacy call did not fail');
        } catch (\Error $legacy) {
        }
        try {
            ColumnQueryKey::index($column);
            $this->fail('contract did not fail');
        } catch (\Error $now) {
        }
        $this->assertSame(get_class($legacy), get_class($now));
        $this->assertSame($legacy->getMessage(), $now->getMessage());
    }

    public function testCreatingAKeyLooksNoMetadataUp(): void
    {
        $text = CustomColumn::getEloquent('text', $this->table());
        System::clearRequestSession();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        ColumnQueryKey::json('some_key');
        ColumnQueryKey::json($text);
        $log = \DB::getQueryLog();
        \DB::disableQueryLog();
        $this->assertSame([], $log);
    }
}
