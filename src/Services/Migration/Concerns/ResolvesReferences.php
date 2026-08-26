<?php

namespace Exceedone\Exment\Services\Migration\Concerns;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\Migration\Blueprint;

/**
 * Linking imported records to each other.
 *
 * A record can point at one that has not been written yet - a child issue
 * filed before its parent, a ticket whose caller came later in the dump - so
 * anything unresolved is queued and patched once every stream has landed.
 */
trait ResolvesReferences
{
    /**
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     * @return int|null
     */
    protected function resolveReference(array $reference, array $row, array $definition)
    {
        if (!$this->referenceApplies($reference, $row)) {
            return null;
        }

        $raw = $this->pick($row, strval(array_get($definition, 'from', '')));
        if (is_nullorempty($raw)) {
            return null;
        }

        $key = $this->referenceKey($reference, $raw);
        $targetTable = $this->targetTable($reference);

        if (!$targetTable || $this->dry) {
            return null;
        }

        return array_get($this->keyMap($targetTable), $key);
    }

    /**
     * Note a reference that had nothing to point at yet.
     *
     * @param string $name
     * @param array<string, mixed> $stream
     * @param array<string, mixed> $row
     * @param string $tableName
     * @param int $id
     * @return void
     */
    protected function rememberPending(string $name, array $stream, array $row, string $tableName, int $id)
    {
        foreach ((array)array_get($stream, 'columns', []) as $column => $definition) {
            $definition = (array)$definition;
            $reference = array_get($definition, 'ref');
            if (!$reference) {
                continue;
            }

            if (!$this->referenceApplies($reference, $row)) {
                continue;
            }

            $raw = $this->pick($row, strval(array_get($definition, 'from', '')));
            if (is_nullorempty($raw)) {
                continue;
            }

            $targetTable = $this->targetTable($reference);
            if (!$targetTable) {
                continue;
            }

            $key = $this->referenceKey($reference, $raw);
            if (array_get($this->keyMap($targetTable), $key)) {
                continue;
            }

            $this->pending[] = [
                'table' => $tableName,
                'id' => $id,
                'column' => strval($column),
                'target' => $targetTable->table_name,
                'key' => $key,
            ];
        }
    }

    /**
     * Fill in the references that pointed at a record not yet imported.
     *
     * A child issue filed before its parent, a ticket whose caller was created
     * later - normal in any real dataset, and the reason a single pass leaves
     * holes that nobody notices until somebody clicks through.
     *
     * @return array<string, int>
     */
    protected function resolvePending(): array
    {
        $result = ['pending' => count($this->pending), 'patched' => 0, 'unresolved' => 0];

        if ($this->dry || empty($this->pending)) {
            return $result;
        }

        // reload the maps: everything has landed by now
        $this->keyMaps = [];

        foreach ($this->pending as $item) {
            $target = CustomTable::getEloquent(array_get($item, 'target'));
            $table = CustomTable::getEloquent(array_get($item, 'table'));
            if (!isset($target) || !isset($table)) {
                $result['unresolved']++;
                continue;
            }

            $id = array_get($this->keyMap($target), array_get($item, 'key'));
            if (!$id) {
                $result['unresolved']++;
                $this->note(sprintf(
                    '%s.%s still points at "%s", which never arrived',
                    array_get($item, 'table'),
                    array_get($item, 'column'),
                    array_get($item, 'key')
                ));
                continue;
            }

            try {
                $record = $table->getValueModel(array_get($item, 'id'));
                if (!isset($record)) {
                    $result['unresolved']++;
                    continue;
                }
                $record->saved_notify(false);
                $record->setValue([strval(array_get($item, 'column')) => $id]);
                $record->save();
                $result['patched']++;
            } catch (\Throwable $e) {
                $result['unresolved']++;
                $this->note('could not patch a reference: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Does this link apply to this row at all?
     *
     * ServiceNow keeps every conversation on the instance in one table, tagged
     * with the table it belongs to. Without this guard, a comment on a change
     * request would be queued as a link to an incident that does not exist, and
     * the run would end reporting thousands of unresolved references that were
     * never references in the first place.
     *
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $row
     * @return bool
     */
    protected function referenceApplies(array $reference, array $row): bool
    {
        foreach ((array)array_get($reference, 'when', []) as $path => $expected) {
            $actual = strval($this->pick($row, strval($path)));

            $matched = false;
            foreach ((array)$expected as $candidate) {
                if (strval($candidate) === $actual) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $reference
     * @param mixed $raw
     * @return string
     */
    protected function referenceKey(array $reference, $raw): string
    {
        return $this->buildKey(strval(array_get($reference, 'stream')), strval($this->flatten($raw)));
    }

    /**
     * @param array<string, mixed> $reference
     * @return CustomTable|null
     */
    protected function targetTable(array $reference)
    {
        $stream = strval(array_get($reference, 'stream'));
        $tableName = array_get($this->preset, 'streams.' . $stream . '.table');

        return $tableName ? CustomTable::getEloquent($tableName) : null;
    }

    /**
     * migration key => record id, for one table, read once.
     *
     * One query per table beats one query per record: on fifty thousand issues
     * that is the difference between a run measured in minutes and one measured
     * in hours.
     *
     * @param CustomTable $table
     * @return array<string, int>
     */
    protected function keyMap(CustomTable $table): array
    {
        $name = $table->table_name;

        if (array_key_exists($name, $this->keyMaps)) {
            return $this->keyMaps[$name];
        }

        $column = CustomColumn::getEloquent(Blueprint::KEY_COLUMN, $table);
        if (!isset($column)) {
            return $this->keyMaps[$name] = [];
        }

        $map = [];

        $table->getValueModel()->newQuery()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->select(['id', 'value'])
            ->chunk(2000, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    $key = array_get($row->value, Blueprint::KEY_COLUMN);
                    if (!is_nullorempty($key)) {
                        $map[strval($key)] = intval($row->id);
                    }
                }
            });

        return $this->keyMaps[$name] = $map;
    }
}
