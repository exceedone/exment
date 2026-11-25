<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

/**
 * The public `value` of a custom value (02 §15.2 publicValue), used by the `value` accessor and
 * therefore by getValue / setValue, toArray / API, revisions read through the model, exports and
 * every caller of `$model->value`.
 *
 * - Table not reading physical: the stored JSON, unchanged.
 * - Table reading physical, row as loaded (not new, `value` not changed in memory):
 *     single-valued typed column c: value_original[c] if present, else render(type, pcol_c) when
 *     not NULL, else absent; multi-valued columns and every other (unknown, retained) key: the
 *     JSON value. Key order follows the JSON.
 * - Partial select (a typed column or value_original not loaded): never read as NULL and never
 *   reloaded per row. While the JSON shadow is complete (every reachable state - no cleanup
 *   exists), the JSON shadow is returned: it equals publicValue by the write protocol. Without a
 *   complete shadow it throws (unreachable now; explicit for the cleanup block).
 * - A new model or a `value` changed in memory: the JSON being written (what the next save's
 *   projection will mirror), not a reconstruction from the loaded typed columns.
 */
final class PublicValueResolver
{
    /**
     * @param array<string, mixed>|null $json the decoded `value` attribute
     * @return array<string, mixed>|null
     */
    public static function resolve(CustomValue $model, ?array $json): ?array
    {
        if (!$model->storageReadsPhysical()) {
            return $json;
        }
        $table = $model->custom_table;
        $storage = TableStorage::fromModel($table);
        if (!$model->exists || $model->valueChangedInMemory()) {
            return $json;
        }

        $attributes = $model->getAttributes();
        $columns = static::typedColumns($table);
        $loaded = array_key_exists(PhysicalNaming::VALUE_ORIGINAL, $attributes);
        foreach ($columns as $c) {
            $loaded = $loaded && array_key_exists($c['pcol'], $attributes);
        }
        if (!$loaded) {
            if ($storage->hasCompleteShadow()) {
                return $json;
            }
            throw new \Exceedone\Exment\Exceptions\StorageConversionException('public value refused: typed columns not selected and no complete JSON shadow', []);
        }

        $original = Projection::decode($attributes[PhysicalNaming::VALUE_ORIGINAL]) ?? [];
        $public = $json ?? [];
        foreach ($columns as $name => $c) {
            if (array_key_exists($name, $original)) {
                $public[$name] = $original[$name];
                continue;
            }
            $typed = $attributes[$c['pcol']];
            if ($typed === null) {
                unset($public[$name]);
                continue;
            }
            $public[$name] = PublicValueRenderer::render($c['type'], $typed);
        }
        return ($json === null && empty($public)) ? null : $public;
    }

    /** @return array<string, array{pcol: string, type: string}> keyed by column_name */
    private static function typedColumns(CustomTable $table): array
    {
        $out = [];
        foreach ($table->custom_columns_cache as $column) {
            $type = PhysicalColumnType::of($column);
            if ($type !== null) {
                $out[(string)$column->column_name] = ['pcol' => PhysicalNaming::stagingColumn($column), 'type' => $type];
            }
        }
        return $out;
    }
}
