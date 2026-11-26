<?php

namespace Exceedone\Exment\Services\Encryption;

use Exceedone\Exment\Model\CustomTable;

/**
 * The values already there of a column converted when its option "encrypted" is turned on or
 * off (ISS00148): the rows of the table (deleted ones too), then the revisions of them (their
 * old and new json). A part at a time, from an id on, so a job or a command goes on where it
 * stopped. Written straight (no saving event, no revision, no new updated_at): the value is
 * the same, only the way it is kept changes.
 *
 * Encrypting also encrypts again a value of a key that is not the current one (a rotation).
 */
class ColumnConverter
{
    public const PHASE_ROWS = 'rows';
    public const PHASE_REVISIONS = 'revisions';

    /** Rows (or revisions) in one part. */
    public const CHUNK = 500;

    /**
     * Convert one part.
     *
     * @return array{phase: string, after: int, done: bool, converted: int, unreadable: int}
     */
    public static function step(CustomTable $table, string $column, bool $encrypt, string $phase = self::PHASE_ROWS, int $after = 0, int $limit = self::CHUNK): array
    {
        $result = ['phase' => $phase, 'after' => $after, 'done' => false, 'converted' => 0, 'unreadable' => 0];
        if ($phase === static::PHASE_ROWS) {
            $rows = \DB::table(getDBTableName($table))->where('id', '>', $after)->orderBy('id')->limit($limit)->get(['id', 'value']);
            foreach ($rows as $row) {
                $result['after'] = intval($row->id);
                $json = static::convertJson($row->value, $column, $encrypt, $result);
                if (isset($json)) {
                    \DB::table(getDBTableName($table))->where('id', $row->id)->update(['value' => $json]);
                }
            }
            if (count($rows) < $limit) {
                // the rows are done: the revisions next
                $result['phase'] = static::PHASE_REVISIONS;
                $result['after'] = 0;
            }
            return $result;
        }

        $type = static::morphClass($table);
        $revisions = \DB::table('revisions')->where('revisionable_type', $type)->where('key', 'value')
            ->where('id', '>', $after)->orderBy('id')->limit($limit)->get(['id', 'old_value', 'new_value']);
        foreach ($revisions as $revision) {
            $result['after'] = intval($revision->id);
            $update = [];
            foreach (['old_value', 'new_value'] as $field) {
                $json = static::convertJson($revision->{$field}, $column, $encrypt, $result);
                if (isset($json)) {
                    $update[$field] = $json;
                }
            }
            if (count($update) > 0) {
                \DB::table('revisions')->where('id', $revision->id)->update($update);
            }
        }
        $result['done'] = count($revisions) < $limit;
        return $result;
    }

    /**
     * Convert the whole column (the command, the tests).
     *
     * @return array{converted: int, unreadable: int}
     */
    public static function all(CustomTable $table, string $column, bool $encrypt, int $limit = self::CHUNK): array
    {
        $total = ['converted' => 0, 'unreadable' => 0];
        $phase = static::PHASE_ROWS;
        $after = 0;
        do {
            $step = static::step($table, $column, $encrypt, $phase, $after, $limit);
            $total['converted'] += $step['converted'];
            $total['unreadable'] += $step['unreadable'];
            $phase = $step['phase'];
            $after = $step['after'];
        } while (!$step['done']);
        return $total;
    }

    /**
     * The revisionable_type of the rows of a table.
     */
    public static function morphClass(CustomTable $table): string
    {
        $model = $table->getValueModel();
        return isset($model) ? $model->getMorphClass() : $table->table_name;
    }

    /**
     * The json with the value of the column converted, null: not changed.
     *
     * @param mixed $json
     * @param array{phase: string, after: int, done: bool, converted: int, unreadable: int} $result counted
     */
    protected static function convertJson($json, string $column, bool $encrypt, array &$result): ?string
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $values = json_decode($json, true);
        if (!is_array($values) || !array_key_exists($column, $values)) {
            return null;
        }
        $value = $values[$column];
        $converted = EncryptedColumns::convertValue($value, $encrypt);
        if (!isset($converted)) {
            if (EncryptedValue::isEncrypted($value) && (!$encrypt || EncryptedValue::needsRotation($value))) {
                // its key is not here: left as it is
                $result['unreadable']++;
            }
            return null;
        }
        $values[$column] = $converted;
        $result['converted']++;
        return json_encode($values) ?: null;
    }
}
