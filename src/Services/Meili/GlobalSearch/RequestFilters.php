<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Illuminate\Http\Request;

/**
 * Parse the generic filter/sort params from the search request into the
 * internal filter array the MeiliSearchService understands.
 *
 * The param contract (query · tables · date_from/to · users · facets · range ·
 * sort) is the single source of truth shared by the results page, saved search
 * and export, so parsing lives in one place.
 */
class RequestFilters
{
    /**
     * Read filters from the request:
     * ['date_from'=>unix, 'date_to'=>unix, 'users'=>int[], 'facets'=>string[], 'ranges'=>...].
     *
     * @return array<string,mixed>
     */
    public static function parse(Request $request): array
    {
        $filters = [];

        // Params are attacker-controlled: `?date_from[]=x` turns any input
        // into an array, so type-check every value before using it as a string
        // (concat/strtotime on an array would 500 the request).
        $from = $request->input('date_from');
        if (is_string($from) && $from !== '' && ($ts = strtotime($from . ' 00:00:00')) !== false) {
            $filters['date_from'] = $ts;
        }
        $to = $request->input('date_to');
        if (is_string($to) && $to !== '' && ($ts = strtotime($to . ' 23:59:59')) !== false) {
            $filters['date_to'] = $ts;
        }

        $users = $request->input('users');
        if (is_string($users)) {
            $users = array_filter(explode(',', $users), fn ($v) => $v !== '');
        }
        if (!empty($users) && is_array($users)) {
            $users = array_filter($users, 'is_scalar');
            if (!empty($users)) {
                $filters['users'] = array_map('intval', $users);
            }
        }

        $facets = $request->input('facets');
        if (is_string($facets)) {
            $facets = array_filter(explode("\n", $facets), fn ($v) => $v !== '');
        }
        if (!empty($facets) && is_array($facets)) {
            $facets = array_values(array_filter($facets, 'is_string'));
            if (!empty($facets)) {
                $filters['facets'] = $facets;
            }
        }

        // range[n_col][from|to]: date -> unix, number -> number.
        $ranges = (array) $request->input('range', []);
        $out = [];
        foreach ($ranges as $field => $r) {
            if (!is_string($field) || !preg_match(DocumentMapper::RANGE_FIELD_PATTERN, $field) || !is_array($r)) {
                continue;
            }
            foreach (['from', 'to'] as $k) {
                $v = $r[$k] ?? null;
                if (!is_scalar($v) || $v === '') {
                    continue;
                }
                $out[$field][$k] = is_numeric($v) ? ($v + 0) : (strtotime((string) $v) ?: null);
            }
        }
        if (!empty($out)) {
            $filters['ranges'] = $out;
        }

        return $filters;
    }

    /**
     * Sort key from the request: null = by relevance (default).
     * Only accepts valid values -> no garbage pushed into Meili's sort expression.
     */
    public static function sort(Request $request): ?string
    {
        $sort = $request->input('sort', '');

        return is_string($sort) && in_array($sort, ['newest', 'oldest'], true) ? $sort : null;
    }
}
