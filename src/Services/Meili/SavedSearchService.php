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
     * Whitelist the filter keys allowed to be saved from the request.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function filtersFromInput(array $input): array
    {
        $out = [];
        foreach (['tables', 'users', 'facets'] as $k) {
            $v = array_values(array_filter(array_map('strval', (array) ($input[$k] ?? [])), fn ($s) => $s !== ''));
            if (!empty($v)) {
                $out[$k] = $v;
            }
        }
        foreach (['date_from', 'date_to'] as $k) {
            $v = (string) ($input[$k] ?? '');
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        $ranges = (array) ($input['range'] ?? []);
        foreach ($ranges as $field => $r) {
            if (!is_array($r)) {
                continue;
            }
            foreach (['from', 'to'] as $side) {
                $v = (string) ($r[$side] ?? '');
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
     * @param array<string,array> $ctx
     * @return array{params:array<string,mixed>, dropped:array<int,string>}
     */
    public static function sanitizeWith(array $stored, array $ctx): array
    {
        $params = [];
        $dropped = [];

        // tables: keep only tables that still exist + still authorized.
        foreach ((array) ($stored['tables'] ?? []) as $t) {
            if (in_array((string) $t, $ctx['tables'] ?? [], true)) {
                $params['tables'][] = (string) $t;
            } else {
                $dropped[] = 'table:' . $t;
            }
        }

        // dates: keep if parseable.
        foreach (['date_from', 'date_to'] as $k) {
            $v = (string) ($stored[$k] ?? '');
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
        foreach ((array) ($stored['users'] ?? []) as $u) {
            if (in_array((int) $u, $validUsers, true)) {
                $params['users'][] = (int) $u;
            } else {
                $dropped[] = 'user:' . $u;
            }
        }

        // facets: "column=value" token — the column must still exist.
        $validCols = array_map('strval', $ctx['facet_columns'] ?? []);
        foreach ((array) ($stored['facets'] ?? []) as $token) {
            $col = MeiliSearchService::parseFacetToken((string) $token)['col'];
            if (in_array($col, $validCols, true)) {
                $params['facets'][] = (string) $token;
            } else {
                $dropped[] = 'facet:' . $token;
            }
        }

        // range: the n_<col> field must still be declared.
        $validFields = array_map('strval', $ctx['range_fields'] ?? []);
        foreach ((array) ($stored['range'] ?? []) as $field => $r) {
            if (!is_array($r)) {
                continue;
            }
            if (in_array((string) $field, $validFields, true)) {
                foreach (['from', 'to'] as $side) {
                    if (($r[$side] ?? '') !== '') {
                        $params['range'][$field][$side] = (string) $r[$side];
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
            'facet_columns' => static::existingColumnNames($stored['facets'] ?? []),
            'range_fields' => FilterConfig::allRangeFields(),
            'user_ids' => static::existingUserIds($stored['users'] ?? []),
        ]);
    }

    /**
     * Tables that are still searchable + the current user still has view permission.
     *
     * @return array<int,string>
     */
    protected static function searchableTableNames(): array
    {
        try {
            return CustomTable::searchEnabled()->get()
                ->filter(fn ($t) => $t->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE))
                ->pluck('table_name')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * column_name that still exists, among the columns referenced by the facet tokens.
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
        try {
            return \DB::table('custom_columns')->whereIn('column_name', $cols)
                ->distinct()->pluck('column_name')->all();
        } catch (\Throwable $e) {
            return [];
        }
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
            return [];
        }
    }
}
