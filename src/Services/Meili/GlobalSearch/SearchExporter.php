<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Services\DataImportExport\Actions\Export\ViewAction;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Http\Request;

/**
 * Export the search results of ONE table (CSV/XLSX) via Exment's existing export
 * engine: Meili filters out ids by the current keyword + filters -> inject
 * whereIn(id) into the grid -> ViewAction exports the exact CustomView columns
 * (like the list screen).
 *
 * Permission: the query runs through the global scope so ids without permission
 * drop off by themselves; a user can only export what they can see on screen.
 * Limit: at most permission_scan_cap (default 1000) records per run.
 */
class SearchExporter
{
    public function __construct(private MeiliSearchService $service)
    {
    }

    /**
     * @param \Exceedone\Exment\Model\CustomTable $custom_table
     * @return mixed  Normally never returns: FormatBase::sendResponse() sends the
     *   file and exits. The value is still propagated for the paths that do return.
     */
    public function export(Request $request, string $q, $custom_table)
    {
        // max(1): with a cap of 0 the "too many results" test below would refuse
        // every export, including an empty one.
        $cap = max(1, (int) config('meilisearch.permission_scan_cap', 1000));
        $sort = RequestFilters::sort($request);
        $result = $this->service->searchTablePaginated(
            $q,
            $custom_table->table_name,
            $cap,
            1,
            RequestFilters::parse($request),
            $sort
        );
        $ids = $result['ids'];

        // A full page back means Meilisearch had at least this many matches, so
        // exporting would drop the rest with nothing on the file to say so.
        // Refusing is the only honest option: the index caps at maxTotalHits, so
        // there is no page 2 to fetch.
        if (count($ids) >= $cap) {
            admin_toastr(sprintf(exmtrans('search.export_too_many'), $cap), 'error');
            return back();
        }

        $classname = getModelName($custom_table);
        $grid = new \ExmentAdminCore\Admin\Grid(new $classname());
        $grid->model()->usePaginate(false);
        // No hits -> empty whereIn so the output file has only a header (not the whole table).
        $grid->model()->whereIn('id', empty($ids) ? [-1] : $ids);

        // The grid re-fetches by id and would otherwise use its own default
        // order, losing the sort the user picked on screen. Meili sorts these
        // two on f_date, i.e. created_at.
        if ($sort === 'newest' || $sort === 'oldest') {
            $grid->model()->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc');
        }

        $format = in_array($request->input('format'), ['csv', 'xlsx'], true)
            ? $request->input('format')
            : 'xlsx';

        $service = (new DataImportExportService())
            ->exportAction(new ViewAction([
                'custom_table' => $custom_table,
                'custom_view' => CustomView::getAllData($custom_table),
                'grid' => $grid,
            ]))
            ->format($format)
            ->filebasename($custom_table->table_name);

        return $service->export();
    }
}
