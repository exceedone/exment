<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomValue;

/**
 * Model dual-write (02 §15.4, §15.13): the projection of the JSON a save actually writes, added to
 * that save's own INSERT/UPDATE attributes.
 *
 * Where: CustomValue::getAttributesForInsert() / getDirtyForUpdate(), i.e. after the creating /
 * updating event and every one of its listeners, after the timestamps, immediately before the
 * statement. The input is the `value` text of the statement itself, so the typed columns and
 * value_original always match the persisted JSON exactly - whatever prepare(), a saving /
 * creating / updating listener, disable_saving_event or restore_revision did to it.
 *
 * - UPDATE that does not write `value` (only other attributes are dirty): nothing is projected;
 *   the row's JSON is not rewritten, so its typed columns are not either.
 * - UPDATE / INSERT that writes `value`: every staging column and the whole value_original are
 *   set from that JSON (full projection). value_original holds single-valued columns only, all of
 *   which are recomputed from the same JSON the statement writes, so no key is ever taken from a
 *   stale model snapshot.
 *
 * Intent: Exment writes the whole `value` (last writer wins - unchanged); omitted keys are taken
 * back from the model's original (V-3, possibly stale) and explicit nulls removed (V-2) by
 * prepare(). The projection does not reinterpret intent; it mirrors what is persisted.
 */
final class DualWriter
{
    /**
     * @param array<string, mixed> $attributes the statement's attributes (dirty or insert)
     * @return array<string, mixed>
     */
    public static function projectStatement(CustomValue $model, DualWriteContext $context, array $attributes, bool $insert): array
    {
        if (!$insert && !array_key_exists('value', $attributes)) {
            $context->markProjected();
            return $attributes;
        }

        $json = Projection::decode($attributes['value'] ?? null);
        $projection = Projection::of($context->table(), $json);
        $original = $projection->original();

        static::assertColumnsExist($model->getTable(), array_keys($projection->typed()));

        foreach ($projection->typed() as $pcol => $typed) {
            $attributes[$pcol] = $typed;
            $model->setStorageAttribute($pcol, $typed);
        }
        $encoded = empty($original) ? null : $model->encodeStorageJson($original);
        $attributes[PhysicalNaming::VALUE_ORIGINAL] = $encoded;
        $model->setStorageAttribute(PhysicalNaming::VALUE_ORIGINAL, $encoded);

        $context->markProjected();
        return $attributes;
    }

    /**
     * The staging columns and value_original must exist (provisioned before dual-write): a
     * missing one refuses the write instead of skipping it. Uses the cached column listing.
     *
     * @param array<int, string> $pcols
     */
    public static function assertColumnsExist(string $db_table_name, array $pcols): void
    {
        $missing = [];
        foreach (array_merge($pcols, [PhysicalNaming::VALUE_ORIGINAL]) as $name) {
            if (!hasColumn($db_table_name, $name)) {
                $missing[] = $name;
            }
        }
        if (!empty($missing)) {
            throw new StorageConversionException("dual-write refused: $db_table_name lacks " . implode(', ', $missing), []);
        }
    }
}
