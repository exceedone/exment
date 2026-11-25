<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\ColumnItems\ParentItem;
use Exceedone\Exment\Model\ColumnQueryKey;
use Exceedone\Exment\Model\CustomTable;

/**
 * Batched replacement of the per-row parent lookup of ParentItem::getImportValue()
 * ($parent_table->getValueModel()->where("value->$column", $value)->first()).
 *
 * One lookup object lives for exactly one DefaultTableProvider::getDataObject() call (one sheet / one chunk). The
 * conversion phase saves nothing, so the batch reads see the same data as the per-row reads did, and nothing can go stale
 * in a later chunk because the next chunk builds a new object.
 *
 * Equivalence is taken from the database, not re-implemented in PHP: a batch runs the same scoped query
 * ("value->column" IN (...)) and asks the DB, per returned row, which inputs satisfy the very same "json expression = ?"
 * comparison as the original query (collation, padding and number/text comparison included). Anything that cannot be
 * decided that way answers null and the caller runs the original query:
 *   - the input is not a string (the original binds numbers differently), or the driver is not MySQL/MariaDB,
 *   - more than one parent row matches the input (the original returns an arbitrary one),
 *   - a returned row satisfies none of the compared inputs.
 */
class ImportParentLookup
{
    public const BATCH_SIZE = 500;

    public const MAX_CACHED = 50000;

    /**
     * @var array<int|string, array<string, mixed>> import rows of the sheet / chunk
     */
    protected $rows;

    /**
     * @var array<string, array{values: array<int, string>, pos: array<string, int>}>
     */
    protected $plans = [];

    /**
     * "tableId|column" => 's'.value => ['found' => bool, 'id' => mixed] | false (undecided)
     *
     * @var array<string, array<string, array|false>>
     */
    protected $results = [];

    /**
     * @var array<string, ParentItem>
     */
    protected $parentItems = [];

    /**
     * @var array<string, int|null>
     */
    protected $typeIds = [];

    /**
     * @var int number of batch queries executed
     */
    public $batchCount = 0;

    /**
     * @param array<int|string, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /**
     * The ParentItem only provides getImportValue() here, which reads nothing but its table.
     */
    public function parentItem(CustomTable $parentTable): ParentItem
    {
        $key = (string)$parentTable->id;
        if (!isset($this->parentItems[$key])) {
            $this->parentItems[$key] = ParentItem::getItem($parentTable);
        }
        return $this->parentItems[$key];
    }

    /**
     * @param CustomTable $parentTable
     * @param string $column column name of the parent table
     * @param mixed $value
     * @return array{found: bool, id?: mixed}|null null = not decided here, run the original query
     */
    public function resolve(CustomTable $parentTable, string $column, $value): ?array
    {
        if (!is_string($value) || !static::isSupported($parentTable)) {
            return null;
        }

        $key = $parentTable->id . '|' . $column;
        $valueKey = 's' . $value;
        if (!isset($this->results[$key]) || !array_key_exists($valueKey, $this->results[$key])) {
            $this->load($parentTable, $column, $key, $value);
        }

        $result = $this->results[$key][$valueKey] ?? false;
        return $result === false ? null : $result;
    }

    protected static function isSupported(CustomTable $parentTable): bool
    {
        return in_array($parentTable->getValueModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Distinct string parent_id values of the rows pointing at $parentTable, in first-appearance order.
     *
     * @return array{values: array<int, string>, pos: array<string, int>}
     */
    protected function plan(CustomTable $parentTable, string $key): array
    {
        if (isset($this->plans[$key])) {
            return $this->plans[$key];
        }

        $values = [];
        $pos = [];
        foreach ($this->rows as $row) {
            $parentId = $row['parent_id'] ?? null;
            $type = $row['parent_type'] ?? null;
            if (!is_string($parentId) || !is_scalar($type)) {
                continue;
            }

            $typeKey = (string)$type;
            if (!array_key_exists($typeKey, $this->typeIds)) {
                $table = CustomTable::getEloquent($type);
                $this->typeIds[$typeKey] = $table ? $table->id : null;
            }
            if ($this->typeIds[$typeKey] !== $parentTable->id) {
                continue;
            }

            $valueKey = 's' . $parentId;
            if (!isset($pos[$valueKey])) {
                $pos[$valueKey] = count($values);
                $values[] = $parentId;
            }
        }

        return $this->plans[$key] = ['values' => $values, 'pos' => $pos];
    }

    protected function load(CustomTable $parentTable, string $column, string $key, string $value): void
    {
        $plan = $this->plan($parentTable, $key);
        $start = $plan['pos']['s' . $value] ?? null;
        if ($start === null) {
            $this->results[$key]['s' . $value] = false;
            return;
        }

        // the requested value and the next unresolved ones, in row order
        $known = $this->results[$key] ?? [];
        $batch = [];
        for ($i = $start, $n = count($plan['values']); $i < $n && count($batch) < static::BATCH_SIZE; $i++) {
            if (!array_key_exists('s' . $plan['values'][$i], $known)) {
                $batch[] = $plan['values'][$i];
            }
        }

        if (count($known) + count($batch) > static::MAX_CACHED) {
            $this->results[$key] = [];
        }

        $builder = $parentTable->getValueModel()->newQuery();
        $jsonKey = ColumnQueryKey::json($column, $parentTable)->expression();
        $expression = $builder->getQuery()->getGrammar()->wrap($jsonKey);
        $builder->select(getDBTableName($parentTable) . '.id');
        foreach ($batch as $i => $batchValue) {
            $builder->selectRaw("($expression = ?) as m$i", [$batchValue]);
        }
        $builder->whereIn($jsonKey, $batch);

        // toBase(): the same global scopes (permissions) as the original query, without hydrating models
        $rows = $builder->toBase()->get();
        $this->batchCount++;

        $matches = array_fill(0, count($batch), []);
        $consistent = true;
        foreach ($rows as $row) {
            $hit = false;
            foreach ($batch as $i => $_) {
                if ((int)($row->{"m$i"} ?? 0) === 1) {
                    $matches[$i][] = $row->id;
                    $hit = true;
                }
            }
            if (!$hit) {
                $consistent = false;
            }
        }

        // ids are read through a model so that casts/accessors are the same as `$target_value->id` of the original query
        $caster = $parentTable->getValueModel();
        foreach ($batch as $i => $batchValue) {
            if (!$consistent || count($matches[$i]) > 1) {
                $this->results[$key]['s' . $batchValue] = false;
            } elseif (count($matches[$i]) === 0) {
                $this->results[$key]['s' . $batchValue] = ['found' => false];
            } else {
                $caster->setRawAttributes(['id' => $matches[$i][0]]);
                $this->results[$key]['s' . $batchValue] = ['found' => true, 'id' => $caster->id];
            }
        }
    }
}
