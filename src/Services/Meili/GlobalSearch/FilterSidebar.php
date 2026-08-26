<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliFilterSetting;
use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Services\Meili\SavedSearchService;
use Illuminate\Http\Request;

/**
 * Render the left column of the search results page: the unified filter sidebar
 * (table facets, equality facets, creator, created date range, numeric ranges)
 * with live disjunctive-faceting counts.
 *
 * @phpstan-type FacetItem array{value:string,label:string,count:int,checked:bool}
 * @phpstan-type FacetGroup array{title:string,name:string,new:bool,items:array<int,FacetItem>}
 */
class FilterSidebar
{
    public function __construct(private MeiliSearchService $service)
    {
    }

    public function render(Request $request, string $q): string
    {
        $filters = RequestFilters::parse($request);
        $selectedUsers = $filters['users'] ?? [];
        $selectedTables = RequestFilters::strList($request, 'tables');
        if (!empty($selectedTables)) {
            $filters['tables'] = $selectedTables;
        }

        $filters['permitted_tables'] = SavedSearchService::facetableTableNames();

        // Disjunctive faceting: each group's counts reflect ALL current filters
        // except that group's own selection (so options in a ticked group do not
        // disappear). One merged query for groups with no selection; groups with
        // a selection are re-queried separately (usually <=2 extra queries).
        try {
            $dist = $this->service->searchDistributions($q, $filters, ['table_name', 'facets', 'f_user']);
        } catch (\Throwable $e) {
            // An empty sidebar is the visible symptom; log so the cause is findable.
            \Illuminate\Support\Facades\Log::warning('[Meili] facet distribution unavailable: ' . $e->getMessage());
            $dist = [];
        }

        // One box per filter group: tables -> equality facets -> creator.
        $checkboxGroups = array_values(array_filter(array_merge(
            [$this->tableFacetGroup($q, $filters, $dist['table_name'] ?? [], $selectedTables)],
            $this->facetValueGroups($q, $filters, $dist['facets'] ?? []),
            [$this->creatorFacetGroup($q, $filters, $dist['f_user'] ?? [], $selectedUsers)]
        )));

        $action = admin_url('search');

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        return view('exment::search.filter-sidebar', [
            'query' => $q,
            'action' => $action,
            'clearUrl' => $action . '?query=' . urlencode($q),
            'checkboxGroups' => $checkboxGroups,
            'dateFrom' => is_string($dateFrom) ? $dateFrom : '',
            'dateTo' => is_string($dateTo) ? $dateTo : '',
            'ranges' => $this->rangeInputs($request),
            // Submitting the sidebar must not silently reset the chosen sort.
            'sort' => RequestFilters::sort($request),
        ])->render();
    }

    /**
     * "Table" checkbox group: one checkbox per table with its hit count.
     * Nothing checked = show every table (default); checked = only those tables.
     * Counts follow the current filters (minus the table selection itself).
     *
     * @param array<string,mixed> $filters
     * @param array<string,int> $baseDist  table_name distribution under the full filters
     * @param array<int,string> $selectedTables
     * @return FacetGroup|null
     */
    private function tableFacetGroup(string $q, array $filters, array $baseDist, array $selectedTables): ?array
    {
        // Group with its own selection -> recount with filters minus 'tables' (disjunctive).
        $dist = $baseDist;
        if (!empty($selectedTables)) {
            try {
                $dist = $this->service->searchDistributions(
                    $q,
                    MeiliSearchService::filtersWithoutKey($filters, 'tables'),
                    ['table_name']
                )['table_name'] ?? [];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Meili] table facet recount unavailable: ' . $e->getMessage());
                $dist = [];
            }
        }
        arsort($dist);

        // A ticked table with 0 results is still shown (so the user can untick it).
        foreach ($selectedTables as $t) {
            if (!array_key_exists($t, $dist)) {
                $dist[$t] = 0;
            }
        }

        $selectAll = empty($selectedTables);
        $items = [];
        foreach ($dist as $tableName => $count) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table || !$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }
            $items[] = [
                'value' => (string) $tableName,
                'label' => $table->table_view_name ?? $tableName,
                'count' => (int) $count,
                'checked' => $selectAll || in_array((string) $tableName, $selectedTables, true),
            ];
        }

        if (empty($items)) {
            return null;
        }

        return ['title' => exmtrans('custom_table.table'), 'name' => 'tables[]', 'new' => false, 'items' => $items];
    }

    /**
     * "Created user" checkbox group: one checkbox per creator with its hit
     * count. Counts follow the current filters (minus the user selection itself).
     *
     * @param array<string,mixed> $filters
     * @param array<string,int> $baseDist  f_user distribution under the full filters
     * @param array<int,int> $selectedUsers
     * @return FacetGroup|null
     */
    private function creatorFacetGroup(string $q, array $filters, array $baseDist, array $selectedUsers): ?array
    {
        // Group with its own selection -> recount with filters minus 'users' (disjunctive).
        $dist = $baseDist;
        if (!empty($selectedUsers)) {
            try {
                $dist = $this->service->searchDistributions(
                    $q,
                    MeiliSearchService::filtersWithoutKey($filters, 'users'),
                    ['f_user']
                )['f_user'] ?? [];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Meili] creator facet recount unavailable: ' . $e->getMessage());
                $dist = [];
            }
        }
        arsort($dist);
        // Any org with more creators than this loses the rest, so keep it in step
        // with the equality groups rather than a tighter number of its own.
        $dist = array_slice($dist, 0, max(1, (int) config('meilisearch.filter.max_values_per_group', 20)), true);

        // A ticked user outside the top values / with 0 results is still shown.
        foreach ($selectedUsers as $uid) {
            if (!array_key_exists($uid, $dist) && !array_key_exists((string) $uid, $dist)) {
                $dist[$uid] = 0;
            }
        }
        if (empty($dist)) {
            return null;
        }

        $names = LabelResolver::resolveUserNames(array_keys($dist));
        $items = [];
        foreach ($dist as $uid => $count) {
            $items[] = [
                'value' => (string) $uid,
                'label' => $names[$uid] ?? ('#' . $uid),
                'count' => (int) $count,
                'checked' => in_array((int) $uid, $selectedUsers, true),
            ];
        }

        return ['title' => exmtrans('common.created_user'), 'name' => 'users[]', 'new' => true, 'items' => $items];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,int> $baseDist  facets distribution under the full filters
     * @return array<int,FacetGroup>
     */
    private function facetValueGroups(string $q, array $filters, array $baseDist): array
    {
        $selected = array_map('strval', $filters['facets'] ?? []);

        // Selected tokens, grouped by column.
        $selectedByCol = [];
        foreach ($selected as $t) {
            $selectedByCol[MeiliSearchService::parseFacetToken($t)['col']][] = $t;
        }

        arsort($baseDist);
        $groups = [];
        foreach ($baseDist as $token => $count) {
            $p = MeiliSearchService::parseFacetToken((string) $token);
            $groups[$p['col']][(string) $token] = (int) $count;
        }
        // Groups with more results come first (groups with 1-2 stray values go last).
        uasort($groups, fn ($a, $b) => array_sum($b) <=> array_sum($a));

        // Column with its own selection -> recount with filters minus that column's OWN tokens.
        foreach (array_keys($selectedByCol) as $col) {
            try {
                $dist = $this->service->searchDistributions(
                    $q,
                    MeiliSearchService::filtersWithoutFacetColumn($filters, $col),
                    ['facets']
                )['facets'] ?? [];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Meili] facet group recount unavailable: ' . $e->getMessage());
                continue;
            }
            arsort($dist);
            $colDist = [];
            foreach ($dist as $token => $count) {
                if (MeiliSearchService::parseFacetToken((string) $token)['col'] === $col) {
                    $colDist[(string) $token] = (int) $count;
                }
            }
            $groups[$col] = $colDist ?: ($groups[$col] ?? []);
        }

        if (empty($groups)) {
            return [];
        }

        $maxGroups = max(1, (int) config('meilisearch.filter.max_groups', 12));
        $maxVals = max(1, (int) config('meilisearch.filter.max_values_per_group', 8));
        // Group label: alias (merged group) takes priority first, then the column's
        // view_label, finally fall back to the column display name. An alias column
        // token has key = alias so only aliasLabels matches; a normal column token
        // matches the latter two sources.
        $aliasLabels = FilterConfig::aliasLabels();
        $labels = $aliasLabels
            + LabelResolver::settingViewLabels(array_keys($groups))
            + LabelResolver::resolveColumnLabels(array_keys($groups));
        // Multiple tables may have columns with the same display name (e.g. "Status") ->
        // append the table name so the user can tell which group belongs to which
        // table. An alias has no view_label -> the title-building code below falls
        // back to the alias string itself via "?? $col".
        $labels = LabelResolver::disambiguate($labels);

        $out = [];
        foreach (array_slice($groups, 0, $maxGroups, true) as $col => $tokenCounts) {
            $tokenCounts = array_slice($tokenCounts, 0, $maxVals, true);
            // A ticked token is always shown (even with 0 results / outside the top).
            foreach (($selectedByCol[$col] ?? []) as $t) {
                if (!array_key_exists($t, $tokenCounts)) {
                    $tokenCounts[$t] = 0;
                }
            }
            $items = [];
            foreach ($tokenCounts as $token => $count) {
                $items[] = [
                    'value' => (string) $token,
                    'label' => MeiliSearchService::parseFacetToken((string) $token)['value'],
                    'count' => (int) $count,
                    'checked' => in_array((string) $token, $selected, true),
                ];
            }
            $out[] = ['title' => $labels[$col] ?? $col, 'name' => 'facets[]', 'new' => true, 'items' => $items];
        }

        return $out;
    }

    /**
     * Min/max input definitions for the range columns configured by the admin
     * (across all tables).
     *
     * @return array<int,array{label:string,field:string,type:string,from:string,to:string}>
     */
    private function rangeInputs(Request $request): array
    {
        try {
            $settings = MeiliFilterSetting::with('custom_table')
                ->where('filter_type', 'range')
                ->where('mode', 'include')
                ->where('enabled', 1)->get();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] range filter settings unavailable: ' . $e->getMessage());
            return [];
        }
        if ($settings->isEmpty()) {
            return [];
        }

        $req = (array) $request->input('range', []);

        // Resolve each setting's column through CustomColumn (cached per table)
        $viewable = SavedSearchService::searchableTableNames();

        $resolved = $settings->map(function ($s) use ($viewable) {
            $table = $s->custom_table;
            if (!$table || !in_array($table->table_name, $viewable, true)) {
                return null;
            }
            $column = CustomColumn::getEloquent($s->column_name, $table);
            if (!$column || !DocumentMapper::supportsRange((string) $column->column_type)) {
                return null;
            }

            return ['setting' => $s, 'table' => $table, 'column' => $column];
        })->filter()->values();

        // Labels appearing on more than one table get the table name appended.
        $duplicateLabels = $resolved
            ->map(fn ($r) => $r['setting']->view_label ?: $r['column']->column_view_name)
            ->filter()
            ->duplicates()
            ->values();

        $out = [];
        $seen = [];
        foreach ($resolved as $r) {
            $setting = $r['setting'];
            $column = $r['column'];
            $tableName = $r['table']->table_name;

            $field = DocumentMapper::rangeField((string) $tableName, (string) $column->column_name);
            if (isset($seen[$field])) {
                continue;
            }
            $seen[$field] = true;

            $label = $setting->view_label ?: ($column->column_view_name ?? $column->column_name);
            // Same column label in two tables -> say which table it belongs to.
            if ($duplicateLabels->contains($label)) {
                $label .= ' (' . ($r['table']->table_view_name ?: $tableName) . ')';
            }

            $out[] = [
                'label' => $label,
                'field' => $field,
                'type' => DocumentMapper::rangeInputType((string) $column->column_type),
                'from' => RequestFilters::rangeSide($req[$field] ?? null, 'from'),
                'to' => RequestFilters::rangeSide($req[$field] ?? null, 'to'),
            ];
        }

        return $out;
    }
}
