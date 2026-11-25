<?php

namespace Exceedone\Exment\Services\Search;

use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RelationTable;
use Illuminate\Support\Collection;

/**
 * What the related search of a table shows, and in which order (#1570).
 *
 * The related search lists every table that points at the record - by a
 * column that selects it, or as a child or a many-to-many relation. The table
 * setting "関連検索" keeps one row per such table: shown or not, and its place
 * in the list. Nothing kept means the list as it always was; a table that
 * became related after the setting was saved is shown, at the end.
 *
 * Kept in the table options as relation_search: [{key, hidden}], in order.
 */
class RelationSearchSetting
{
    public const OPTION_NAME = 'relation_search';

    /**
     * The key of one related table in the setting: "<table id>-<search type>".
     *
     * @param RelationTable $relationTable
     * @return string
     */
    public static function keyOf(RelationTable $relationTable): string
    {
        return strval($relationTable->table->id) . '-' . strval($relationTable->searchType);
    }

    /**
     * Every table the related search of this table can show, each once, in
     * the order it shows them when nothing is set.
     *
     * @param CustomTable $custom_table
     * @param bool $checkPermission
     * @return Collection<string, RelationTable> keyed by keyOf()
     */
    public static function relationTables(CustomTable $custom_table, bool $checkPermission = true): Collection
    {
        $results = collect();
        foreach ($custom_table->getRelationTables($checkPermission) as $relationTable) {
            $key = static::keyOf($relationTable);
            if (!$results->has($key)) {
                $results->put($key, $relationTable);
            }
        }

        return $results;
    }

    /**
     * The setting as it is kept: key => hidden, in the order kept.
     *
     * @param CustomTable $custom_table
     * @return array<string, bool>
     */
    public static function stored(CustomTable $custom_table): array
    {
        $stored = [];
        foreach ((array)$custom_table->getOption(static::OPTION_NAME, []) as $row) {
            $key = strval(array_get((array)$row, 'key'));
            if ($key === '' || array_key_exists($key, $stored)) {
                continue;
            }
            $stored[$key] = boolval(array_get((array)$row, 'hidden'));
        }

        return $stored;
    }

    /**
     * Every related table with whether it is hidden, in the order of the
     * setting: the kept ones first, then the ones it does not know yet.
     *
     * @param CustomTable $custom_table
     * @param bool $checkPermission
     * @return array<int, array{key: string, relation: RelationTable, hidden: bool}>
     */
    public static function rows(CustomTable $custom_table, bool $checkPermission = true): array
    {
        $relationTables = static::relationTables($custom_table, $checkPermission);
        $stored = static::stored($custom_table);

        $rows = [];
        foreach ($stored as $key => $hidden) {
            if ($relationTables->has($key)) {
                $rows[] = ['key' => $key, 'relation' => $relationTables->get($key), 'hidden' => $hidden];
            }
        }
        foreach ($relationTables as $key => $relationTable) {
            if (!array_key_exists($key, $stored)) {
                $rows[] = ['key' => strval($key), 'relation' => $relationTable, 'hidden' => false];
            }
        }

        return $rows;
    }

    /**
     * The related tables the related search shows, in order.
     *
     * @param CustomTable $custom_table
     * @return array<int, RelationTable>
     */
    public static function shown(CustomTable $custom_table): array
    {
        $shown = [];
        foreach (static::rows($custom_table) as $row) {
            if (!$row['hidden']) {
                $shown[] = $row['relation'];
            }
        }

        return $shown;
    }

    /**
     * The name of a related table in the setting: the table, and how it is related.
     *
     * @param RelationTable $relationTable
     * @return string
     */
    public static function labelOf(RelationTable $relationTable): string
    {
        $type = match (intval($relationTable->searchType)) {
            SearchType::SELECT_TABLE => exmtrans('custom_table.relation_search.select_table'),
            SearchType::ONE_TO_MANY => exmtrans('custom_table.relation_search.one_to_many'),
            SearchType::MANY_TO_MANY => exmtrans('custom_table.relation_search.many_to_many'),
            default => '',
        };
        $name = strval($relationTable->table->table_view_name);

        return $type === '' ? $name : $name . ' (' . $type . ')';
    }

    /**
     * The setting as it is sent from the table setting, cleaned for keeping:
     * only keys of tables that are related, each once, in the order sent.
     *
     * @param CustomTable $custom_table
     * @param mixed $input list of [key, hidden]
     * @return array<int, array{key: string, hidden: int}>
     */
    public static function clean(CustomTable $custom_table, $input): array
    {
        $known = static::relationTables($custom_table, false);
        $rows = [];
        $seen = [];
        foreach ((array)$input as $row) {
            $key = strval(array_get((array)$row, 'key'));
            if (!$known->has($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['key' => $key, 'hidden' => boolval(array_get((array)$row, 'hidden')) ? 1 : 0];
        }

        return $rows;
    }
}
