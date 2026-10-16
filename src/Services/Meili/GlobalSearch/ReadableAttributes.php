<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Services\Meili\SavedSearchService;

/**
 * Which parts of a table's documents the current viewer may be matched on.
 *
 * Documents are built by a job with no logged-in user, so a linked record is
 * indexed under its real label (DocumentMapper). On screen the same value is
 * replaced with the "no permission" text, so without this a viewer could type a
 * hidden record's name and learn from the hits alone which records point at it
 * - and the same for a hidden user or organization.
 *
 * Meilisearch does the filtering: the readable paths are handed to it as
 * attributesToSearchOn. Filtering hits afterwards instead would have to find the
 * keyword back in the permitted text, which typo tolerance and synonyms make
 * impossible without dropping correct hits.
 *
 * Only usable on a query already narrowed to ONE table: the index stores fields
 * under their bare column name (fields.user exists in many tables), so a path
 * excluded for one table would be excluded for all. The queries that span tables
 * keep their own guards - see HeaderSuggester and FilterSidebar.
 */
class ReadableAttributes
{
    /** The table's name, not record data: a hit on it reveals no hidden value. */
    public const ALWAYS_READABLE = 'table_label';

    /**
     * Attribute paths this viewer may be matched on for $table.
     *
     * Null when nothing in the table is hidden from them (the common case, and
     * always so for an administrator): the caller then sends no
     * attributesToSearchOn and search behaves exactly as before.
     *
     * @param  mixed  $table  CustomTable, id or table_name
     * @return array<int,string>|null
     */
    public static function forTable($table): ?array
    {
        $table = CustomTable::getEloquent($table);
        if (!$table) {
            return null;
        }

        // Per request, not per call: one search page asks for the same table
        // once per result box and again for the sidebar recount.
        return System::requestSession('meili_readable_attributes_' . $table->id, function () use ($table) {
            try {
                $user = \Exment::user();
                if (!$user || $user->isAdministrator()) {
                    return null;
                }

                $columns = collect($table->getFreewordSearchColumns());
                $hidden = $columns->filter(fn ($c) => self::isHiddenColumn($c))->pluck('column_name')->all();
                $labelHidden = self::labelMayExposeHidden($table);

                if (empty($hidden) && !$labelHidden) {
                    return null;
                }

                $paths = [self::ALWAYS_READABLE];
                if (!$labelHidden) {
                    $paths[] = 'label';
                }
                foreach ($columns as $column) {
                    if (!in_array($column->column_name, $hidden, true)) {
                        $paths[] = 'fields.' . $column->column_name;
                    }
                }

                return array_values(array_unique($paths));
            } catch (\Throwable $e) {
                // Fail closed: a table whose permissions cannot be read must not
                // be searched on its record data.
                \Illuminate\Support\Facades\Log::warning(
                    "[Meili] readable attributes of '{$table->table_name}' unavailable, its content is left out of the search: " . $e->getMessage()
                );

                return [self::ALWAYS_READABLE];
            }
        });
    }

    /**
     * Those of $tableNames that need a search of their own because part of
     * their content is hidden from this viewer.
     *
     * @param  array<int,string>  $tableNames
     * @return array<int,string>
     */
    public static function restrictedTables(array $tableNames): array
    {
        return array_values(array_filter($tableNames, fn ($name) => self::forTable($name) !== null));
    }

    /**
     * Facet prefixes built from a column whose values this viewer may not read.
     *
     * Dropped from both the sidebar and the filters: the values are the hidden
     * labels themselves, and `facets[]=table::col=name` in the URL would
     * otherwise answer "does a record point at <name>?" without ever showing it.
     *
     * @param  array<int,string>  $tableNames
     * @return array<int,string>
     */
    public static function hiddenFacetPrefixes(array $tableNames): array
    {
        $out = [];
        foreach ($tableNames as $tableName) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table) {
                continue;
            }
            try {
                $aliases = FilterConfig::aliasMap($table);
                foreach (FilterConfig::equalityColumns($table) as $column) {
                    if (!self::isHiddenColumn($column)) {
                        continue;
                    }
                    // The prefix DocumentMapper wrote the token under.
                    $out[] = $aliases[$column->column_name]
                        ?? DocumentMapper::qualifyColumn((string) $table->table_name, (string) $column->column_name);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "[Meili] facet permissions of '{$tableName}' unavailable: " . $e->getMessage()
                );
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The one hidden-prefix list for this viewer, over every table they may
     * search.
     *
     * Deliberately not narrowed per screen: the result page, the sidebar and
     * the applied chips must agree on which facets exist. Narrowed to the table
     * being searched, an alias shared with another table's hidden column would
     * still filter the results while the sidebar and the chips showed nothing
     * for it.
     *
     * @return array<int,string>
     */
    public static function hiddenFacetPrefixesForViewer(): array
    {
        return System::requestSession(
            'meili_hidden_facet_prefixes',
            fn () => self::hiddenFacetPrefixes(SavedSearchService::searchableTableNames())
        );
    }

    /**
     * Drop every facet token belonging to one of $hiddenPrefixes (pure logic;
     * the caller that already knows the prefixes reuses them instead of looking
     * them up a second time).
     *
     * @param  array<string,mixed>  $filters
     * @param  array<int,string>  $hiddenPrefixes
     * @return array<string,mixed>
     */
    public static function stripFacetPrefixes(array $filters, array $hiddenPrefixes): array
    {
        if (empty($filters['facets']) || empty($hiddenPrefixes)) {
            return $filters;
        }

        $kept = array_values(array_filter(
            (array) $filters['facets'],
            fn ($token) => !in_array(MeiliSearchService::parseFacetToken((string) $token)['col'], $hiddenPrefixes, true)
        ));

        if (empty($kept)) {
            unset($filters['facets']);

            return $filters;
        }

        $filters['facets'] = $kept;

        return $filters;
    }

    /**
     * A linked column (select_table / user / organization) whose value the
     * viewer may not read in full: the document keeps the real label, the
     * screen shows "no permission".
     *
     * Checked to any depth. A column pointing at a table whose every row is
     * visible can still carry a hidden name, because that table's own label may
     * be built from a link of its own - and the document stores the label as it
     * was resolved at index time, with no user and therefore no filtering.
     *
     * @param  mixed  $column  CustomColumn
     * @param  array<int,bool>  $seen  target table ids already on this path
     */
    private static function isHiddenColumn($column, array $seen = []): bool
    {
        if (!$column || !ColumnType::isSelectTable($column->column_type)) {
            return false;
        }

        $target = $column->select_target_table;

        // No target left to check against -> assume the worst.
        if (!$target || !SavedSearchService::canViewAllRows($target)) {
            return true;
        }

        // Tables can reference each other: a table already on this path has
        // been checked, so stop rather than recurse forever.
        $id = (int) $target->id;
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;

        return self::labelMayExposeHidden($target, $seen);
    }

    /**
     * Whether the table's label is (or may be) built from a hidden column.
     *
     * @param  mixed  $table  CustomTable
     * @param  array<int,bool>  $seen  target table ids already on this path
     */
    private static function labelMayExposeHidden($table, array $seen = []): bool
    {
        $labelColumns = $table->getLabelColumns();

        // expart_mode label format: a free-form string that may name any column.
        if (is_string($labelColumns)) {
            return true;
        }

        // No label definition -> the first column is the label
        // (CustomValue::getBasicLabel).
        $columns = empty($labelColumns) || count($labelColumns) === 0
            ? [$table->custom_columns_cache->first()]
            : collect($labelColumns)->map(fn ($c) => CustomColumn::getEloquent($c->table_label_id))->all();

        foreach ($columns as $column) {
            if (self::isHiddenColumn($column, $seen)) {
                return true;
            }
        }

        return false;
    }
}
