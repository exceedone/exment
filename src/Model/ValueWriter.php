<?php

namespace Exceedone\Exment\Model;

/**
 * The single place that writes the JSON `value` of one custom value record (B1).
 *
 * - set():     partial write, one key or a key list (CustomValue::setValue / setValueDirectly).
 * - replace(): the whole value at once (setRevision, the initial user).
 * - prepare(): the full snapshot built in the saving event, before the record's own SQL write:
 *              each column's saving() normalization, omitted keys taken back from the original
 *              row (V-3), null keys removed (V-2), assigned only when something changed.
 *
 * Behavior is exactly the former in-model code: JSON storage only, no storage mode, no second
 * persistence step - nothing here saves the model. A save with disable_saving_event, with
 * restore_revision, or a quiet save does not reach prepare() (see 04_Progress, W1 bypasses).
 * Set-based writes (W2), BulkInsert/restore (W4) and plugin `value->` writes (W6) are not here.
 */
final class ValueWriter
{
    /**
     * Partial write, as DatabaseJsonTrait::setJson('value', ...): a key list sets every key and
     * ignores $forgetIfNull; one key with $forgetIfNull and a null value drops the key.
     *
     * @param string|array<string, mixed>|\Illuminate\Support\Collection<string, mixed>|null $key
     * @param mixed $val
     */
    public static function set(CustomValue $model, $key, $val = null, bool $forgetIfNull = false): void
    {
        if (!isset($key)) {
            return;
        }
        if (is_array($key) || $key instanceof \Illuminate\Support\Collection) {
            foreach ($key as $k => $v) {
                static::set($model, $k, $v);
            }
            return;
        }

        $value = $model->value;
        if (is_null($value)) {
            $value = [];
        }
        if ($forgetIfNull && is_null($val)) {
            array_forget($value, $key);
        } else {
            $value[$key] = static::convert($val);
        }
        $model->value = $value;
    }

    /**
     * Assign the whole value.
     *
     * @param mixed $value
     */
    public static function replace(CustomValue $model, $value): void
    {
        $model->value = $value;
    }

    /**
     * Build the full snapshot of the value in the saving event (CustomValue::prepareValue()).
     */
    public static function prepare(CustomValue $model): void
    {
        // because on value edit display, if before upload file and not upload again, don't post value.
        $value = $model->value;
        $original = json_decode_ex($model->getRawOriginal('value'), true);

        $update_flg = false;
        foreach ($model->custom_table->custom_columns_cache as $custom_column) {
            $column_name = $custom_column->column_name;
            // get saving value
            $v = $custom_column->column_item->setCustomValue($model)->saving();
            // if has value, update
            if (isset($v)) {
                array_set($value, $column_name, $v);
                $update_flg = true;
            }

            if (static::takeOriginal($model, $value, $original, $custom_column)) {
                $update_flg = true;
            }
        }

        // array_forget if $v is null
        // if not execute this, mysql column "virtual" returns string "null".
        foreach ($value as $k => $v) {
            if (is_null($v)) {
                $update_flg = true;
                array_forget($value, $k);
            }
        }

        if ($update_flg) {
            $model->setAttribute('value', $value);
        }

        // W6: observation only, before the save's SQL (NullValueWriteObserver in production)
        ValueWriteObservation::emit(ValueWriteObservation::save(ValueWriteObservation::KIND_PREPARED, $model));
    }

    /**
     * Hybrid storage dual-write: add the typed columns and value_original projected from the
     * `value` this INSERT/UPDATE writes to its own attributes (Services\Storage\DualWriter).
     * Called from CustomValue::getAttributesForInsert() / getDirtyForUpdate(), after every
     * creating/updating listener; returns the attributes unchanged outside a dual-write save.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function projectStatement(CustomValue $model, array $attributes, bool $insert): array
    {
        $context = $model->storageWriteContext();
        if ($context === null) {
            return $attributes;
        }
        return \Exceedone\Exment\Services\Storage\DualWriter::projectStatement($model, $context, $attributes, $insert);
    }

    /**
     * V-3: a column absent from $value is taken back from the original row, unless the user
     * removes its file. Returns whether it was.
     *
     * @param mixed $value
     * @param mixed $original
     * @param CustomColumn $custom_column
     */
    public static function takeOriginal(CustomValue $model, &$value, $original, $custom_column): bool
    {
        if (is_null($value)) {
            $value = [];
        }
        $column_name = $custom_column->column_name;
        // if not key, set from original
        if (array_key_exists($column_name, $value)) {
            return false;
        }
        // property "$remove_file_columns" uses user wants to delete file
        if (in_array($column_name, $model->remove_file_columns())) {
            return false;
        }

        if (!array_key_value_exists($column_name, $original)) {
            return false;
        }

        $value[$column_name] = array_get($original, $column_name);
        return true;
    }

    /**
     * DatabaseJsonTrait::convertSetValue(): a Carbon is kept as its array form (V-7).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function convert($value)
    {
        if ($value instanceof \Carbon\Carbon) {
            return \Exment::carbonToArray($value);
        }
        return $value;
    }
}
