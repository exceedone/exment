<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliFilterSetting;
use Exceedone\Exment\Services\Meili\DocumentMapper;

/**
 * Resolve display labels for filter groups/chips: column view names, admin
 * view_labels, table names (for disambiguation) and user names. Shared by the
 * filter sidebar and the applied-filter chips.
 */
class LabelResolver
{
    /**
     * Keep only the prefixes whose label is safe to show the current user: a
     * qualified "table::column" whose table the user may view. Bare prefixes
     * (aliases / legacy tokens with no table qualifier) are dropped so the
     * caller falls back to the raw token instead of leaking a hidden label.
     *
     * @param array<int,string> $prefixes
     * @param array<int,string> $permittedTables table_names the user may view
     * @return array<int,string>
     */
    public static function permittedPrefixes(array $prefixes, array $permittedTables): array
    {
        return array_values(array_filter($prefixes, function ($prefix) use ($permittedTables) {
            $table = DocumentMapper::splitColumnPrefix((string) $prefix)['table'];
            return $table !== null && in_array($table, $permittedTables, true);
        }));
    }

    /**
     * Facet prefix -> display label (view_label) configured by the admin on the
     * filter settings screen, if any. Keyed back by the original prefix.
     *
     * @param array<int,string> $prefixes
     * @return array<string,string>
     */
    public static function settingViewLabels(array $prefixes): array
    {
        if (empty($prefixes)) {
            return [];
        }

        $parts = [];
        foreach ($prefixes as $prefix) {
            $parts[(string) $prefix] = DocumentMapper::splitColumnPrefix((string) $prefix);
        }

        try {
            $rows = MeiliFilterSetting::with('custom_table')
                ->whereIn('column_name', array_column($parts, 'column'))
                ->whereNotNull('view_label')
                ->where('view_label', '<>', '')
                ->get();
        } catch (\Throwable $e) {
            // Pre-migration this is expected; still log so a real failure does
            // not just show up as unlabelled filter groups.
            \Illuminate\Support\Facades\Log::warning('[Meili] setting labels unavailable: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($parts as $prefix => $part) {
            foreach ($rows as $row) {
                if ($row->column_name !== $part['column']) {
                    continue;
                }
                // Qualified -> only the setting row of the owning table applies.
                if ($part['table'] !== null
                    && (string) array_get($row, 'custom_table.table_name') !== $part['table']) {
                    continue;
                }
                $out[$prefix] = (string) $row->view_label;
                break;
            }
        }

        return $out;
    }

    /**
     * Resolve facet prefix -> column_view_name.
     * A qualified prefix ("table::column") resolves against that exact table;
     * a bare one (an alias, or a legacy token) falls back to first match.
     *
     * @param array<int,string> $prefixes
     * @return array<string,string>
     */
    public static function resolveColumnLabels(array $prefixes): array
    {
        return self::resolvePrefixes($prefixes, 'custom_columns.column_view_name');
    }

    /**
     * Resolve facet prefix -> table_view_name of the owning table.
     *
     * @param array<int,string> $prefixes
     * @return array<string,string>
     */
    public static function resolveColumnTables(array $prefixes): array
    {
        return self::resolvePrefixes($prefixes, 'custom_tables.table_view_name');
    }

    /**
     * Shared lookup for the two resolvers above: read one column off
     * custom_columns joined to custom_tables, keyed back by the ORIGINAL prefix
     * so callers never have to know about the qualifier.
     *
     * @param array<int,string> $prefixes
     * @param string $select fully qualified column to read as the label
     * @return array<string,string>
     */
    private static function resolvePrefixes(array $prefixes, string $select): array
    {
        if (empty($prefixes)) {
            return [];
        }

        // Split once: qualified prefixes are matched on (table_name, column_name),
        // bare ones on column_name only.
        $parts = [];
        foreach ($prefixes as $prefix) {
            $parts[(string) $prefix] = DocumentMapper::splitColumnPrefix((string) $prefix);
        }

        try {
            $rows = \DB::table('custom_columns')
                ->join('custom_tables', 'custom_tables.id', '=', 'custom_columns.custom_table_id')
                ->whereIn('custom_columns.column_name', array_column($parts, 'column'))
                ->get([
                    'custom_columns.column_name as _column',
                    'custom_tables.table_name as _table',
                    \DB::raw($select . ' as _label'),
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] column labels unavailable: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($parts as $prefix => $part) {
            foreach ($rows as $row) {
                if ($row->_column !== $part['column']) {
                    continue;
                }
                // Qualified -> only the owning table may answer.
                if ($part['table'] !== null && $row->_table !== $part['table']) {
                    continue;
                }
                if ($row->_label !== null && $row->_label !== '') {
                    $out[$prefix] = (string) $row->_label;
                }
                break;
            }
        }

        return $out;
    }

    /**
     * Duplicate group labels (columns in different tables but the same
     * column_view_name) -> append the table name: "Status" + "Status" =>
     * "Status (Contract)" / "Status (Customer)".
     *
     * @param array<string,string> $labels  column_name => label
     * @return array<string,string>
     */
    public static function disambiguate(array $labels): array
    {
        $dups = array_keys(array_filter(array_count_values($labels), fn ($n) => $n > 1));
        if (empty($dups)) {
            return $labels;
        }

        $tables = self::resolveColumnTables(array_keys($labels));
        foreach ($labels as $col => $label) {
            if (in_array($label, $dups, true) && !empty($tables[$col])) {
                $labels[$col] = $label . ' (' . $tables[$col] . ')';
            }
        }

        return $labels;
    }

    /**
     * Resolve user ids -> display names (labels of the user table). Returns [id => name].
     *
     * @param array<int,int|string> $ids
     * @return array<int|string,string>
     */
    public static function resolveUserNames(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        try {
            $userTable = CustomTable::getEloquent(SystemTableName::USER);
            if (!$userTable) {
                return [];
            }
            return getModelName($userTable)::whereIn('id', $ids)->get()
                ->mapWithKeys(fn ($u) => [$u->id => $u->label])->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] user names unavailable: ' . $e->getMessage());
            return [];
        }
    }
}
