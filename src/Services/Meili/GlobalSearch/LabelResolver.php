<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliFilterSetting;

/**
 * Resolve display labels for filter groups/chips: column view names, admin
 * view_labels, table names (for disambiguation) and user names. Shared by the
 * filter sidebar and the applied-filter chips.
 */
class LabelResolver
{
    /**
     * column_name -> display label (view_label) configured by the admin on the
     * filter settings screen, if any.
     *
     * @param array<int,string> $columnNames
     * @return array<string,string>
     */
    public static function settingViewLabels(array $columnNames): array
    {
        if (empty($columnNames)) {
            return [];
        }
        try {
            return MeiliFilterSetting::whereIn('column_name', $columnNames)
                ->whereNotNull('view_label')
                ->where('view_label', '<>', '')
                ->pluck('view_label', 'column_name')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve column_name -> column_view_name (across all tables, first match wins).
     *
     * @param array<int,string> $columnNames
     * @return array<string,string>
     */
    public static function resolveColumnLabels(array $columnNames): array
    {
        if (empty($columnNames)) {
            return [];
        }
        try {
            return \DB::table('custom_columns')
                ->whereIn('column_name', $columnNames)
                ->pluck('column_view_name', 'column_name')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve column_name -> table_view_name of the table containing the column (first match).
     *
     * @param array<int,string> $columnNames
     * @return array<string,string>
     */
    public static function resolveColumnTables(array $columnNames): array
    {
        if (empty($columnNames)) {
            return [];
        }
        try {
            return \DB::table('custom_columns')
                ->join('custom_tables', 'custom_tables.id', '=', 'custom_columns.custom_table_id')
                ->whereIn('custom_columns.column_name', $columnNames)
                ->pluck('custom_tables.table_view_name', 'custom_columns.column_name')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
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
            return [];
        }
    }
}
