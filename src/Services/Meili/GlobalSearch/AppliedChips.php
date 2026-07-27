<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Http\Request;

/**
 * Build the "applied filters" chips above the results: one chip per active
 * condition with a URL to remove just that condition, plus a clear-all link.
 */
class AppliedChips
{
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

        // Tables
        $tables = array_values(array_filter(array_map('strval', (array) ($qs['tables'] ?? []))));
        foreach ($tables as $tn) {
            $rest = $qs;
            $rest['tables'] = array_values(array_diff($tables, [$tn]));
            if (empty($rest['tables'])) {
                unset($rest['tables']);
            }
            $table = CustomTable::getEloquent($tn);
            $chips[] = [
                'label' => exmtrans('custom_table.table') . ': ' . ($table->table_view_name ?? $tn),
                'url' => $url($rest),
            ];
        }

        // Status/category (facets)
        $facets = array_values(array_filter(array_map('strval', (array) ($qs['facets'] ?? []))));
        if (!empty($facets)) {
            $cols = array_map(fn ($t) => MeiliSearchService::parseFacetToken($t)['col'], $facets);
            $labels = FilterConfig::aliasLabels()
                + LabelResolver::settingViewLabels($cols)
                + LabelResolver::resolveColumnLabels($cols);
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
        $users = array_values(array_filter(array_map('strval', (array) ($qs['users'] ?? []))));
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
                'label' => exmtrans('common.created_at') . ' ' . exmtrans($trans) . ': ' . $qs[$key],
                'url' => $url($rest),
            ];
        }

        // Number/date range (range[n_col][from|to])
        $ranges = (array) ($qs['range'] ?? []);
        if (!empty($ranges)) {
            $cols = array_map(fn ($f) => preg_replace('/^n_/', '', (string) $f), array_keys($ranges));
            $labels = LabelResolver::settingViewLabels($cols) + LabelResolver::resolveColumnLabels($cols);
            foreach ($ranges as $field => $r) {
                $from = (string) ($r['from'] ?? '');
                $to = (string) ($r['to'] ?? '');
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
