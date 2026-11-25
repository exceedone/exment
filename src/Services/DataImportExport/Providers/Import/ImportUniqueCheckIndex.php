<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

/**
 * Hash index for the in-file (sibling) unique check of one import data set.
 *
 * It only answers "is there a sibling row that CustomTable::scanUniqueSiblings() would report as duplicate?"
 * faster. Whenever the answer cannot be proven identical to the legacy loose-comparison scan
 * (unsafe value types, empty-like values, very long numbers...), the legacy scan is executed instead.
 */
class ImportUniqueCheckIndex
{
    /**
     * Row data keyed by the import line number, in import order.
     *
     * @var array<int|string, mixed>
     */
    protected $rows = [];

    /**
     * Ids of the existing models in the data set.
     *
     * @var array<int, mixed>
     */
    protected $ignoreIds = [];

    /**
     * Index per column-name signature. false = not indexable, otherwise tuple key => first row keys.
     *
     * @var array<string, array<string, array<int, int|string>>|false>
     */
    protected $indexes = [];

    /**
     * @param array<int|string, mixed> $dataObjects rows of ['data' => ..., 'model' => ...]
     */
    public function __construct($dataObjects)
    {
        foreach ($dataObjects as $line_no => $value) {
            $this->rows[$line_no] = array_get($value, 'data');
            $model = array_get($value, 'model');
            if (isset($model) && !is_null($id = array_get($model, 'id'))) {
                $this->ignoreIds[] = $id;
            }
        }
    }

    /**
     * @return array<int, mixed>
     */
    public function getIgnoreIds(): array
    {
        return $this->ignoreIds;
    }

    /**
     * Find a duplicated sibling row.
     *
     * @param array<int, string> $names value names (e.g. "value.code", "parent_id") of the unique definition, in column order
     * @param mixed $input current row values
     * @param int|string|null $currentKey line number of the current row (excluded from the siblings)
     * @param callable $scan receives iterable siblings, returns [bool $is_duplicate, array $column_keys] (the legacy scan)
     * @return array{0: bool, 1: array<int, mixed>}
     */
    public function findDuplicate(array $names, $input, $currentKey, callable $scan): array
    {
        if (count($names) === 0) {
            return $scan([]);
        }

        $index = $this->getIndex($names);
        $tuple = $index === false ? null : static::tupleOf($names, $input);
        if ($tuple === null) {
            return $scan($this->siblings($currentKey));
        }

        // all values are empty: the legacy scan can never report a duplicate for safe values.
        if (!$tuple[1]) {
            return [false, []];
        }

        $hitKey = null;
        foreach ($index[$tuple[0]] ?? [] as $key) {
            if ($key != $currentKey) {
                $hitKey = $key;
                break;
            }
        }
        if (is_null($hitKey)) {
            return [false, []];
        }

        // Re-run the legacy scan up to the first matching row, so the reported columns are identical.
        $result = $scan($this->siblings($currentKey, $hitKey));
        return $result[0] ? $result : $scan($this->siblings($currentKey));
    }

    /**
     * Siblings in import order, excluding the current row, optionally up to (and including) $untilKey.
     *
     * @param int|string|null $currentKey
     * @param int|string|null $untilKey
     * @return \Generator
     */
    protected function siblings($currentKey, $untilKey = null)
    {
        foreach ($this->rows as $key => $row) {
            if ($key != $currentKey) {
                yield $row;
            }
            if (!is_null($untilKey) && $key === $untilKey) {
                return;
            }
        }
    }

    /**
     * @param array<int, string> $names
     * @return array<string, array<int, int|string>>|false
     */
    protected function getIndex(array $names)
    {
        $signature = implode("\x1f", $names);
        if (!array_key_exists($signature, $this->indexes)) {
            $this->indexes[$signature] = $this->buildIndex($names);
        }
        return $this->indexes[$signature];
    }

    /**
     * @param array<int, string> $names
     * @return array<string, array<int, int|string>>|false
     */
    protected function buildIndex(array $names)
    {
        $map = [];
        foreach ($this->rows as $key => $row) {
            $tuple = static::tupleOf($names, $row);
            if ($tuple === null) {
                return false;
            }
            if (!$tuple[1]) {
                continue;
            }
            // two keys are enough to find the first row other than the current one
            if (!isset($map[$tuple[0]])) {
                $map[$tuple[0]] = [$key];
            } elseif (count($map[$tuple[0]]) < 2) {
                $map[$tuple[0]][] = $key;
            }
        }
        return $map;
    }

    /**
     * @param array<int, string> $names
     * @param mixed $values
     * @return array{0: string, 1: bool}|null [tuple key, has any non-null value]. null if any value is not safely comparable.
     */
    protected static function tupleOf(array $names, $values): ?array
    {
        $parts = [];
        $hasValue = false;
        foreach ($names as $name) {
            $canonical = static::canonicalize(array_get($values, $name));
            if ($canonical === false) {
                return null;
            }
            if (is_null($canonical)) {
                $parts[] = 'N';
                continue;
            }
            $hasValue = true;
            $parts[] = strlen($canonical) . ':' . $canonical;
        }
        return [implode('|', $parts), $hasValue];
    }

    /**
     * Canonical key such that, for two canonicalizable values, a == b (PHP 8 loose comparison) is true
     * if and only if both keys are equal (or both are null).
     * Returns false for values that are not provably so: empty string, zero, bool, array, object,
     * floats in exponent form, numeric strings with whitespace/exponent, long decimals, and integers over 18 digits.
     *
     * @param mixed $value
     * @return string|false|null null for null
     */
    public static function canonicalize($value)
    {
        if (is_null($value)) {
            return null;
        }

        $isFloat = is_float($value);
        if (is_int($value) || $isFloat) {
            if ($isFloat && !is_finite($value)) {
                return false;
            }
            $value = (string)$value;
        } elseif (!is_string($value)) {
            return false;
        } elseif ($value === '') {
            return false;
        } elseif (!is_numeric($value)) {
            return 'S' . $value;
        }

        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?\z/', $value, $m)) {
            return false;
        }

        $int = ltrim($m[2], '0');
        $hasDecimalPoint = isset($m[3]);
        $fraction = $hasDecimalPoint ? rtrim($m[3], '0') : '';
        if ($int === '' && $fraction === '') {
            return false;
        }

        // Decimal syntax is compared as float by PHP: keep it within the exact 15 significant digits.
        // Integer syntax is compared as int up to PHP_INT_MAX: 18 digits is safe.
        if ($hasDecimalPoint || $isFloat) {
            if (strlen($int) + strlen($fraction) > 15) {
                return false;
            }
        } elseif (strlen($int) > 18) {
            return false;
        }

        return 'D' . ($m[1] === '-' ? '-' : '') . ($int === '' ? '0' : $int) . ($fraction === '' ? '' : '.' . $fraction);
    }
}
