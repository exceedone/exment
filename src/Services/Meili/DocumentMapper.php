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
     *
     * @param  mixed  $valueId
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
     * @param  iterable<\Exceedone\Exment\Model\CustomColumn>  $columns  The table's freeword CustomColumns
     * @param  iterable<\Exceedone\Exment\Model\CustomColumn>  $facetColumns
     * @param  iterable<\Exceedone\Exment\Model\CustomColumn>  $rangeColumns
     * @param  array<string,string>  $aliases
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

        // facets["<prefix>=value"] from status/classification columns.
        $facets = [];
        foreach ($facetColumns as $column) {
            $value = $record->getValue($column, true);
            $prefix = $aliases[$column->column_name] ?? self::qualifyColumn($tableName, $column->column_name);
            foreach (self::facetTokens($prefix, $value) as $token) {
                $facets[] = $token;
            }
        }
        if (!empty($facets)) {
            $extra['facets'] = array_values(array_unique($facets));
        }

        // n_<table::col>: numeric field for range filtering.
        foreach ($rangeColumns as $column) {
            $num = self::rangeValue($record->getValue($column), (string) $column->column_type);
            if ($num !== null) {
                $extra[self::rangeField($tableName, $column->column_name)] = $num;
            }
        }

        return $this->buildDocument($tableName, $tableLabel, $record->id, $record->label, $fields, $extra);
    }

    /**
     * Assemble the document to send to Meilisearch.
     * Drops fields whose value is null or an empty string.
     *
     * @param  mixed  $valueId
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
     * Column types rangeValue() can turn into a number. Anything else indexes
     * no n_<table>::<column> attribute at all, so a range filter on it matches
     * nothing - user and organization columns resolve to a CustomValue object.
     */
    public const RANGE_COLUMN_TYPES = ['date', 'datetime', 'time', 'integer', 'decimal', 'currency'];

    public static function supportsRange(string $columnType): bool
    {
        return in_array($columnType, self::RANGE_COLUMN_TYPES, true);
    }

    /**
     * Convert a range column value to a comparable number
     * (date/datetime -> unix, time -> seconds since midnight, number -> number).
     *
     * @param  mixed  $value
     * @return int|float|null
     */
    public static function rangeValue($value, string $columnType)
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        // A time column carries no date, so strtotime() would resolve "10:30:00"
        // against the day the record happens to be indexed: the same clock time
        // gets a different number on every reindex, and a filter built today
        // matches nothing indexed yesterday. Seconds since midnight is the only
        // stable comparable number for a time of day.
        if ($columnType === 'time') {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('H:i:s');
            }
            return is_scalar($value) ? self::timeOfDaySeconds((string) $value) : null;
        }
        if (in_array($columnType, ['date', 'datetime'], true)) {
            return self::toTimestamp($value);
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }

    /**
     * "HH:MM" / "HH:MM:SS" -> seconds since midnight. Anything else -> null.
     *
     * Shared by the indexer (DocumentMapper::rangeValue) and the request parser
     * (RequestFilters::parse) so both sides of a time range speak the same unit.
     */
    public static function timeOfDaySeconds(string $value): ?int
    {
        if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m)) {
            return null;
        }
        $hour = (int) $m[1];
        if ($hour > 23) {
            return null;
        }

        return $hour * 3600 + ((int) $m[2]) * 60 + ((int) ($m[3] ?? 0));
    }

    /**
     * html input type of a range column's min/max box in the filter sidebar.
     * A time column needs a time picker: rendering it as a number box let the
     * browser reject "10:30" outright, so the filter could not be used at all.
     */
    public static function rangeInputType(string $columnType): string
    {
        if ($columnType === 'time') {
            return 'time';
        }

        return in_array($columnType, ['date', 'datetime'], true) ? 'date' : 'number';
    }

    /**
     * Separator between table_name and column_name in an unaliased facet prefix.
     * Neither name can contain it (both are [A-Za-z0-9_]), so the prefix stays
     * unambiguous and splittable.
     */
    public const COLUMN_QUALIFIER = '::';

    /**
     * Table-qualified facet prefix: "table_name::column_name".
     */
    public static function qualifyColumn(string $tableName, string $columnName): string
    {
        return $tableName . self::COLUMN_QUALIFIER . $columnName;
    }

    /**
     * Prefix of every numeric range attribute in the index.
     */
    public const RANGE_PREFIX = 'n_';

    /**
     * Injection guard - the field name is concatenated into a Meilisearch
     * filter expression, so nothing but n_<table>::<column> may pass.
     * The unqualified n_<column> shape is rejected on purpose: it matches no
     * attribute, so sanitize() drops it with a warning instead of returning 0 rows.
     */
    public const RANGE_FIELD_PATTERN = '/^n_[A-Za-z0-9_]+::[A-Za-z0-9_]+$/';

    /**
     * Name of the Meilisearch attribute holding a column's range value:
     * "n_<table_name>::<column_name>".
     */
    public static function rangeField(string $tableName, string $columnName): string
    {
        return self::RANGE_PREFIX . self::qualifyColumn($tableName, $columnName);
    }

    /**
     * Split a facet prefix back into [table_name, column_name].
     * An aliased prefix has no qualifier -> table_name is null.
     *
     * @return array{table:?string,column:string}
     */
    public static function splitColumnPrefix(string $prefix): array
    {
        $pos = strpos($prefix, self::COLUMN_QUALIFIER);
        if ($pos === false) {
            return ['table' => null, 'column' => $prefix];
        }

        return [
            'table' => substr($prefix, 0, $pos),
            'column' => substr($prefix, $pos + strlen(self::COLUMN_QUALIFIER)),
        ];
    }

    /**
     * Generate facet tokens "column=value" for one column.
     * Array -> multiple tokens; skips null/empty; bool -> 1/0.
     *
     * @param  mixed  $value
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
     * @param  mixed  $createdAt
     * @param  mixed  $createdUserId
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
     *
     * @param  mixed  $value
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
