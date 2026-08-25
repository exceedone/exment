<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Build a LengthAwarePaginator of CustomValue models from Meilisearch results:
 * over-fetch candidate ids, filter permissions via the Exment global scope, then
 * page the permission-matched ids (keeping Meili's relevance order).
 */
class ResultPaginator
{
    public function __construct(private MeiliSearchService $service)
    {
    }

    /**
     * @param \Exceedone\Exment\Model\CustomTable $custom_table
     * @return array{paginator:\Illuminate\Pagination\LengthAwarePaginator<int,\Exceedone\Exment\Model\CustomValue>, capped:bool}
     *   capped = the candidate set hit permission_scan_cap, so the real total is
     *   at least the reported one (UI shows it as "N+").
     */
    public function paginate($custom_table, string $q, Request $request): array
    {
        $perPage = System::datalist_pager_count() ?? 5;
        $page = (int) $request->input('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        // 1. Over-fetch candidate ids (by relevance) up to the cap, to filter permissions + paginate.
        // max(1): a cap of 0 would ask Meilisearch for zero hits AND make the
        // "capped" test below always true, so the page would report "0+".
        $cap = max(1, (int) config('meilisearch.permission_scan_cap', 1000));
        $result = $this->service->searchTablePaginated(
            $q,
            $custom_table->table_name,
            $cap,
            1,
            RequestFilters::parse($request),
            RequestFilters::sort($request)
        );
        $candidateIds = $result['ids'];
        // Hit the over-fetch cap -> Meili had (at least) as many matches as we asked
        // for, so the reported total is a floor, not the exact count.
        $capped = count($candidateIds) >= $cap;

        // 2. Filter permissions once: the query has the global scope -> the set of ids actually viewable.
        $accessibleIds = empty($candidateIds)
            ? []
            : getModelName($custom_table)::whereIn('id', $candidateIds)->pluck('id')->all();

        // 3. Count + page the PERMISSION-MATCHED ids (keeping Meili's order).
        $paged = MeiliSearchService::pageAccessibleIds($candidateIds, $accessibleIds, $page, $perPage);

        $models = collect();
        if (!empty($paged['pageIds'])) {
            $loaded = getModelName($custom_table)::whereIn('id', $paged['pageIds'])->get()->keyBy('id');
            $models = collect($paged['pageIds'])->map(fn ($id) => $loaded->get($id))->filter()->values();
        }

        return [
            'paginator' => new LengthAwarePaginator(
                $models,
                $paged['total'],
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath()]
            ),
            'capped' => $capped,
        ];
    }
}
