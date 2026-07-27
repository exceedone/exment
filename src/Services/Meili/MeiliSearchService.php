<?php

namespace Exceedone\Exment\Services\Meili;

use Meilisearch\Client;

/**
 * Global search via Meilisearch: ONE query to the combined index "exment_global".
 */
class MeiliSearchService
{
    public function __construct(
        private Client $client,
        private string $indexName
    ) {
    }

    /**
     * Whether Meilisearch is available (used for health-check / fallback decision).
     * Catches every error -> returns false instead of throwing.
     */
    public function isAvailable(): bool
    {
        try {
            return $this->client->isHealthy();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Apply the matching strategy from config into the search options ('all' to match
     * the AND semantics of the original MySQL path; a query-time option only).
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function applyMatchingStrategy(array $options): array
    {
        $strategy = config('meilisearch.matching_strategy');
        if (!empty($strategy)) {
            $options['matchingStrategy'] = $strategy;
        }

        return $options;
    }

    /**
     * System-wide search.
     *
     * @return array<int,array{table_name:?string,value_id:mixed,label:?string}>
     */
    public function search(string $q, int $limit = 10, ?string $tableName = null): array
    {
        $result = $this->client->index($this->indexName)
            ->search($q, $this->applyMatchingStrategy(self::buildSearchOptions($limit, $tableName)));

        return self::mapHits($result->getHits());
    }

    /**
     * Search within ONE table, with pagination (for the full results page).
     *
     * @return array{ids:array<int,mixed>, total:int}
     */
    public function searchTablePaginated(string $q, string $tableName, int $perPage, int $page, array $filters = [], ?string $sort = null): array
    {
        $result = $this->client->index($this->indexName)
            ->search($q, $this->applyMatchingStrategy(self::buildTableSearchOptions($tableName, $perPage, $page, $filters, $sort)));

        $ids = array_map(fn ($hit) => $hit['value_id'] ?? null, $result->getHits());

        return ['ids' => array_values(array_filter($ids, fn ($id) => $id !== null)), 'total' => (int) $result->getTotalHits()];
    }

    /**
     * Build the paginated per-table search parameters (pure logic).
     * $filters: ['date_from'=>unix, 'date_to'=>unix, 'users'=>int[]].
     *
     * @return array<string,mixed>
     */
    public static function buildTableSearchOptions(string $tableName, int $perPage, int $page, array $filters = [], ?string $sort = null): array
    {
        $options = [
            'filter' => self::buildFilterExpression($tableName, $filters),
            'hitsPerPage' => $perPage,
            'page' => $page,
        ];

        $sortExpr = self::buildSortExpression($sort);
        if (!empty($sortExpr)) {
            $options['sort'] = $sortExpr;
        }

        return $options;
    }

    /**
     * The UI sort key -> Meili's sort expression.
     * Default (null/'relevance'/invalid) = by relevance: do NOT send
     * 'sort' so Meili uses its ranking rules.
     *
     * @return array<int,string>
     */
    public static function buildSortExpression(?string $sort): array
    {
        switch ($sort) {
            case 'newest':
                return ['f_date:desc'];
            case 'oldest':
                return ['f_date:asc'];
            default:
                return [];
        }
    }

    /**
     * Quote a string value for use inside a Meili filter expression.
     * Backslash MUST be escaped before the quote: a value ending in `\`
     * would otherwise neutralize the closing quote and let the rest of the
     * (user-influenced) value be parsed as raw filter syntax.
     */
    public static function quoteFilterValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Build the Meili filter expression: table + date range + creator.
     * $tableName null -> no table constraint (used for system-wide facets).
     *
     * @param array{date_from?:?int,date_to?:?int,users?:array<int,int>} $filters
     */
    public static function buildFilterExpression(?string $tableName, array $filters): string
    {
        $parts = [];

        if ($tableName !== null && $tableName !== '') {
            $parts[] = 'table_name = ' . self::quoteFilterValue($tableName);
        }

        // Filter by multiple tables: table_name IN [...].
        $tables = $filters['tables'] ?? [];
        if (!empty($tables)) {
            $vals = implode(', ', array_map(
                fn ($t) => self::quoteFilterValue((string) $t),
                $tables
            ));
            $parts[] = 'table_name IN [' . $vals . ']';
        }

        $from = $filters['date_from'] ?? null;
        if ($from !== null && $from !== '') {
            $parts[] = 'f_date >= ' . (int) $from;
        }
        $to = $filters['date_to'] ?? null;
        if ($to !== null && $to !== '') {
            $parts[] = 'f_date <= ' . (int) $to;
        }

        $users = $filters['users'] ?? [];
        if (!empty($users)) {
            $ids = implode(', ', array_map('intval', $users));
            $parts[] = 'f_user IN [' . $ids . ']';
        }

        // [Filter v2] facets: grouped by column -> OR within a column, AND between columns.
        foreach (self::buildFacetClauses($filters['facets'] ?? []) as $clause) {
            $parts[] = $clause;
        }

        // n_<col> >= from AND n_<col> <= to.
        foreach (($filters['ranges'] ?? []) as $field => $r) {
            if (!preg_match('/^n_[A-Za-z0-9_]+$/', (string) $field)) {
                continue;
            }
            $rangeFrom = $r['from'] ?? null;
            $rangeTo = $r['to'] ?? null;
            // floatval instead of arithmetic: a non-numeric string would make
            // `$v + 0` throw a TypeError in PHP 8.
            if ($rangeFrom !== null && $rangeFrom !== '' && is_scalar($rangeFrom)) {
                $parts[] = $field . ' >= ' . floatval($rangeFrom);
            }
            if ($rangeTo !== null && $rangeTo !== '' && is_scalar($rangeTo)) {
                $parts[] = $field . ' <= ' . floatval($rangeTo);
            }
        }

        return implode(' AND ', $parts);
    }

    /**
     * Compare the ids that SHOULD be indexed (from MySQL)
     * against the value_ids currently in the index, and return the drift:
     *  - missing: in MySQL but not in the index (need indexing)
     *  - orphan : in the index but not in MySQL (deleted -> need removing)
     *
     * @param array<int,int|string> $dbIds
     * @param array<int,int|string> $indexIds
     * @return array{missing:array<int,int|string>, orphan:array<int,int|string>}
     */
    public static function diffIds(array $dbIds, array $indexIds): array
    {
        $db = array_fill_keys(array_map('strval', $dbIds), true);
        $idx = array_fill_keys(array_map('strval', $indexIds), true);

        $missing = [];
        foreach ($dbIds as $id) {
            if (!isset($idx[(string) $id])) {
                $missing[] = $id;
            }
        }

        $orphan = [];
        foreach ($indexIds as $id) {
            if (!isset($db[(string) $id])) {
                $orphan[] = $id;
            }
        }

        return ['missing' => $missing, 'orphan' => $orphan];
    }

    /**
     * All value_ids currently in the index for one table (paginated via
     * getDocuments with a filter, so it is NOT capped by maxTotalHits like
     * search()).
     *
     * @return array<int,mixed>
     */
    public function indexedValueIds(string $tableName, int $pageSize = 1000): array
    {
        $ids = [];
        $offset = 0;

        while (true) {
            $query = (new \Meilisearch\Contracts\DocumentsQuery())
                ->setFilter(['table_name = ' . self::quoteFilterValue($tableName)])
                ->setFields(['value_id'])
                ->setLimit($pageSize)
                ->setOffset($offset);

            $results = $this->client->index($this->indexName)->getDocuments($query);
            $rows = $results->getResults();
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                if (isset($row['value_id'])) {
                    $ids[] = $row['value_id'];
                }
            }
            $offset += $pageSize;
            if ($offset >= $results->getTotal()) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Delete documents from the index by their value_ids for one table.
     */
    public function deleteByValueIds(string $tableName, array $valueIds, DocumentMapper $mapper): void
    {
        if (empty($valueIds)) {
            return;
        }
        $docIds = array_map(fn ($id) => $mapper->makeDocumentId($tableName, $id), $valueIds);
        $task = $this->client->index($this->indexName)->deleteDocuments($docIds);
        $this->client->waitForTask($task['taskUid'], 60000);
    }

    /**
     * From candidate ids (Meili order) + the set of accessible ids,
     * return the current page + total (permission-matched, keeping Meili's order).
     *
     * @param array<int,mixed> $candidateIds  ids in Meili's relevance order
     * @param array<int,mixed> $accessibleIds the set of allowed ids (returned by the scoped query)
     * @return array{pageIds:array<int,mixed>, total:int}
     */
    public static function pageAccessibleIds(array $candidateIds, array $accessibleIds, int $page, int $perPage): array
    {
        $set = array_flip($accessibleIds);
        $ordered = array_values(array_filter($candidateIds, fn ($id) => isset($set[$id])));

        $offset = max(0, ($page - 1) * $perPage);

        return [
            'pageIds' => array_slice($ordered, $offset, $perPage),
            'total' => count($ordered),
        ];
    }

    /**
     * Remove a group's OWN selection from the filters
     * (disjunctive faceting): the count for group X = the whole filter minus column X's tokens,
     * so that options in the currently-ticked group do not disappear.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function filtersWithoutFacetColumn(array $filters, string $col): array
    {
        $tokens = array_values(array_filter(
            $filters['facets'] ?? [],
            fn ($t) => self::parseFacetToken((string) $t)['col'] !== $col
        ));

        if (empty($tokens)) {
            unset($filters['facets']);
        } else {
            $filters['facets'] = $tokens;
        }

        return $filters;
    }

    /**
     * Remove an entire filter key ('tables'/'users'...) —
     * used for non-facet groups (table, creator).
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function filtersWithoutKey(array $filters, string $key): array
    {
        unset($filters[$key]);

        return $filters;
    }

    /**
     * [Sidebar count] ONE query fetching the facet distribution for multiple attributes
     * (table_name/facets/f_user) with the current filter. Returns the raw map
     * attribute => [value => count].
     *
     * @param array<int,string> $attributes
     * @return array<string,array<string,int>>
     */
    public function searchDistributions(string $q, array $filters, array $attributes): array
    {
        $opts = ['limit' => 0, 'facets' => $attributes];
        $expr = self::buildFilterExpression(null, $filters);
        if ($expr !== '') {
            $opts['filter'] = $expr;
        }

        $result = $this->client->index($this->indexName)->search($q, $this->applyMatchingStrategy($opts));

        return $result->getFacetDistribution() ?? [];
    }

    /**
     * Split a "column=value" token at the first '='.
     *
     * @return array{col:string,value:string}
     */
    public static function parseFacetToken(string $token): array
    {
        $pos = strpos($token, '=');
        if ($pos === false) {
            return ['col' => $token, 'value' => ''];
        }

        return ['col' => substr($token, 0, $pos), 'value' => substr($token, $pos + 1)];
    }

    /**
     * Group tokens by column -> one `facets IN [...]` clause per column.
     * (OR within the same column, AND between columns when joined by AND in buildFilterExpression.)
     *
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    public static function buildFacetClauses(array $tokens): array
    {
        $byCol = [];
        foreach ($tokens as $t) {
            $col = self::parseFacetToken($t)['col'];
            $byCol[$col][] = self::quoteFilterValue($t);
        }

        $clauses = [];
        foreach ($byCol as $vals) {
            $clauses[] = 'facets IN [' . implode(', ', $vals) . ']';
        }

        return $clauses;
    }

    /**
     * Build the search parameters for Meilisearch (pure logic).
     *
     * @return array<string,mixed>
     */
    public static function buildSearchOptions(int $limit, ?string $tableName): array
    {
        $options = ['limit' => $limit];

        if ($tableName !== null && $tableName !== '') {
            $options['filter'] = 'table_name = ' . self::quoteFilterValue($tableName);
        }

        return $options;
    }

    /**
     * Reduce Meilisearch hits down to just the needed fields (pure logic).
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array<int,array{table_name:?string,value_id:mixed,label:?string}>
     */
    public static function mapHits(array $hits): array
    {
        return array_map(function ($hit) {
            return [
                'table_name' => $hit['table_name'] ?? null,
                'value_id' => $hit['value_id'] ?? null,
                'label' => $hit['label'] ?? null,
            ];
        }, $hits);
    }

    // ---- Highlight (keyword emphasis) ----

    /**
     * Highlight markers inserted by Meilisearch. Deliberately NOT html tags:
     * the snippet is record content (untrusted), so the consumer must
     * html-escape the whole snippet first and only then convert these
     * markers into real tags. See HeaderSuggester::toHighlightedHtml().
     */
    public const HIGHLIGHT_PRE = '__EXM_HL__';
    public const HIGHLIGHT_POST = '__/EXM_HL__';

    /**
     * Search with highlighting: also returns 'snippet' (the excerpt containing the highlighted keyword).
     *
     * @return array<int,array{table_name:?string,value_id:mixed,label:?string,snippet:string}>
     */
    public function searchHighlighted(string $q, int $limit = 10): array
    {
        $result = $this->client->index($this->indexName)->search($q, $this->applyMatchingStrategy([
            'limit' => $limit,
            'attributesToHighlight' => ['*'],
            'highlightPreTag' => self::HIGHLIGHT_PRE,
            'highlightPostTag' => self::HIGHLIGHT_POST,
        ]));

        return array_map(function ($hit) {
            return [
                'table_name' => $hit['table_name'] ?? null,
                'value_id' => $hit['value_id'] ?? null,
                'label' => $hit['label'] ?? null,
                'snippet' => self::pickHighlightedSnippet($hit['_formatted'] ?? []),
            ];
        }, $result->getHits());
    }

    /**
     * Pick the highlight excerpt to display (pure logic): prefer label, and if label
     * does not match, take the first field containing the highlighted keyword.
     *
     * @param  array<string,mixed>  $formatted  the hit's _formatted
     */
    public static function pickHighlightedSnippet(array $formatted, string $preTag = self::HIGHLIGHT_PRE): string
    {
        $label = (string) ($formatted['label'] ?? '');
        if ($label !== '' && strpos($label, $preTag) !== false) {
            return $label;
        }

        foreach (($formatted['fields'] ?? []) as $value) {
            if (is_string($value) && strpos($value, $preTag) !== false) {
                return $value;
            }
        }

        return $label;
    }

    // ---- Facet (count by table) ----

    /**
     * Count the number of results per table (table_name) for the keyword $q.
     *
     * @return array<int,array{table:string,count:int}>
     */
    public function searchFacets(string $q): array
    {
        $result = $this->client->index($this->indexName)->search($q, $this->applyMatchingStrategy([
            'limit' => 0,
            'facets' => ['table_name'],
        ]));

        $distribution = $result->getFacetDistribution()['table_name'] ?? [];

        return self::sortFacets($distribution);
    }

    /**
     * Count the number of results per creator (f_user) for the keyword $q,
     * applying the current filter (e.g. date range). Returns [user_id => count] in descending order.
     *
     * @param array{date_from?:?int,date_to?:?int,users?:array<int,int>} $filters
     * @return array<int|string,int>
     */
    public function searchUserFacets(string $q, array $filters = []): array
    {
        $opts = ['limit' => 0, 'facets' => ['f_user']];
        $expr = self::buildFilterExpression(null, $filters);
        if ($expr !== '') {
            $opts['filter'] = $expr;
        }

        $result = $this->client->index($this->indexName)->search($q, $this->applyMatchingStrategy($opts));
        $dist = $result->getFacetDistribution()['f_user'] ?? [];
        arsort($dist);

        return $dist;
    }

    /**
     * [Filter v2] Distribution of facets["column=value"] for the keyword $q (applying date/user filters),
     * returns [token => count] in descending order. Used to build the status/classification checkbox groups.
     *
     * @param array{date_from?:?int,date_to?:?int,users?:array<int,int>} $filters
     * @return array<string,int>
     */
    public function searchFacetValues(string $q, ?string $tableName, array $filters = []): array
    {
        $opts = ['limit' => 0, 'facets' => ['facets']];
        $expr = self::buildFilterExpression($tableName, $filters);
        if ($expr !== '') {
            $opts['filter'] = $expr;
        }

        $result = $this->client->index($this->indexName)->search($q, $this->applyMatchingStrategy($opts));
        $dist = $result->getFacetDistribution()['facets'] ?? [];
        arsort($dist);

        return $dist;
    }

    /**
     * Sort the facet distribution by count in descending order (pure logic).
     *
     * @param  array<string,int>  $distribution  [table_name => count]
     * @return array<int,array{table:string,count:int}>
     */
    public static function sortFacets(array $distribution): array
    {
        $facets = [];
        foreach ($distribution as $table => $count) {
            $facets[] = ['table' => (string) $table, 'count' => (int) $count];
        }

        usort($facets, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return $facets;
    }

    /**
     * Keep only hits whose value_id is in that table's set of accessible ids
     * (the set returned by Exment's global-scope query), preserving order,
     * truncated to $limit.
     *
     * @param array<int,array<string,mixed>> $hits
     * @param array<string,array<int|string,bool>> $accessibleSets  table_name => [id => true]
     * @return array<int,array<string,mixed>>
     */
    public static function filterAccessibleHits(array $hits, array $accessibleSets, int $limit): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $table = $hit['table_name'] ?? null;
            $id = $hit['value_id'] ?? null;
            if ($table === null || $id === null) {
                continue;
            }
            if (!isset($accessibleSets[$table][$id])) {
                continue;
            }
            $out[] = $hit;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
