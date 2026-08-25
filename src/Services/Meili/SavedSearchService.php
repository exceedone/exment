<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

/**
 * Clean up Saved Search filters against the CURRENT metadata before applying them:
 * deleted/unauthorized tables, deleted/renamed columns, deleted users, ranges no longer
 * declared... are all dropped silently (without breaking the page), and the dropped parts are
 * returned to notify the user.
 *
 * Generic: works only on the standard search-screen parameters
 * (tables/date_from/date_to/users/facets/range) — knows nothing about the business domain.
 */
class SavedSearchService
{
    /**
     * A request value as a clean list of non-empty strings, dropping anything
     * that is not scalar.
     *
     * The save modal posts the current query string as-is, so `?tables[][]=x`
     * arrives here as a nested array: strval() on it is an "Array to string
     * conversion" E_WARNING, which Laravel turns into an ErrorException — the
     * save 500s, and without the exception it would store the literal "Array".
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private static function scalarList($value): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_filter($value, 'is_scalar')),
            fn ($s) => $s !== ''
        ));
    }

    /**
     * Same guard for a single value: a non-scalar becomes '' (i.e. "not set")
     * instead of the string "Array".
     *
     * @param mixed $value
     */
    private static function scalarString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Whitelist the filter keys allowed to be saved from the request.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function filtersFromInput(array $input): array
    {
        $out = [];
        foreach (['tables', 'users', 'facets'] as $k) {
            $v = self::scalarList($input[$k] ?? []);
            if (!empty($v)) {
                $out[$k] = $v;
            }
        }
        foreach (['date_from', 'date_to'] as $k) {
            $v = self::scalarString($input[$k] ?? '');
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        $ranges = (array) ($input['range'] ?? []);
        foreach ($ranges as $field => $r) {
            // A range field is always "n_<table>::<column>"; a numeric key means
            // the param was posted as a plain list and can never match a field.
            if (!is_string($field) || !is_array($r)) {
                continue;
            }
            foreach (['from', 'to'] as $side) {
                $v = self::scalarString($r[$side] ?? '');
                if ($v !== '') {
                    $out['range'][$field][$side] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * Clean up filters against the context metadata.
     *
     * $ctx = [
     *   'tables'        => table names that still exist + are still viewable,
     *   'facet_columns' => column_name that still exists (for facet tokens),
     *   'range_fields'  => n_<col> fields still declared as range,
     *   'user_ids'      => user ids that still exist (among the referenced ids),
     * ]
     *
     * @param array<string,mixed> $stored
     * @param array<string,array<int,int|string>> $ctx
     * @return array{params:array<string,mixed>, dropped:array<int,string>}
     */
    public static function sanitizeWith(array $stored, array $ctx): array
    {
        $params = [];
        $dropped = [];

        // Every value below goes through the scalar guards: rows saved before
        // filtersFromInput() started dropping non-scalars still hold arrays, and
        // "'table:' . $array" is the same E_WARNING -> ErrorException 500 here.

        // tables: keep only tables that still exist + still authorized.
        foreach (self::scalarList($stored['tables'] ?? []) as $t) {
            if (in_array($t, $ctx['tables'] ?? [], true)) {
                $params['tables'][] = $t;
            } else {
                $dropped[] = 'table:' . $t;
            }
        }

        // dates: keep if parseable.
        foreach (['date_from', 'date_to'] as $k) {
            $v = self::scalarString($stored[$k] ?? '');
            if ($v === '') {
                continue;
            }
            if (strtotime($v) !== false) {
                $params[$k] = $v;
            } else {
                $dropped[] = $k . ':' . $v;
            }
        }

        // users: keep only ids that still exist.
        $validUsers = array_map('intval', $ctx['user_ids'] ?? []);
        foreach (self::scalarList($stored['users'] ?? []) as $u) {
            if (in_array((int) $u, $validUsers, true)) {
                $params['users'][] = (int) $u;
            } else {
                $dropped[] = 'user:' . $u;
            }
        }

        // facets: "column=value" token — the column must still exist.
        $validCols = array_map('strval', $ctx['facet_columns'] ?? []);
        foreach (self::scalarList($stored['facets'] ?? []) as $token) {
            $col = MeiliSearchService::parseFacetToken($token)['col'];
            if (in_array($col, $validCols, true)) {
                $params['facets'][] = $token;
            } else {
                $dropped[] = 'facet:' . $token;
            }
        }

        // range: the n_<col> field must still be declared.
        $validFields = array_map('strval', $ctx['range_fields'] ?? []);
        foreach ((array) ($stored['range'] ?? []) as $field => $r) {
            if (!is_string($field) || !is_array($r)) {
                continue;
            }
            if (in_array($field, $validFields, true)) {
                foreach (['from', 'to'] as $side) {
                    $v = self::scalarString($r[$side] ?? '');
                    if ($v !== '') {
                        $params['range'][$field][$side] = $v;
                    }
                }
            } else {
                $dropped[] = 'range:' . $field;
            }
        }

        return ['params' => $params, 'dropped' => $dropped];
    }

    /**
     * Clean up against the system's current metadata (gather the context then call the pure version).
     *
     * @param array<string,mixed> $stored
     * @return array{params:array<string,mixed>, dropped:array<int,string>}
     */
    public static function sanitize(array $stored): array
    {
        return static::sanitizeWith($stored, [
            'tables' => static::searchableTableNames(),
            // Same guard as sanitizeWith(): the lookups below concatenate and
            // cast these values, so a stored non-scalar must not reach them.
            'facet_columns' => static::existingColumnNames(self::scalarList($stored['facets'] ?? [])),
            'range_fields' => FilterConfig::allRangeFields(),
            'user_ids' => static::existingUserIds(self::scalarList($stored['users'] ?? [])),
        ]);
    }

    /**
     * Tables that are still searchable + the current user still has view permission.
     *
     * @return array<int,string>
     */
    public static function searchableTableNames(): array
    {
        try {
            return CustomTable::searchEnabled()->get()
                ->filter(fn ($t) => $t->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE))
                ->pluck('table_name')
                ->all();
        } catch (\Throwable $e) {
            // Fail closed: an empty list is also the sidebar's permission
            // boundary, so it hides everything. Log it or the sidebar just
            // looks empty for no visible reason.
            \Illuminate\Support\Facades\Log::warning('[Meili] searchable table list unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Tables whose facet counts are safe to show: the user can see every row.
     *
     * Meilisearch computes facet distributions over the whole index, which holds
     * every row regardless of permission (the index is shared - see
     * ExmentIndexer). For a table granted row by row, those counts describe rows
     * the user cannot open: they reveal how many exist and which values they
     * carry. searchableTableNames() is too wide for that - it also admits
     * AVAILABLE_ACCESS_CUSTOM_VALUE, which is exactly the row-by-row case.
     *
     * @return array<int,string>
     */
    public static function facetableTableNames(): array
    {
        try {
            return CustomTable::searchEnabled()->get()
                ->filter(fn ($t) => $t->hasPermission(Permission::AVAILABLE_ALL_CUSTOM_VALUE))
                ->pluck('table_name')
                ->all();
        } catch (\Throwable $e) {
            // Fail closed, same as searchableTableNames().
            \Illuminate\Support\Facades\Log::warning('[Meili] facetable table list unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Facet prefixes that are still valid: "table::column" or a configured alias.
     * A bare column_name is a pre-qualification leftover and is dropped, so the
     * caller warns instead of silently filtering on a token no document carries.
     *
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    protected static function existingColumnNames(array $tokens): array
    {
        $cols = array_values(array_unique(array_map(
            fn ($t) => MeiliSearchService::parseFacetToken((string) $t)['col'],
            $tokens
        )));
        if (empty($cols)) {
            return [];
        }

        $parts = [];
        foreach ($cols as $col) {
            $parts[$col] = DocumentMapper::splitColumnPrefix((string) $col);
        }

        $aliases = FilterConfig::allAliases();

        // Only qualified prefixes need a column lookup.
        $qualified = array_filter($parts, fn ($p) => $p['table'] !== null);

        $rows = [];
        if (!empty($qualified)) {
            try {
                $rows = \DB::table('custom_columns')
                    ->join('custom_tables', 'custom_tables.id', '=', 'custom_columns.custom_table_id')
                    ->whereIn('custom_columns.column_name', array_column($qualified, 'column'))
                    ->get(['custom_columns.column_name as _column', 'custom_tables.table_name as _table']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Meili] facet column validation unavailable: ' . $e->getMessage());
                return [];
            }
        }

        $out = [];
        foreach ($parts as $prefix => $part) {
            // Bare prefix: valid only as an alias.
            if ($part['table'] === null) {
                if (in_array((string) $prefix, $aliases, true)) {
                    $out[] = (string) $prefix;
                }
                continue;
            }

            foreach ($rows as $row) {
                if ($row->_column !== $part['column']) {
                    continue;
                }
                if ($row->_table !== $part['table']) {
                    continue;
                }
                $out[] = (string) $prefix;
                break;
            }
        }

        return $out;
    }

    /**
     * User ids that still exist, among the referenced ids.
     *
     * @param array<int,int|string> $ids
     * @return array<int,int>
     */
    protected static function existingUserIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        try {
            $userTable = CustomTable::getEloquent(SystemTableName::USER);
            if (!$userTable) {
                return [];
            }
            return getModelName($userTable)::whereIn('id', array_map('intval', $ids))
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] user id validation unavailable: ' . $e->getMessage());
            return [];
        }
    }
}
