<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Grid\Linker;
use ExmentAdminCore\Admin\Widgets\Table as WidgetTable;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\Meili\GlobalSearch\AppliedChips;
use Exceedone\Exment\Services\Meili\GlobalSearch\FilterSidebar;
use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use Exceedone\Exment\Services\Meili\GlobalSearch\ResultPaginator;
use Exceedone\Exment\Services\Meili\GlobalSearch\SavedSearchBar;
use Exceedone\Exment\Services\Meili\GlobalSearch\SearchExporter;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Http\Request;

/**
 * Global search via Meilisearch: the entry points used by SearchController
 * (header suggestions, results page, per-table pagination, filter sidebar,
 * saved-search bar, applied chips, export).
 *
 * This trait is a thin facade: each entry method delegates to a focused class
 * under Services\Meili\GlobalSearch. When Meilisearch is disabled or fails, the
 * controller falls back to the original MySQL logic.
 *
 * meilisearch-php is a "suggest" dependency, so meiliEnabled() also checks that
 * the client class exists.
 */
trait MeiliGlobalSearchTrait
{
    protected function meiliEnabled(): bool
    {
        return boolval(config('meilisearch.global_search'))
            && class_exists(\Meilisearch\Client::class);
    }

    protected function makeService(): MeiliSearchService
    {
        return new MeiliSearchService(
            MeiliClientFactory::make(),
            config('meilisearch.index')
        );
    }

    /**
     * Log when we must fall back from Meilisearch to MySQL.
     */
    protected function logMeiliFallback(string $where, \Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning(
            "[Meili] {$where} fallback to MySQL: " . $e->getMessage()
        );
    }

    /**
     * Global search header suggestions (same response structure as header()).
     *
     * @param string $q
     * @return array
     */
    protected function headerByMeilisearch($q)
    {
        return (new HeaderSuggester($this->makeService()))->suggest((string) $q);
    }

    /**
     * Render the left column of the search results page (unified filter sidebar).
     */
    protected function renderFilterSidebarHtml(Request $request, string $q): string
    {
        return (new FilterSidebar($this->makeService()))->render($request, $q);
    }

    /**
     * Render the saved-search quickbar (chips + save modal share targets).
     */
    protected function renderSavedSearchBarHtml(Request $request): string
    {
        return SavedSearchBar::render($request);
    }

    /**
     * "Applied filters" chips above the results + clear-all link.
     *
     * @return array{chips:array<int,array{label:string,url:string}>,clearUrl:string}
     */
    protected function appliedChips(Request $request): array
    {
        return AppliedChips::build($request);
    }

    /**
     * Sort key from the request: null = relevance (default).
     */
    protected function sortFromRequest(Request $request): ?string
    {
        return RequestFilters::sort($request);
    }

    /**
     * Export the search results of ONE table (CSV/XLSX). Reuses Exment's export
     * engine fed with Meili-derived, permission-matched ids.
     *
     * @param \Exceedone\Exment\Model\CustomTable $custom_table
     * @return void  (FormatBase::sendResponse() sends the file then exits)
     */
    protected function exportByMeili(Request $request, string $q, $custom_table)
    {
        return (new SearchExporter($this->makeService()))->export($request, $q, $custom_table);
    }

    /**
     * Body of getListItem paginated via Meilisearch. Glue between the Meili
     * paginator (ResultPaginator) and Exment's grid/view rendering; kept separate
     * so the caller can wrap it with the MySQL fallback.
     */
    protected function getListItemByMeili(Request $request, $q, $table_name)
    {
        $custom_table = CustomTable::getEloquent($table_name);
        if (empty($custom_table)) {
            return [];
        }

        $boxHeader = $this->getBoxHeaderHtml($custom_table, ['query' => $q]);

        $paged = (new ResultPaginator($this->makeService()))->paginate($custom_table, $q, $request);
        $paginate = $paged['paginator'];
        // capped = the count is a floor (the over-fetch cap was reached) -> the UI
        // renders it as "N+" instead of an exact number.
        $capped = $paged['capped'];
        $paginate->setPath(admin_urls('search', 'list') . '?' . http_build_query(['query' => $q, 'table_name' => $table_name]));
        // Pagination links are generated server-side (data-ajax-link), so they
        // must carry the current filters; otherwise moving to page 2 would drop
        // date/users/facets/range/sort.
        $paginate->appends(array_filter($request->only(['date_from', 'date_to', 'users', 'facets', 'range', 'sort'])));
        $datalist = $paginate->items();

        if (count($datalist) == 0) {
            return [
                'table_name' => array_get($custom_table, 'table_name'),
                'header' => $boxHeader,
                'body' => exmtrans('search.no_result'),
                // total = number of PERMISSION-MATCHED records (scope-filtered), used to
                // show the count on the header box + hide boxes with no results.
                'total' => 0,
                'total_capped' => false
            ];
        }
        $links = $paginate->links('exment::search.links')->toHtml();
        $view = CustomView::getAllData($custom_table);

        list($headers, $bodies, $columnStyles, $columnClasses) = $view->convertDataTable($datalist, [
            'action_callback' => function (&$link, $custom_table, $data) {
                if (count($custom_table->getRelationTables()) > 0) {
                    $link .= (new Linker())
                    ->url($data->getRelationSearchUrl(true))
                    ->icon('fa-compress')
                    ->tooltip(exmtrans('search.header_relation'));
                }
            }
        ]);
        $table = (new WidgetTable($headers, $bodies))->class('table table-hover')
            ->setColumnStyle($columnStyles)
            ->setColumnClasses($columnClasses);

        return [
            'table_name' => array_get($custom_table, 'table_name'),
            'header' => $boxHeader,
            'body' => $table->render(),
            'footer' => $links,
            'total' => $paginate->total(),
            'total_capped' => $capped
        ];
    }
}
