<?php

namespace Exceedone\Exment\Model;

/**
 * The single place for set-based writes of the JSON `value` of business custom tables (C1).
 *
 * No model, no save(), no events, no revision, no ValueWriter::prepare() - the statements are the
 * ones the callers ran before, unchanged. Which rows a statement touches is decided by the caller:
 *
 * - removeKey(): one JSON_REMOVE over the rows of $query (ExtendedBuilderTrait::updateRemovingJsonKey).
 *   On an Eloquent builder its global scopes are NOT applied (the base query is used): soft-deleted
 *   rows and rows the current user cannot see are included. The key is written into the SQL, no
 *   binding. MySQL / MariaDB only.
 * - setKey(): one JSON_SET of one row by id, through the query builder.
 *
 * Hybrid storage (Stage B block 3): each statement runs under the storage write protocol
 * (Services\Storage\StorageBulkWrite): unchanged in JSON-only states; in dual-write states it is
 * followed, in the same transaction, by the projection of what it persisted; refused otherwise.
 * W1 writes one record through ValueWriter; BulkInsert / restore (W4) and plugin `value->`
 * writes (W6) are not here.
 */
final class ValueBulkWriter
{
    /**
     * Remove the key of $column_name from the value of every row $query selects.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder $query
     * @return bool the statement result
     */
    public static function removeKey($query, string $column_name): bool
    {
        $db_table_name = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getModel()->getTable() : (string)$query->from;

        // hybrid storage: participates in the storage write protocol; in dual-write the same rows'
        // typed column and value_original entry follow (StorageBulkWrite)
        return (bool)\Exceedone\Exment\Services\Storage\StorageBulkWrite::run(
            $db_table_name,
            function () use ($query, $db_table_name, $column_name) {
                // W6: observation only, before the statement
                ValueWriteObservation::emit(ValueWriteObservation::bulk(ValueWriteObservation::KIND_BULK_REMOVE_KEY, $db_table_name, $column_name));

                // @phpstan-ignore-next-line ExtendedBuilderTrait
                return $query->updateRemovingJsonKey(CustomColumn::jsonValuePath($column_name));
            },
            function ($table) use ($column_name) {
                \Exceedone\Exment\Services\Storage\StorageBulkWrite::assertRemoveKeySupported($table, $column_name);
            },
            function ($table) use ($query, $db_table_name, $column_name) {
                \Exceedone\Exment\Services\Storage\StorageBulkWrite::projectRemovedKey($table, $db_table_name, $query, $column_name);
            }
        );
    }

    /**
     * SQL Server: Laravel's grammar cannot express `value->key = ?` for this driver (it emits
     * `json_value(...) = ?`, a syntax error), so the JSON_SET of the MySQL grammar becomes
     * JSON_MODIFY (documented: learn.microsoft.com JSON_MODIFY). A scalar is bound with its PHP type
     * (a string stays a JSON string, a number a JSON number); null becomes JSON null (a plain NULL
     * argument would remove the key); an array is inserted as a JSON fragment.
     *
     * @param mixed $id
     * @param mixed $value
     */
    private static function sqlServerSetKey(string $db_table_name, $id, string $column_name, $value): int
    {
        $grammar = \DB::getQueryGrammar();
        $col = $grammar->wrap('value');
        $path = '$."' . str_replace('"', '\\"', $column_name) . '"';
        if ($value === null) {
            // JSON null: in lax mode a NULL argument REMOVES the key; in strict mode it sets null but the key
            // must exist - so the key is created first, then set to null in strict mode
            $sql = 'update ' . $grammar->wrapTable($db_table_name) . " set $col = JSON_MODIFY(JSON_MODIFY($col, ?, N''), ?, NULL) where " . $grammar->wrap('id') . ' = ?';
            return (int)\DB::update($sql, [$path, 'strict ' . $path, $id]);
        }
        if (is_array($value) || is_object($value)) {
            $expression = 'JSON_QUERY(?)';
            $bindings = [$path, json_encode($value)];
        } else {
            $expression = '?';
            $bindings = [$path, is_bool($value) ? (int)$value : $value];
        }
        $sql = 'update ' . $grammar->wrapTable($db_table_name) . " set $col = JSON_MODIFY($col, ?, $expression) where " . $grammar->wrap('id') . ' = ?';
        $bindings[] = $id;
        return (int)\DB::update($sql, $bindings);
    }

    /**
     * Set the key of $column_name in the value of the row $id of $db_table_name.
     *
     * @param mixed $id
     * @param mixed $value
     * @return int affected rows
     */
    public static function setKey(string $db_table_name, $id, string $column_name, $value): int
    {
        // hybrid storage: participates in the storage write protocol; in dual-write the row's
        // typed column and value_original entry follow from the persisted JSON (StorageBulkWrite)
        return (int)\Exceedone\Exment\Services\Storage\StorageBulkWrite::run(
            $db_table_name,
            function () use ($db_table_name, $id, $column_name, $value) {
                // W6: observation only, before the statement (no value)
                ValueWriteObservation::emit(ValueWriteObservation::bulk(ValueWriteObservation::KIND_BULK_SET_KEY, $db_table_name, $column_name, $id));

                if (\Exceedone\Exment\Services\Storage\PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
                    return static::sqlServerSetKey($db_table_name, $id, $column_name, $value);
                }
                return \DB::table($db_table_name)->where('id', $id)->update([CustomColumn::jsonValuePath($column_name) => $value]);
            },
            null,
            function ($table) use ($db_table_name, $id, $column_name) {
                \Exceedone\Exment\Services\Storage\StorageBulkWrite::projectKey($table, $db_table_name, $id, $column_name);
            }
        );
    }
}
