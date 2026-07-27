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

        return [
            'searchableAttributes' => $opts['searchable_attributes'] ?? self::DEFAULT_SEARCHABLE,
            'filterableAttributes' => $filterable,
            'sortableAttributes' => $sortable,
            'stopWords' => array_values($opts['stop_words'] ?? []),
            'synonyms' => $synonyms,
            'rankingRules' => $opts['ranking_rules'] ?? self::DEFAULT_RANKING,
            'typoTolerance' => [
                'enabled' => $opts['typo_enabled'] ?? true,
                'minWordSizeForTypos' => [
                    'oneTypo' => (int) ($opts['typo_one'] ?? 5),
                    'twoTypos' => (int) ($opts['typo_two'] ?? 9),
                ],
            ],
        ];
    }
}
