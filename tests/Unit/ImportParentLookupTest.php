<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\ParentItem;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\ImportParentLookup;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * Step 3: ImportParentLookup must answer exactly like ParentItem::getImportValue()'s per-row query, or answer null (original query).
 */
class ImportParentLookupTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const PREFIX = 's3-';

    /** @var array<int, int> */
    protected $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        DB::listen(function () {
            $this->queries++;
        });
    }

    protected function parentTable(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
    }

    protected function createParent(CustomTable $table, array $values): void
    {
        $model = $table->getValueModel();
        foreach ($values as $k => $v) {
            $model->setValue($k, $v);
        }
        $model->save();
    }

    /**
     * @return array<int, mixed> inputs that exercise comparison edge cases
     */
    protected function seedAndInputs(): array
    {
        $table = $this->parentTable();
        $p = static::PREFIX;

        foreach ([
            ["{$p}plain"], ["{$p}dup"], ["{$p}dup"], ["{$p}Case"], ["{$p}pad "], ["{$p}あい"], ["{$p}é"],
            ["{$p}quote'\"%_\\"], ['9300123'], [null], ["{$p}a b"], ["{$p}only-in-integer"],
        ] as [$text]) {
            $this->createParent($table, ['text' => $text]);
        }
        $this->createParent($table, ['text' => "{$p}with-int", 'integer' => 5300]);

        return [
            "{$p}plain", "{$p}missing", "{$p}dup", "{$p}Case", "{$p}case", "{$p}CASE", "{$p}pad", "{$p}pad ", "{$p}アイ", "{$p}あい", "{$p}e", "{$p}é",
            "{$p}quote'\"%_\\", "{$p}quote", '9300123', '09300123', '9300123.0', '9.300123e6', '', ' ', "{$p}a b", "{$p}a  b", "{$p}A B",
            'null', 'NULL', "{$p}plain\0", "{$p}%", "{$p}_lain",
        ];
    }

    protected function original(ParentItem $item, string $column, $value): array
    {
        return $item->getImportValue($value, ['target_column_name' => $column]);
    }

    protected function viaLookup(ParentItem $item, ImportParentLookup $lookup, string $column, $value): array
    {
        return $item->getImportValue($value, ['target_column_name' => $column, 'parent_lookup' => $lookup]);
    }

    protected function rowsFor(array $inputs): array
    {
        $rows = [];
        foreach ($inputs as $input) {
            $rows[] = ['parent_id' => $input, 'parent_type' => TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE];
        }
        return $rows;
    }

    public function testStringInputsAnswerLikeTheOriginalQuery()
    {
        $inputs = $this->seedAndInputs();
        $table = $this->parentTable();
        $item = ParentItem::getItem($table);

        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2] as $userId) {
            $this->be(LoginUser::find($userId));
            $lookup = new ImportParentLookup($this->rowsFor($inputs));
            foreach (['text', 'integer'] as $column) {
                foreach ($inputs as $input) {
                    $this->assertSame(
                        $this->original($item, $column, $input),
                        $this->viaLookup($item, $lookup, $column, $input),
                        "user $userId, column $column, input " . json_encode($input, JSON_UNESCAPED_UNICODE)
                    );
                }
            }
            $this->assertGreaterThan(0, $lookup->batchCount);
        }
    }

    public function testResultsAreDecidedHereOnlyWhenUnambiguous()
    {
        $inputs = $this->seedAndInputs();
        $table = $this->parentTable();
        $lookup = new ImportParentLookup($this->rowsFor($inputs));
        $p = static::PREFIX;

        $this->assertSame(['found' => true, 'id' => $this->original(ParentItem::getItem($table), 'text', "{$p}plain")['value']], $lookup->resolve($table, 'text', "{$p}plain"));
        $this->assertSame(['found' => false], $lookup->resolve($table, 'text', "{$p}missing"));
        // two parents share this text: the original query returns an arbitrary one, so the lookup must not decide
        $this->assertNull($lookup->resolve($table, 'text', "{$p}dup"));
        // not a string: the original binds it differently
        $this->assertNull($lookup->resolve($table, 'text', 5300));
        $this->assertNull($lookup->resolve($table, 'text', 1.5));
        $this->assertNull($lookup->resolve($table, 'text', true));
        $this->assertNull($lookup->resolve($table, 'text', null));
    }

    public function testBatchesAreBoundedAndQueriesDropFromPerRowToPerBatch()
    {
        $table = $this->parentTable();
        $p = static::PREFIX;
        $this->createParent($table, ['text' => "{$p}bulk-0"]);
        $this->createParent($table, ['text' => "{$p}bulk-777"]);

        $inputs = [];
        for ($i = 0; $i < ImportParentLookup::BATCH_SIZE * 2 + 203; $i++) {
            $inputs[] = "{$p}bulk-$i";
        }
        // repeated values must not add queries
        $rows = $this->rowsFor(array_merge($inputs, $inputs));
        $item = ParentItem::getItem($table);

        $expected = [];
        foreach ($inputs as $input) {
            $expected[$input] = $this->original($item, 'text', $input);
        }

        $lookup = new ImportParentLookup($rows);
        $this->queries = 0;
        foreach ($rows as $row) {
            $this->assertSame($expected[$row['parent_id']], $this->viaLookup($item, $lookup, 'text', $row['parent_id']));
        }
        $this->assertSame(3, $lookup->batchCount);
        $this->assertLessThanOrEqual(8, $this->queries, 'queries of the lookup: batches + metadata');
        $this->assertTrue($expected["{$p}bulk-0"]['result']);
        $this->assertTrue($expected["{$p}bulk-777"]['result']);
        $this->assertFalse($expected["{$p}bulk-1"]['result']);
    }

    public function testParentTypesAreResolvedSeparately()
    {
        $parent = $this->parentTable();
        $other = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $p = static::PREFIX;
        $this->createParent($parent, ['text' => "{$p}both"]);
        $this->createParent($other, ['text' => "{$p}both"]);
        $this->createParent($other, ['text' => "{$p}only-other"]);

        $rows = [
            ['parent_id' => "{$p}both", 'parent_type' => $parent->table_name],
            ['parent_id' => "{$p}both", 'parent_type' => $other->table_name],
            ['parent_id' => "{$p}only-other", 'parent_type' => $parent->table_name],
            ['parent_id' => "{$p}only-other", 'parent_type' => $other->table_name],
            ['parent_id' => "{$p}both", 'parent_type' => null],
        ];
        $lookup = new ImportParentLookup($rows);
        foreach ([$parent, $other] as $table) {
            $item = ParentItem::getItem($table);
            foreach ($rows as $row) {
                $this->assertSame(
                    $this->original($item, 'text', $row['parent_id']),
                    $this->viaLookup($item, $lookup, 'text', $row['parent_id'])
                );
            }
        }
        $this->assertSame($lookup->parentItem($parent), $lookup->parentItem($parent));
        $this->assertNotSame($lookup->parentItem($parent), $lookup->parentItem($other));
    }

    public function testProcessCustomValueGivesTheSameDataAndErrorsInTheSameOrder()
    {
        $inputs = $this->seedAndInputs();
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $setting = [['column_name' => 'parent_id', 'target_column_name' => 'text']];
        $type = TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE;

        $rows = [];
        foreach ($inputs as $i => $input) {
            $rows[$i + 2] = ['parent_id' => $input, 'parent_type' => $type, 'value.text' => "child-$i"];
        }
        // a row without parent_type and a numeric parent_id take the unchanged paths
        $rows[] = ['parent_id' => 5300, 'parent_type' => $type, 'value.text' => 'numeric'];

        $run = function (bool $withLookup) use ($rows, $child, $setting) {
            $errors = [];
            $out = [];
            $lookup = $withLookup ? new ImportParentLookup($rows) : null;
            foreach ($rows as $line => $row) {
                $options = ['setting' => $setting, 'datalist' => $rows, 'errorCallback' => function ($message, $key) use (&$errors, $line) {
                    $errors[] = [$line, $key, $message];
                }];
                if ($lookup) {
                    $options['parentLookup'] = $lookup;
                }
                $out[$line] = DataImportExportService::processCustomValue($child->custom_columns, $row, $options);
            }
            return [$out, $errors];
        };

        $this->assertSame($run(false), $run(true));
        [, $errors] = $run(true);
        $this->assertNotEmpty($errors);
    }

    /**
     * @param array<int, mixed> $inputs
     * @param class-string<ImportParentLookup> $class
     * @return array<int, array> original answers
     */
    protected function assertLookupEqualsOriginal(string $class, array $inputs, ?int $expectedBatches = null): ImportParentLookup
    {
        $table = $this->parentTable();
        $item = ParentItem::getItem($table);
        $lookup = new $class($this->rowsFor($inputs));
        foreach ($inputs as $input) {
            $this->assertSame(
                $this->original($item, 'text', $input),
                $this->viaLookup($item, $lookup, 'text', $input),
                'input ' . json_encode($input, JSON_UNESCAPED_UNICODE)
            );
        }
        if ($expectedBatches !== null) {
            $this->assertSame($expectedBatches, $lookup->batchCount);
        }
        return $lookup;
    }

    public function testBatchBoundariesWithASmallBatchSize()
    {
        $table = $this->parentTable();
        $p = static::PREFIX;
        // 7 distinct inputs, batch size 3 => positions 0..2 / 3..5 / 6; found values sit on both sides of every boundary
        foreach ([0, 2, 3, 5, 6] as $i) {
            $this->createParent($table, ['text' => "{$p}edge-$i"]);
        }
        $inputs = [];
        for ($i = 0; $i < 7; $i++) {
            $inputs[] = "{$p}edge-$i";
        }

        $this->assertLookupEqualsOriginal(ImportParentLookupSmallBatch::class, $inputs, 3);
        // each input asked first: the batch always starts at the asked value, the following unresolved ones fill it up
        foreach (array_reverse($inputs) as $first) {
            $lookup = new ImportParentLookupSmallBatch($this->rowsFor($inputs));
            $item = ParentItem::getItem($table);
            $this->assertSame($this->original($item, 'text', $first), $this->viaLookup($item, $lookup, 'text', $first));
        }
    }

    public function testDuplicateParentsAcrossBatchesAreNeverDecidedAndRepeatsAreNotRequeried()
    {
        $table = $this->parentTable();
        $p = static::PREFIX;
        $this->createParent($table, ['text' => "{$p}d-1"]);
        $this->createParent($table, ['text' => "{$p}d-1"]);
        $this->createParent($table, ['text' => "{$p}d-5"]);
        $this->createParent($table, ['text' => "{$p}d-5"]);
        $this->createParent($table, ['text' => "{$p}d-4"]);

        $inputs = [];
        for ($i = 0; $i < 7; $i++) {
            $inputs[] = "{$p}d-$i";
        }
        // d-1 is ambiguous inside the first batch, d-5 inside the second; d-4 is unique in the second
        $rows = $this->rowsFor(array_merge($inputs, $inputs, $inputs));
        $lookup = new ImportParentLookupSmallBatch($rows);
        $this->assertNull($lookup->resolve($table, 'text', "{$p}d-1"));
        $this->assertNull($lookup->resolve($table, 'text', "{$p}d-5"));
        $this->assertTrue($lookup->resolve($table, 'text', "{$p}d-4")['found']);
        $this->assertSame(['found' => false], $lookup->resolve($table, 'text', "{$p}d-0"));
        // batches start at the asked value: [d-1..d-3], [d-5,d-6], [d-4], [d-0]
        $this->assertSame(4, $lookup->batchCount);

        $before = $lookup->batchCount;
        foreach ($rows as $row) {
            $lookup->resolve($table, 'text', $row['parent_id']);
        }
        $this->assertSame($before, $lookup->batchCount, 'resolved and undecided values are remembered');

        $this->assertLookupEqualsOriginal(ImportParentLookupSmallBatch::class, $inputs);
    }

    public function testExceedingTheCacheLimitRequeriesInsteadOfTreatingValuesAsMissingOrUnique()
    {
        $table = $this->parentTable();
        $p = static::PREFIX;
        foreach ([0, 1, 4, 9] as $i) {
            $this->createParent($table, ['text' => "{$p}m-$i"]);
        }
        $this->createParent($table, ['text' => "{$p}m-4"]);

        $inputs = [];
        for ($i = 0; $i < 12; $i++) {
            $inputs[] = "{$p}m-$i";
        }
        // limit 5 < 12 distinct values: the cache is cleared several times while the rows are walked in order and in reverse
        $rows = array_merge($inputs, array_reverse($inputs), $inputs);
        $lookup = $this->assertLookupEqualsOriginal(ImportParentLookupSmallLimit::class, $rows);
        $this->assertGreaterThan(3, $lookup->batchCount, 'evicted values are queried again');
        $this->assertLessThanOrEqual(count($rows), $lookup->batchCount);

        // an evicted value never turns into a "found = false" (missing) or a decided answer
        $lookup = new ImportParentLookupSmallLimit($this->rowsFor($inputs));
        $this->assertTrue($lookup->resolve($table, 'text', "{$p}m-0")['found']);
        $lookup->resolve($table, 'text', "{$p}m-11");
        $again = $lookup->resolve($table, 'text', "{$p}m-0");
        $this->assertTrue($again['found']);
        $this->assertNull($lookup->resolve($table, 'text', "{$p}m-4"), 'ambiguous stays undecided after eviction');
    }

    public function testUnsupportedDriverAlwaysFallsBackToTheOriginalQuery()
    {
        $table = $this->parentTable();
        $p = static::PREFIX;
        $this->createParent($table, ['text' => "{$p}drv"]);
        $inputs = ["{$p}drv", "{$p}none"];

        $lookup = $this->assertLookupEqualsOriginal(ImportParentLookupUnsupported::class, $inputs, 0);
        $this->assertNull($lookup->resolve($table, 'text', "{$p}drv"));
        $this->assertNull($lookup->resolve($table, 'text', "{$p}none"));
    }

    public function testMissingParentTableStillFailsLikeTheOriginal()
    {
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $setting = [['column_name' => 'parent_id', 'target_column_name' => 'text']];
        $row = ['parent_id' => 'x', 'parent_type' => 'no_such_table_s3'];

        $messages = [];
        foreach ([false, true] as $withLookup) {
            $options = ['setting' => $setting];
            if ($withLookup) {
                $options['parentLookup'] = new ImportParentLookup([$row]);
            }
            try {
                DataImportExportService::processCustomValue($child->custom_columns, $row, $options);
                $messages[] = 'no exception';
            } catch (\Throwable $e) {
                $messages[] = get_class($e);
            }
        }
        $this->assertSame($messages[0], $messages[1]);
    }
}

class ImportParentLookupSmallBatch extends ImportParentLookup
{
    public const BATCH_SIZE = 3;
}

class ImportParentLookupSmallLimit extends ImportParentLookup
{
    public const BATCH_SIZE = 3;

    public const MAX_CACHED = 5;
}

class ImportParentLookupUnsupported extends ImportParentLookup
{
    protected static function isSupported(CustomTable $parentTable): bool
    {
        return false;
    }
}
