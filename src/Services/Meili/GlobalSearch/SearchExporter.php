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
     * @return void  (FormatBase::sendResponse() sends the file then exits)
     */
    public function export(Request $request, string $q, $custom_table)
    {
        $cap = (int) config('meilisearch.permission_scan_cap', 1000);
        $result = $this->service->searchTablePaginated(
            $q,
            $custom_table->table_name,
            $cap,
            1,
            RequestFilters::parse($request)
        );
        $ids = $result['ids'];

        $classname = getModelName($custom_table);
        $grid = new \Encore\Admin\Grid(new $classname());
        $grid->model()->usePaginate(false);
        // No hits -> empty whereIn so the output file has only a header (not the whole table).
        $grid->model()->whereIn('id', empty($ids) ? [-1] : $ids);

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
