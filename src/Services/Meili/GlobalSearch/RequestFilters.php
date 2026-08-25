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
        if (is_string($from) && $from !== '' && ($ts = self::boundary($from, false)) !== null) {
            $filters['date_from'] = $ts;
        }
        $to = $request->input('date_to');
        if (is_string($to) && $to !== '' && ($ts = self::boundary($to, true)) !== null) {
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
                $out[$field][$k] = is_numeric($v) ? ($v + 0) : self::rangeBound((string) $v, $k === 'to');
            }
        }
        if (!empty($out)) {
            $filters['ranges'] = $out;
        }

        return $filters;
    }

    /**
     * Read a param as a string. `?query[]=x` makes input() return an array, and
     * casting that to string is an E_WARNING - which Laravel turns into an
     * ErrorException, so a crafted URL would 500 the page.
     */
    public static function str(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Read a param as a list of strings, dropping anything not scalar.
     *
     * @return array<int,string>
     */
    public static function strList(Request $request, string $key): array
    {
        $values = $request->input($key, []);
        if (is_scalar($values)) {
            $values = [$values];
        }
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_filter($values, 'is_scalar')),
            fn ($v) => $v !== ''
        ));
    }

    /**
     * Bound of a range[n_<table>::<col>] box. A time column is indexed as
     * seconds since midnight (DocumentMapper::rangeValue), so "10:30" must be
     * converted the same way - strtotime() would turn it into today's unix
     * timestamp and the filter would match nothing.
     *
     * Only the range boxes go through here: date_from/date_to filter created_at,
     * which really is a unix timestamp.
     */
    private static function rangeBound(string $value, bool $upper): ?int
    {
        $seconds = DocumentMapper::timeOfDaySeconds($value);
        if ($seconds === null) {
            return self::boundary($value, $upper);
        }

        // "to 10:30" must cover 10:30:00-10:30:59, like a date bound covers the
        // whole day; an explicit "10:30:45" is already exact.
        $hasSeconds = (bool) preg_match('/^\d{1,2}:\d{2}:\d{2}$/', trim($value));

        return ($upper && !$hasSeconds) ? $seconds + 59 : $seconds;
    }

    private static function boundary(string $value, bool $upper): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $upper ? ' 23:59:59' : ' 00:00:00';
        }

        $ts = strtotime($value);

        return $ts === false ? null : $ts;
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
