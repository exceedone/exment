<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Services\Meili\SavedSearchService;

/**
 * Global search header suggestions: a single Meilisearch query, permission
 * filtered, mapped to the same response structure as the original header().
 */
class HeaderSuggester
{
    public function __construct(private MeiliSearchService $service)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function suggest(string $q): array
    {
        $limit = 10;
        // Constrain to tables the user may view, then over-fetch: the row-level
        // scope below can still drop hits, but never a whole table's worth.
        $hits = $this->service->searchHighlighted($q, $limit * 4, [
            'permitted_tables' => SavedSearchService::searchableTableNames(),
        ]);

        // Record permission filter: queries automatically apply the
        // CustomValueModelScope global scope.
        $accessible = $this->accessibleIdSets($hits);

        $results = [];
        foreach (MeiliSearchService::filterAccessibleHits($hits, $accessible, $limit) as $hit) {
            $table = CustomTable::getEloquent(array_get($hit, 'table_name'));
            $label = array_get($hit, 'label');
            // [Highlight] displayed text contains <mark>; the value filled into
            // the input box is the plain label.
            $snippet = array_get($hit, 'snippet') ?: $label;
            $text = self::toHighlightedHtml((string) $snippet);
            $results[] = [
                'value' => $label
                , 'text' => $text
                , 'icon' => array_get($table, 'options.icon')
                , 'table_view_name' => array_get($table, 'table_view_name')
                , 'table_name' => array_get($table, 'table_name')
                , 'value_id' => array_get($hit, 'value_id')
                , 'color' => array_get($table, 'options.color') ?? "#3c8dbc"
                ];
        }

        return $results;
    }

    /**
     * Convert a raw snippet (record content with highlight markers) into safe
     * html: escape everything first, then turn the non-html markers inserted
     * by Meilisearch into <mark> tags. Escaping must come first — the snippet
     * is untrusted record data and is rendered as html by the autocomplete.
     */
    public static function toHighlightedHtml(string $snippet): string
    {
        return str_replace(
            [MeiliSearchService::HIGHLIGHT_PRE, MeiliSearchService::HIGHLIGHT_POST],
            ['<mark>', '</mark>'],
            e($snippet)
        );
    }

    /**
     * For each table appearing in the hits, return the set of record ids the
     * current user can actually view (scoped query).
     *
     * @param array<int,array<string,mixed>> $hits
     * @return array<string,array<int|string,bool>>
     */
    private function accessibleIdSets(array $hits): array
    {
        $idsByTable = [];
        foreach ($hits as $hit) {
            $tn = array_get($hit, 'table_name');
            $vid = array_get($hit, 'value_id');
            if ($tn !== null && $vid !== null) {
                $idsByTable[$tn][] = $vid;
            }
        }

        $accessible = [];
        foreach ($idsByTable as $tableName => $ids) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table || !$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                $accessible[$tableName] = [];
                continue;
            }
            $allowed = getModelName($table)::whereIn('id', array_values(array_unique($ids)))
                ->pluck('id')
                ->all();
            $accessible[$tableName] = array_fill_keys($allowed, true);
        }

        return $accessible;
    }
}
