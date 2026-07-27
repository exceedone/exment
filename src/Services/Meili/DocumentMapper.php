<?php

namespace Exceedone\Exment\Services\Meili;

/**
 * Map Exment data (CustomValue) to a Meilisearch document.
 *
 * The pure logic (safe id generation + document assembly) is separated out for unit testing.
 */
class DocumentMapper
{
    /**
     * Generate a safe primary key for Meilisearch: "{table_name}__{value_id}".
     * Meilisearch only accepts characters [a-zA-Z0-9_-] for the primary key.
     */
    public function makeDocumentId(string $tableName, $valueId): string
    {
        $safe = strtolower($tableName);
        $safe = preg_replace('/[^a-z0-9_-]/', '_', $safe);

        return "{$safe}__{$valueId}";
    }

    /**
     * Map one Exment CustomValue to a Meilisearch document.
     * Uses values resolved through accessors (select_table -> label, formatted dates...),
     * NOT the raw JSON directly -> search results match Exment.
     *
     * @param  \Exceedone\Exment\Model\CustomValue  $record
     * @param  iterable  $columns  The table's freeword CustomColumns
     * @return array<string,mixed>
     */
    public function map($record, iterable $columns, string $tableName, ?string $tableLabel, iterable $facetColumns = [], iterable $rangeColumns = [], array $aliases = []): array
    {
        $fields = [];
        foreach ($columns as $column) {
            $value = $record->getValue($column, true);
            // A multi-select value may be an array -> merge into a string for easier search.
            if (is_array($value)) {
                $value = implode(' ', array_filter($value, fn ($v) => $v !== null && $v !== ''));
            }
            $fields[$column->column_name] = $value;
        }

        // unified filter fields: creation time + creator.
        $extra = self::filterFieldsV1($record->created_at ?? null, $record->created_user_id ?? null);

        // facets["column=value"] from status/classification columns.
        $facets = [];
        foreach ($facetColumns as $column) {
            $value = $record->getValue($column, true);
            // Column with an alias -> the token uses the alias as prefix (merges columns with the same meaning).
            $prefix = $aliases[$column->column_name] ?? $column->column_name;
            foreach (self::facetTokens($prefix, $value) as $token) {
                $facets[] = $token;
            }
        }
        if (!empty($facets)) {
            $extra['facets'] = array_values(array_unique($facets));
        }

        // n_<col>: numeric field for range filtering.
        foreach ($rangeColumns as $column) {
            $num = self::rangeValue($record->getValue($column), (string) $column->column_type);
            if ($num !== null) {
                $extra['n_' . $column->column_name] = $num;
            }
        }

        return $this->buildDocument($tableName, $tableLabel, $record->id, $record->label, $fields, $extra);
    }

    /**
     * Assemble the document to send to Meilisearch.
     * Drops fields whose value is null or an empty string.
     *
     * @param  array<string,mixed>  $fields
     * @param  array<string,mixed>  $extra  Additional fields (filter v1...) merged into the document.
     * @return array<string,mixed>
     */
    public function buildDocument(
        string $tableName,
        ?string $tableLabel,
        $valueId,
        ?string $label,
        array $fields,
        array $extra = []
    ): array {
        $cleanFields = array_filter($fields, function ($value) {
            return $value !== null && $value !== '';
        });

        return array_merge([
            'id' => $this->makeDocumentId($tableName, $valueId),
            'value_id' => $valueId,
            'table_name' => $tableName,
            'table_label' => $tableLabel,
            'label' => $label,
            'fields' => $cleanFields,
        ], $extra);
    }

    /**
     * Convert a range column value to a comparable number (date -> unix, number -> number).
     *
     * @return int|float|null
     */
    public static function rangeValue($value, string $columnType)
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (in_array($columnType, ['date', 'datetime', 'time'], true)) {
            return self::toTimestamp($value);
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }

    /**
     * Generate facet tokens "column=value" for one column.
     * Array -> multiple tokens; skips null/empty; bool -> 1/0.
     *
     * @return array<int,string>
     */
    public static function facetTokens(string $columnName, $value): array
    {
        $out = [];
        $vals = is_array($value) ? $value : [$value];
        foreach ($vals as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $out[] = $columnName . '=' . (string) $v;
        }

        return $out;
    }

    /**
     * Generate filter fields: f_date (unix) + f_user (array of ids).
     * f_date is skipped when the timestamp cannot be determined.
     *
     * @return array<string,mixed>
     */
    public static function filterFieldsV1($createdAt, $createdUserId): array
    {
        $out = [];

        $ts = self::toTimestamp($createdAt);
        if ($ts !== null) {
            $out['f_date'] = $ts;
        }

        $users = [];
        if ($createdUserId !== null && $createdUserId !== '') {
            $users[] = (int) $createdUserId;
        }
        $out['f_user'] = $users;

        return $out;
    }

    /**
     * Convert a time value (Carbon/DateTime/int/string) to a unix timestamp.
     */
    private static function toTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $t = strtotime((string) $value);

        return $t === false ? null : $t;
    }
}
