<?php

namespace Exceedone\Exment\Services\Meili;

/**
 * Build the Meilisearch index settings (searchable/filterable/sortable
 * attributes, ranking, typo tolerance, synonyms, stop words) from the
 * meilisearch.settings config plus the dynamic range fields. Pure logic.
 */
class IndexSettings
{
    public const DEFAULT_SEARCHABLE = ['label', 'fields', 'table_label'];

    public const DEFAULT_RANKING = ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'];

    /** Meilisearch's own default; never go below it. */
    public const DEFAULT_MAX_TOTAL_HITS = 1000;

    /** Same idea for facet values: Meilisearch defaults to 100, which is too low here. */
    public const DEFAULT_MAX_FACET_VALUES = 1000;

    /**
     * The settings Meilisearch should hold right now: config file + the
     * dynamic parts (range fields, admin dictionary, permission scan cap).
     * Single source so the three appliers cannot drift apart.
     *
     * @return array<string,mixed>
     */
    public static function fromSystem(): array
    {
        $opts = (array) config('meilisearch.settings', []);
        $opts['range_fields'] = FilterConfig::allRangeFields();
        $opts['max_total_hits'] = (int) config('meilisearch.permission_scan_cap', 0);
        $opts['max_facet_values'] = (int) config('meilisearch.settings.max_facet_values', 0);

        return \Exceedone\Exment\Model\MeiliDictionary::mergeIntoOpts($opts);
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function build(array $opts = []): array
    {
        $rangeFields = $opts['range_fields'] ?? [];
        $filterable = $opts['filterable_attributes'] ?? [];
        // table_name + v1 filter axes (f_date, f_user) + v2 (facets) + range (n_<col>).
        $filterable = array_values(array_unique(array_merge(['table_name', 'f_date', 'f_user', 'facets'], $rangeFields, $filterable)));

        // f_date + the n_<col> fields are sortable for range filtering.
        $sortable = array_values(array_unique(array_merge(['f_date'], $rangeFields, $opts['sortable_attributes'] ?? [])));

        // Meili requires synonyms to be a JSON object. Empty must be {} not []
        // -> cast to stdClass when there are no entries.
        $synonyms = $opts['synonyms'] ?? [];
        if (empty($synonyms)) {
            $synonyms = (object) [];
        }

        // Empty means "not configured", not "configure nothing": ?? alone would
        // let an empty config array through and leave Meilisearch with no
        // searchable attributes (search returns nothing) or no ranking rules.
        $searchable = $opts['searchable_attributes'] ?? [];
        $ranking = $opts['ranking_rules'] ?? [];
        $maxTotalHits = max(self::DEFAULT_MAX_TOTAL_HITS, (int) ($opts['max_total_hits'] ?? 0));
        $maxFacetValues = max(self::DEFAULT_MAX_FACET_VALUES, (int) ($opts['max_facet_values'] ?? 0));

        $locales = array_values($opts['locales'] ?? []);
        $localized = $locales === []
            ? null
            : [['attributePatterns' => ['*'], 'locales' => $locales]];

        return [
            'searchableAttributes' => empty($searchable) ? self::DEFAULT_SEARCHABLE : $searchable,
            'filterableAttributes' => $filterable,
            'sortableAttributes' => $sortable,
            'stopWords' => array_values($opts['stop_words'] ?? []),
            'synonyms' => $synonyms,
            'localizedAttributes' => $localized,
            'rankingRules' => empty($ranking) ? self::DEFAULT_RANKING : $ranking,
            'typoTolerance' => [
                'enabled' => $opts['typo_enabled'] ?? true,
                'minWordSizeForTypos' => [
                    'oneTypo' => (int) ($opts['typo_one'] ?? 5),
                    'twoTypos' => (int) ($opts['typo_two'] ?? 9),
                ],
            ],
            // Meili caps results at 1000 by default; the permission filter
            // over-fetches up to permission_scan_cap, so a larger cap would be
            // silently truncated (and the "N+" indicator lost).
            'pagination' => ['maxTotalHits' => $maxTotalHits],
            'faceting' => [
                // One budget for the WHOLE sidebar: every group's values live in
                // the single `facets` attribute, and Meilisearch caps per attribute.
                'maxValuesPerFacet' => $maxFacetValues,
                // Truncation keeps the first values in this order. Meilisearch
                // defaults to 'alpha', so a cut would drop the most common values
                // and leave the sidebar picking its "top N" from an A-to-somewhere
                // slice - with wrong counts on the values that survive.
                'sortFacetValuesBy' => ['*' => 'count'],
            ],
        ];
    }
}
