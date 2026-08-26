<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Services\Meili\SavedSearchService;
use Illuminate\Http\Request;

/**
 * Build the "applied filters" chips above the results: one chip per active
 * condition with a URL to remove just that condition, plus a clear-all link.
 */
class AppliedChips
{
    /**
     * Query params are attacker-controlled: `?tables[][]=x` nests an array, and
     * strval() on it is an E_WARNING that Laravel turns into an ErrorException.
     *
     * @param  mixed  $value
     * @return array<int,string>
     */
    private static function stringList($value): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_filter($value, 'is_scalar')),
            fn ($v) => $v !== ''
        ));
    }

    /**
     * @return array{chips:array<int,array{label:string,url:string}>,clearUrl:string}
     */
    public static function build(Request $request): array
    {
        $qs = $request->query();
        unset($qs['ss'], $qs['back'], $qs['page']);
        $base = admin_url('search');
        $url = fn (array $params) => $base . '?' . http_build_query($params);

        $chips = [];

        // Labels are resolved from raw query keys, so scope them to the tables
        // the user may view: an unpermitted key falls back to the raw token
        // instead of leaking a hidden table's/column's display name.
        $permitted = SavedSearchService::searchableTableNames();

        // Tables
        $tables = self::stringList($qs['tables'] ?? null);
        foreach ($tables as $tn) {
            $rest = $qs;
            $rest['tables'] = array_values(array_diff($tables, [$tn]));
            if (empty($rest['tables'])) {
                unset($rest['tables']);
            }
            $table = in_array($tn, $permitted, true) ? CustomTable::getEloquent($tn) : null;
            $chips[] = [
                'label' => exmtrans('custom_table.table') . ': ' . ($table ? ($table->table_view_name ?? $tn) : $tn),
                'url' => $url($rest),
            ];
        }

        // Status/category (facets)
        $facets = self::stringList($qs['facets'] ?? null);
        if (!empty($facets)) {
            $cols = array_map(fn ($t) => MeiliSearchService::parseFacetToken($t)['col'], $facets);
            // Only resolve column view-names for tables the user may view; alias
            // labels are global admin search vocabulary, so they stay unscoped.
            $safeCols = LabelResolver::permittedPrefixes($cols, $permitted);
            $labels = FilterConfig::aliasLabels()
                + LabelResolver::settingViewLabels($safeCols)
                + LabelResolver::resolveColumnLabels($safeCols);
            foreach ($facets as $token) {
                $p = MeiliSearchService::parseFacetToken($token);
                $rest = $qs;
                $rest['facets'] = array_values(array_diff($facets, [$token]));
                if (empty($rest['facets'])) {
                    unset($rest['facets']);
                }
                $chips[] = [
                    'label' => ($labels[$p['col']] ?? $p['col']) . ': ' . $p['value'],
                    'url' => $url($rest),
                ];
            }
        }

        // Creator
        $users = self::stringList($qs['users'] ?? null);
        if (!empty($users)) {
            $names = LabelResolver::resolveUserNames(array_map('intval', $users));
            foreach ($users as $uid) {
                $rest = $qs;
                $rest['users'] = array_values(array_diff($users, [$uid]));
                if (empty($rest['users'])) {
                    unset($rest['users']);
                }
                $chips[] = [
                    'label' => exmtrans('common.created_user') . ': ' . ($names[(int) $uid] ?? ('#' . $uid)),
                    'url' => $url($rest),
                ];
            }
        }

        // Created date
        foreach (['date_from' => 'search.filter_date_from', 'date_to' => 'search.filter_date_to'] as $key => $trans) {
            if (empty($qs[$key])) {
                continue;
            }
            $rest = $qs;
            unset($rest[$key]);
            $chips[] = [
                'label' => exmtrans('common.created_at') . ' ' . exmtrans($trans) . ': ' . (is_scalar($qs[$key]) ? $qs[$key] : ''),
                'url' => $url($rest),
            ];
        }

        // Number/date range (range[n_col][from|to])
        $ranges = (array) ($qs['range'] ?? []);
        if (!empty($ranges)) {
            $cols = array_map(fn ($f) => preg_replace('/^n_/', '', (string) $f), array_keys($ranges));
            $safeCols = LabelResolver::permittedPrefixes($cols, $permitted);
            $labels = LabelResolver::settingViewLabels($safeCols) + LabelResolver::resolveColumnLabels($safeCols);
            foreach ($ranges as $field => $r) {
                $from = RequestFilters::rangeSide($r, 'from');
                $to = RequestFilters::rangeSide($r, 'to');
                if ($from === '' && $to === '') {
                    continue;
                }
                $rest = $qs;
                unset($rest['range'][$field]);
                if (empty($rest['range'])) {
                    unset($rest['range']);
                }
                $col = preg_replace('/^n_/', '', (string) $field);
                $chips[] = [
                    'label' => ($labels[$col] ?? $col) . ': ' . $from . ' - ' . $to,
                    'url' => $url($rest),
                ];
            }
        }

        return [
            'chips' => $chips,
            'clearUrl' => $url(array_filter($qs, fn ($k) => $k === 'query', ARRAY_FILTER_USE_KEY)),
        ];
    }
}
