<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Str;

/**
 * Makes custom column references inside JSON settings portable between systems.
 *
 * Settings such as a kanban or gantt view store a column by its numeric id.
 * Those ids are assigned per install, so a template carrying them either
 * resolves to the wrong column elsewhere or to nothing at all - the view then
 * fails silently, which is worse than failing loudly. This trait rewrites them
 * to "table_name.column_name" on export and back to an id on import.
 *
 * Three shapes are handled, all found in view options:
 *   1. a key ending in _column_id whose value is the id      (610)
 *   2. a key ending in _column_ids whose value is a list     (["610","612"])
 *   3. a value prefixed with an id and "::"                  ("610::closed")
 *
 * Exment validates table and column names with Define::RULES_REGEX_SYSTEM_NAME,
 * which allows letters, digits, "_" and "-" but never ".", so the dot separator
 * cannot collide with a name.
 */
trait TemplateColumnRefTrait
{
    /**
     * One "table_name.column_name" reference, matching the character set
     * Define::RULES_REGEX_SYSTEM_NAME accepts.
     */
    private const REF_PATTERN = '/^([A-Za-z0-9_\-]+)\.([A-Za-z0-9_\-]+)$/';

    /**
     * The same reference used as a prefix, as in "table.column::closed".
     */
    private const EMBEDDED_REF_PATTERN = '/^([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)::(.*)$/s';

    /**
     * Suffix marking a key that holds a column id.
     */
    private const COLUMN_ID_SUFFIX = '_column_id';

    /**
     * Suffix the same key carries once the id has been replaced.
     */
    private const COLUMN_REF_SUFFIX = '_column';

    /**
     * Suffix marking a key that holds a list of column ids.
     */
    private const COLUMN_IDS_SUFFIX = '_column_ids';

    /**
     * Suffix the same key carries once the ids have been replaced.
     */
    private const COLUMN_REFS_SUFFIX = '_columns';

    /**
     * "table_name.column_name" for a column id, or null when it cannot be resolved.
     *
     * @param mixed $id
     * @return string|null
     */
    protected static function templateColumnIdToRef($id): ?string
    {
        if (!is_numeric($id)) {
            return null;
        }

        $column = CustomColumn::getEloquent($id);
        if (!isset($column) || !isset($column->custom_table)) {
            return null;
        }

        return $column->custom_table->table_name . '.' . $column->column_name;
    }

    /**
     * Column id for a "table_name.column_name" reference, or null when the
     * target does not exist on this system.
     *
     * @param mixed $ref
     * @return int|null
     */
    protected static function templateColumnRefToId($ref): ?int
    {
        if (!is_string($ref) || !preg_match(self::REF_PATTERN, $ref, $m)) {
            return null;
        }

        $table = CustomTable::getEloquent($m[1]);
        if (!isset($table)) {
            return null;
        }

        $column = CustomColumn::getEloquent($m[2], $table);

        return isset($column) ? intval($column->id) : null;
    }

    /**
     * Rewrite every column id in a settings array to a portable reference.
     *
     * Keys ending in _column_id are renamed (the "_id" is dropped) so that an
     * old template carrying raw ids and a new one carrying references never
     * occupy the same key and cannot be confused on import.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected static function templateExportColumnRefs(array $settings): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if (is_string($key) && Str::endsWith($key, self::COLUMN_IDS_SUFFIX) && is_array($value)) {
                $result[Str::beforeLast($key, '_ids') . 's'] = static::templateReplaceColumnRefList($value, true);
                continue;
            }

            if (is_string($key) && Str::endsWith($key, self::COLUMN_ID_SUFFIX) && is_numeric($value)) {
                $ref = static::templateColumnIdToRef($value);
                if (isset($ref)) {
                    $result[Str::beforeLast($key, '_id')] = $ref;
                    continue;
                }
            }

            $result[$key] = static::templateReplaceEmbedded($value, true);
        }

        return $result;
    }

    /**
     * Reverse of templateExportColumnRefs.
     *
     * A reference that no longer resolves is dropped rather than kept, because
     * a stale numeric id would point at an unrelated column on this system.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected static function templateImportColumnRefs(array $settings): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if (is_string($key) && Str::endsWith($key, self::COLUMN_REFS_SUFFIX) && is_array($value)) {
                $result[Str::beforeLast($key, 's') . '_ids'] = static::templateReplaceColumnRefList($value, false);
                continue;
            }

            if (is_string($key) && Str::endsWith($key, self::COLUMN_REF_SUFFIX) && is_string($value)) {
                $id = static::templateColumnRefToId($value);
                if (isset($id)) {
                    // Exment stores these option values as strings (they arrive
                    // from a form), so restore the same type the UI would write.
                    $result[$key . '_id'] = strval($id);
                    continue;
                }
                if (preg_match(self::REF_PATTERN, $value)) {
                    // Unresolvable column reference: leave the setting out.
                    continue;
                }
            }

            $result[$key] = static::templateReplaceEmbedded($value, false);
        }

        return $result;
    }

    /**
     * Convert a list whose entries are either a column id or a fixed keyword.
     *
     * Notify stores notify_action_target this way: ["612", "created_user"].
     * Only plain numeric entries denote a column; keywords and the compound
     * "<id>?view_pivot_column_id=..." form are passed through untouched, which
     * matches what the public form export already does.
     *
     * @param mixed $targets
     * @param bool $toRef true converts id to reference, false converts back
     * @return mixed
     */
    protected static function templateReplaceColumnRefList($targets, bool $toRef)
    {
        if (!is_array($targets)) {
            return $targets;
        }

        $result = [];
        foreach ($targets as $target) {
            if ($toRef && is_numeric($target)) {
                $result[] = static::templateColumnIdToRef($target) ?? $target;
                continue;
            }

            if (!$toRef && is_string($target) && preg_match(self::REF_PATTERN, $target)) {
                $id = static::templateColumnRefToId($target);
                if (!isset($id)) {
                    // The column does not exist here; drop the recipient rather
                    // than leave a reference that would be read as a column name.
                    continue;
                }
                $result[] = strval($id);
                continue;
            }

            $result[] = $target;
        }

        return $result;
    }

    /**
     * Walk a value of any depth and convert the "<column>::<key>" prefixes.
     *
     * @param mixed $value
     * @param bool $toRef true converts id to reference, false converts back
     * @return mixed
     */
    private static function templateReplaceEmbedded($value, bool $toRef)
    {
        if (is_array($value)) {
            $replaced = [];
            foreach ($value as $k => $v) {
                $replaced[$k] = static::templateReplaceEmbedded($v, $toRef);
            }
            return $replaced;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($toRef) {
            if (!preg_match('/^(\d+)::(.*)$/s', $value, $m)) {
                return $value;
            }
            $ref = static::templateColumnIdToRef($m[1]);
            return isset($ref) ? $ref . '::' . $m[2] : $value;
        }

        if (!preg_match(self::EMBEDDED_REF_PATTERN, $value, $m)) {
            return $value;
        }
        $id = static::templateColumnRefToId($m[1]);

        return isset($id) ? $id . '::' . $m[2] : $value;
    }
}
