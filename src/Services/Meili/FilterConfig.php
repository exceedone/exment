<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Model\MeiliFilterSetting;
use Exceedone\Exment\Model\System;

/**
 * Configure which columns are filterable (facets).
 *
 * mode (config meilisearch.filter.mode):
 *  - 'override' (default): auto by column_type + include (admin-added) - exclude (admin-removed).
 *  - 'manual'  : only admin-declared columns (include).
 *  - 'auto'    : only by column_type (internal, not exposed in the UI).
 *
 * include/exclude are stored in the `mode` column of meili_filter_settings.
 */
class FilterConfig
{
    public const SETTINGS_KEY = 'meili_filter_settings_rows';

    /**
     * Every filter setting row, read once per request: the sync path asks for each table.
     *
     * @return \Illuminate\Support\Collection<int,MeiliFilterSetting>
     */
    public static function settingRows()
    {
        return System::requestSession(self::SETTINGS_KEY, fn () => MeiliFilterSetting::orderBy('order')->get());
    }

    /**
     * The column_types treated as equality filters (poured into facets[]).
     *
     * @return array<int,string>
     */
    public static function equalityTypes(): array
    {
        return config('meilisearch.filter.equality_column_types', ['select', 'select_valtext', 'yesno']);
    }

    /**
     * Whether a column is an equality filter axis (by type + file exclusion).
     *
     * @param array<int,string> $allowedTypes
     * @param array<int,string> $excludes  'table.column' entries to exclude
     */
    public static function isEqualityColumn(string $columnType, string $tableColumnKey, array $allowedTypes, array $excludes): bool
    {
        return in_array($columnType, $allowedTypes, true) && !in_array($tableColumnKey, $excludes, true);
    }

    /**
     * The final equality columns of a table according to mode.
     *
     * @param \Exceedone\Exment\Model\CustomTable $table
     * @return \Illuminate\Support\Collection<int,\Exceedone\Exment\Model\CustomColumn>
     */
    public static function equalityColumns($table)
    {
        $mode = config('meilisearch.filter.mode', 'override');
        $includes = self::settingColumns($table, 'equality', 'include');

        if ($mode === 'manual') {
            return $includes;
        }

        $auto = self::autoEqualityColumns($table);
        if ($mode === 'auto') {
            return $auto;
        }

        // override: auto + include - exclude.
        $excludeNames = self::settingColumnNames($table, 'equality', 'exclude');

        return $auto->concat($includes)
            ->unique('column_name')
            ->reject(fn ($c) => in_array($c->column_name, $excludeNames, true))
            ->values();
    }

    /**
     * [auto] Equality columns picked automatically by column_type (+ exclusions in the config file).
     *
     * @param \Exceedone\Exment\Model\CustomTable $table
     * @return \Illuminate\Support\Collection<int,\Exceedone\Exment\Model\CustomColumn>
     */
    public static function autoEqualityColumns($table)
    {
        $allowed = self::equalityTypes();
        $excludes = config('meilisearch.filter.equality_exclude', []);

        return collect($table->custom_columns)->filter(function ($c) use ($table, $allowed, $excludes) {
            return self::isEqualityColumn(
                (string) $c->column_type,
                $table->table_name . '.' . $c->column_name,
                $allowed,
                $excludes
            );
        })->values();
    }

    /**
     * Map column -> alias for a table: [column_name => alias].
     * A column with an alias is indexed with the alias as its token prefix (instead of the column name),
     * so multiple columns with the same meaning merge into a single filter group.
     *
     * @param \Exceedone\Exment\Model\CustomTable|string $table
     * @return array<string,string>
     */
    public static function aliasMap($table): array
    {
        $table = \Exceedone\Exment\Model\CustomTable::getEloquent($table);
        if (!$table) {
            return [];
        }
        try {
            return self::settingRows()
                ->filter(fn ($r) => (int) $r->custom_table_id === (int) $table->id
                    && $r->filter_type === 'equality'
                    && (string) $r->alias !== ''
                    && boolval($r->enabled))
                ->pluck('alias', 'column_name')
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] filter aliases unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Display labels by alias (system-wide): [alias => view_label].
     * Used to name merged filter groups; falls back to the alias when there is no view_label.
     *
     * @return array<string,string>
     */
    public static function aliasLabels(): array
    {
        try {
            // By id: with several rows per alias the last one wins, as the unsorted query did.
            return self::settingRows()
                ->sortBy('id')
                ->filter(fn ($r) => (string) $r->alias !== '' && (string) $r->view_label !== '')
                ->pluck('view_label', 'alias')
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] alias labels unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Range columns (number/date) — always from admin include (there is no auto range).
     *
     * @param \Exceedone\Exment\Model\CustomTable $table
     * @return \Illuminate\Support\Collection<int,\Exceedone\Exment\Model\CustomColumn>
     */
    public static function rangeColumns($table)
    {
        // Rows saved before the type check exist; drop them here too, or the
        // sidebar renders a min/max box that can never match a document.
        return self::settingColumns($table, 'range', 'include')
            ->filter(fn ($c) => DocumentMapper::supportsRange((string) $c->column_type))
            ->values();
    }

    /**
     * The n_<col> numeric field names of all included range columns (system-wide).
     *
     * @return array<int,string>
     */
    /**
     * Every alias currently configured, system-wide.
     *
     * An alias is used as a facet prefix in place of "table::column", so it is
     * the one bare (unqualified) prefix shape that is still legitimate. Callers
     * validating a facet token need this list to tell an alias apart from a
     * stale pre-qualification token.
     *
     * @return array<int,string>
     */
    public static function allAliases(): array
    {
        try {
            return self::settingRows()
                ->filter(fn ($r) => (string) $r->alias !== '' && boolval($r->enabled))
                ->pluck('alias')
                ->unique()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] alias list unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Every range attribute declared filterable in the index, table-qualified.
     *
     * @return array<int,string>
     */
    public static function allRangeFields(): array
    {
        try {
            // Built from rangeColumns(), the sidebar's own source, so a field is
            // listed only while its column still exists and can hold a range.
            // Reading the settings rows alone kept columns an admin had deleted:
            // Exment drops the column but not its row here, so a saved search
            // naming that field passed sanitize() and returned nothing, silently.
            $tableIds = self::settingRows()
                ->filter(fn ($r) => $r->filter_type === 'range' && $r->mode === 'include' && boolval($r->enabled))
                ->pluck('custom_table_id')
                ->unique()
                ->values()
                ->all();

            $fields = [];
            foreach ($tableIds as $tableId) {
                $table = \Exceedone\Exment\Model\CustomTable::getEloquent($tableId);
                if (!$table) {
                    continue;
                }
                // The field name carries the table, so two tables owning a
                // same-named range column stay separate axes.
                foreach (self::rangeColumns($table) as $column) {
                    $fields[] = DocumentMapper::rangeField((string) $table->table_name, (string) $column->column_name);
                }
            }

            return array_values(array_unique($fields));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] range fields unavailable: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * CustomColumns per config row (filter_type + include/exclude) of a table.
     *
     * @param \Exceedone\Exment\Model\CustomTable|string $table
     * @return \Illuminate\Support\Collection<int,\Exceedone\Exment\Model\CustomColumn>
     */
    public static function settingColumns($table, string $filterType, string $rowMode)
    {
        $table = \Exceedone\Exment\Model\CustomTable::getEloquent($table);
        if (!$table) {
            return collect();
        }

        $names = self::settingColumnNames($table, $filterType, $rowMode);
        if (empty($names)) {
            return collect();
        }

        $byName = collect($table->custom_columns)->keyBy('column_name');

        return collect($names)->map(fn ($n) => $byName->get($n))->filter()->values();
    }

    /**
     * List of column_name per config row of a table.
     *
     * @param \Exceedone\Exment\Model\CustomTable|string $table
     * @return array<int,string>
     */
    public static function settingColumnNames($table, string $filterType, string $rowMode): array
    {
        $table = \Exceedone\Exment\Model\CustomTable::getEloquent($table);
        if (!$table) {
            return [];
        }

        return self::settingRows()
            ->filter(fn ($r) => (int) $r->custom_table_id === (int) $table->id
                && $r->filter_type === $filterType
                && $r->mode === $rowMode
                && boolval($r->enabled))
            ->pluck('column_name')
            ->values()
            ->all();
    }
}
