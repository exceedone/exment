<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\DataImportExport\Providers\Import\ImportUniqueCheckIndex;
use PHPUnit\Framework\TestCase;

/**
 * ImportUniqueCheckIndex must report exactly what the legacy in-file sibling scan
 * (CustomTable::scanUniqueSiblings: loose "!=" comparison, both-null skipped, column keys accumulated across siblings) reports.
 * DB-free: the legacy scan is re-implemented here from names.
 */
class ImportUniqueCheckIndexTest extends TestCase
{
    /**
     * Legacy scan, written on resolved value names.
     *
     * @param iterable<mixed> $siblings
     * @param array<int, string> $names
     * @return array{0: bool, 1: array<int, string>}
     */
    protected function legacy($siblings, array $input, array $names): array
    {
        $column_keys = [];
        $is_duplicate = collect($siblings)->contains(function ($row) use ($input, $names, &$column_keys) {
            foreach ($names as $name) {
                $value = array_get($input, $name);
                $other = array_get($row, $name);
                if (is_null($value) && is_null($other)) {
                    continue;
                }
                if ($value != $other) {
                    return false;
                }
                $column_keys[] = $name;
            }
            return !empty($column_keys);
        });
        return [$is_duplicate, $column_keys];
    }

    /**
     * Same as CustomTable: column keys are only used (and compared) when duplicated.
     *
     * @param array<int|string, mixed> $dataObjects
     * @param array<int, string> $names
     * @param int|string $currentKey
     * @return array{0: array{0: bool, 1: array<int, string>}, 1: array{0: bool, 1: array<int, string>}, 2: int}
     */
    protected function compare(array $dataObjects, array $names, $currentKey, ?array $input = null): array
    {
        $input = $input ?? $dataObjects[$currentKey]['data'];
        $siblings = [];
        foreach ($dataObjects as $key => $row) {
            if ($key != $currentKey) {
                $siblings[] = $row['data'];
            }
        }
        $expected = $this->legacy($siblings, $input, $names);

        $scans = 0;
        $index = new ImportUniqueCheckIndex($dataObjects);
        $actual = $index->findDuplicate($names, $input, $currentKey, function ($s) use (&$scans, $input, $names) {
            $scans++;
            return $this->legacy($s, $input, $names);
        });

        if (!$expected[0]) {
            $expected[1] = [];
            $actual[1] = [];
        }
        return [$expected, $actual, $scans];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function objects(array $rows): array
    {
        $result = [];
        foreach ($rows as $i => $values) {
            $result[$i + 2] = ['data' => ['value' => $values], 'model' => null];
        }
        return $result;
    }

    public function testCanonicalizeKeysEqualLooseComparison(): void
    {
        $pool = [
            null, '', 0, '0', 0.0, '0.0', '00', '-0', false, true, 1, '1', '01', '1.0', 1.0, '1e0', ' 1', '1 ', "1\n", '+1', -1, '-1', '-1.0',
            1.5, '1.5', '1.50', '1.500', 1.25, '0.1', 0.1, '.5', '5.', 5, '5', 5.0, 'abc', 'ABC', 'abc ', ' abc', 'a', 'あ', 'ａ',
            '2026-01-01', '2026-1-1', '20260101', 20260101, '0x1A', '1_000', 'INF', 'NAN', INF, NAN,
            '123456789012345', '123456789012345.0', '1234567890123456', '12345678901234567', '12345678901234568', '123456789012345678', '1234567890123456789',
            '9007199254740992', '9007199254740993', '9007199254740992.0', '9007199254740993.0', PHP_INT_MAX, (string)PHP_INT_MAX,
            0.30000000000000004, 1.0E+25, '1e3', '1000', 1000, [], [1], ['a'], new \stdClass(),
        ];

        $safe = 0;
        foreach ($pool as $a) {
            foreach ($pool as $b) {
                $ca = ImportUniqueCheckIndex::canonicalize($a);
                $cb = ImportUniqueCheckIndex::canonicalize($b);
                if ($ca === false || $cb === false) {
                    continue;
                }
                $safe++;
                $this->assertSame(
                    $a == $b,
                    $ca === $cb,
                    'canonical keys must follow loose comparison: ' . var_export($a, true) . ' vs ' . var_export($b, true)
                );
            }
        }
        $this->assertGreaterThan(500, $safe, 'the pool must keep plenty of indexable pairs');

        // values that must never be hashed
        foreach (['', 0, 0.0, '1e0', ' 1', '1 ', true, false, [], [1], INF, NAN, 1.0E+25, '1234567890123456789', '123456789012345.5123'] as $unsafe) {
            $this->assertFalse(ImportUniqueCheckIndex::canonicalize($unsafe), var_export($unsafe, true));
        }
        // values that must be hashed (typical imports)
        foreach ([null, 'abc', '2026-01-01', 'ORD000001', '20260101', 12, '12', '012', '1.5', 1.5, '-3', 'あ'] as $safeValue) {
            $this->assertNotFalse(ImportUniqueCheckIndex::canonicalize($safeValue), var_export($safeValue, true));
        }
        $this->assertSame(ImportUniqueCheckIndex::canonicalize('007'), ImportUniqueCheckIndex::canonicalize(7));
        $this->assertSame(ImportUniqueCheckIndex::canonicalize('1.50'), ImportUniqueCheckIndex::canonicalize(1.5));
        $this->assertNotSame(ImportUniqueCheckIndex::canonicalize('abc'), ImportUniqueCheckIndex::canonicalize('ABC'));
    }

    public function testSingleColumnDuplicateAndCurrentRowExclusion(): void
    {
        $objects = $this->objects([['code' => 'A'], ['code' => 'B'], ['code' => 'A'], ['code' => 'C']]);
        $names = ['value.code'];

        foreach (array_keys($objects) as $key) {
            [$expected, $actual, $scans] = $this->compare($objects, $names, $key);
            $this->assertSame($expected, $actual, "row $key");
        }
        // rows 2 and 4 duplicate each other; B and C are unique and do not need the legacy scan
        [$e, $a, $scans] = $this->compare($objects, $names, 3);
        $this->assertFalse($a[0]);
        $this->assertSame(0, $scans, 'no duplicate: the legacy scan must not run');
        [$e, $a, $scans] = $this->compare($objects, $names, 2);
        $this->assertTrue($a[0]);
        $this->assertSame(['value.code'], $a[1]);

        // a row is never its own sibling
        [$e, $a] = $this->compare($this->objects([['code' => 'A']]), $names, 2);
        $this->assertFalse($a[0]);
    }

    public function testNullAndEmptyValues(): void
    {
        $names = ['value.code'];
        $cases = [
            [['code' => null], ['code' => null]],        // both null: skipped, not duplicated
            [['code' => null], ['code' => 'x']],
            [['code' => 'x'], ['code' => null]],
            [['code' => ''], ['code' => null]],          // legacy: '' == null => duplicated (unsafe, legacy scan)
            [['code' => null], ['code' => 0]],           // legacy: null == 0 => duplicated
            [['code' => '0'], ['code' => null]],
            [['code' => '0'], ['code' => 0]],
            [['code' => ''], ['code' => '']],
            [[], []],                                    // missing keys
            [[], ['code' => 'x']],
            [['code' => []], ['code' => null]],
        ];
        foreach ($cases as $i => [$r1, $r2]) {
            $objects = $this->objects([$r1, $r2]);
            foreach ([2, 3] as $key) {
                [$expected, $actual] = $this->compare($objects, $names, $key);
                $this->assertSame($expected, $actual, "case $i row $key");
            }
        }
    }

    public function testCompositeKeepsLegacyAccumulatedColumnKeys(): void
    {
        $names = ['parent_id', 'value.name'];
        // R0 and R1 share the parent only: the legacy scan leaks "parent_id" into the final column keys.
        $objects = [
            2 => ['data' => ['parent_id' => 1, 'value' => ['name' => 'b']], 'model' => null],
            3 => ['data' => ['parent_id' => 1, 'value' => ['name' => 'a']], 'model' => null],
            4 => ['data' => ['parent_id' => 2, 'value' => ['name' => 'a']], 'model' => null],
            5 => ['data' => ['parent_id' => 1, 'value' => ['name' => 'a']], 'model' => null],
        ];
        foreach (array_keys($objects) as $key) {
            [$expected, $actual] = $this->compare($objects, $names, $key);
            $this->assertSame($expected, $actual, "row $key");
        }
        [$expected, $actual] = $this->compare($objects, $names, 5);
        $this->assertSame(['parent_id', 'parent_id', 'value.name'], $actual[1], 'legacy accumulated keys');

        // same name under another parent is not a duplicate; no legacy scan is needed
        [$e, $a, $scans] = $this->compare($objects, $names, 4);
        $this->assertFalse($a[0]);
        $this->assertSame(0, $scans);
    }

    public function testCreateAndUpdateIgnoreIds(): void
    {
        $objects = [
            2 => ['data' => ['value' => ['code' => 'A']], 'model' => ['id' => 10]],
            3 => ['data' => ['value' => ['code' => 'B']], 'model' => null],
            4 => ['data' => ['value' => ['code' => 'C']], 'model' => ['id' => 12]],
        ];
        $index = new ImportUniqueCheckIndex($objects);
        $this->assertSame([10, 12], $index->getIgnoreIds());

        $legacyIds = [];
        foreach ($objects as $value) {
            $model = array_get($value, 'model');
            if (isset($model) && !is_null($id = array_get($model, 'id'))) {
                $legacyIds[] = $id;
            }
        }
        $this->assertSame($legacyIds, $index->getIgnoreIds());
        $this->assertSame([], (new ImportUniqueCheckIndex([]))->getIgnoreIds());
    }

    public function testChunkBoundaryOnlyChecksRowsOfTheDataSet(): void
    {
        $all = $this->objects(array_map(fn($i) => ['code' => 'K' . ($i % 5)], range(0, 19)));
        $names = ['value.code'];

        // whole sheet (browser import): duplicates everywhere
        foreach (array_keys($all) as $key) {
            [$expected, $actual] = $this->compare($all, $names, $key);
            $this->assertSame($expected, $actual, "all row $key");
            $this->assertTrue($actual[0]);
        }

        // chunks of 4 rows (CLI importChunk): the index only sees the rows of its own chunk
        foreach (array_chunk($all, 4, true) as $c => $chunk) {
            foreach (array_keys($chunk) as $key) {
                [$expected, $actual] = $this->compare($chunk, $names, $key);
                $this->assertSame($expected, $actual, "chunk $c row $key");
            }
        }
        $chunk = array_slice($all, 0, 6, true);
        [$e, $a] = $this->compare($chunk, $names, array_key_first($chunk));
        $this->assertTrue($a[0], 'K0 repeats as the 6th row only when the chunk contains it');
        $chunk = array_slice($all, 0, 5, true);
        [$e, $a] = $this->compare($chunk, $names, array_key_first($chunk));
        $this->assertFalse($a[0]);
    }

    public function testRandomizedEquivalenceWithMixedTypes(): void
    {
        mt_srand(20260716);
        $safePool = [null, 'A', 'B', 'a', 'ORD1', '2026-01-01', 1, 2, '1', '2', '01', '2.0', 2.0, 'あ', '-5', -5, 3.5, '3.50', 'x y'];
        $unsafePool = ['', 0, '0', false, true, ' 1', '1e1', 10, [], '123456789012345678901'];
        $nameSets = [
            ['value.a'], ['value.a', 'value.b'], ['parent_id', 'value.a'], ['value.a', 'value.b', 'value.c'], ['parent_id', 'value.a', 'value.b'],
        ];

        $total = 0;
        $withDuplicate = 0;
        $noScan = 0;
        foreach ([[$safePool, 0], [$safePool, 3], [$safePool, 15]] as [$pool, $unsafeEvery]) {
            foreach ($nameSets as $names) {
                for ($round = 0; $round < 12; $round++) {
                    $rows = [];
                    $count = mt_rand(2, 25);
                    for ($i = 0; $i < $count; $i++) {
                        $row = ['value' => []];
                        foreach (['a', 'b', 'c'] as $c) {
                            $source = ($unsafeEvery > 0 && mt_rand(1, $unsafeEvery * 4) === 1) ? $unsafePool : $pool;
                            $row['value'][$c] = $source[mt_rand(0, min(count($source) - 1, 5 + $round))];
                        }
                        $row['parent_id'] = $pool[mt_rand(0, 6)];
                        $rows[$i + 2] = ['data' => $row, 'model' => null];
                    }
                    foreach (array_keys($rows) as $key) {
                        [$expected, $actual, $scans] = $this->compare($rows, $names, $key);
                        $total++;
                        $withDuplicate += $expected[0] ? 1 : 0;
                        $noScan += $scans === 0 ? 1 : 0;
                        $this->assertSame($expected, $actual, 'names=' . implode(',', $names) . " round=$round row=$key");
                    }
                }
            }
        }
        $this->assertGreaterThan(1000, $total);
        $this->assertGreaterThan(100, $withDuplicate, 'the generated data must contain duplicates');
        $this->assertGreaterThan(300, $noScan, 'the index must answer without the legacy scan for safe data');
    }

    public function testInputDifferentFromStoredRowAndNonArrayRows(): void
    {
        $objects = $this->objects([['code' => 'A'], ['code' => 'B']]);
        $objects[4] = ['data' => null, 'model' => null];
        // validator input (not the stored data) decides the lookup
        [$expected, $actual] = $this->compare($objects, ['value.code'], 2, ['value' => ['code' => 'B']]);
        $this->assertSame($expected, $actual);
        $this->assertTrue($actual[0]);
        [$expected, $actual] = $this->compare($objects, ['value.code'], 4, ['value' => ['code' => 'A']]);
        $this->assertSame($expected, $actual);
        $this->assertTrue($actual[0]);

        [$expected, $actual] = $this->compare($objects, [], 2, ['value' => ['code' => 'A']]);
        $this->assertSame($expected, $actual);
    }
}
