<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meilisearch connection
    */

    'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),

    'key' => env('MEILISEARCH_KEY', null),
    'connect_timeout' => (float) env('MEILISEARCH_CONNECT_TIMEOUT', 1.0),

    'timeout' => (float) env('MEILISEARCH_TIMEOUT', 5.0),

    'index' => env('MEILISEARCH_INDEX', 'exment_global'),

    'batch_size' => (int) env('MEILISEARCH_BATCH_SIZE', 1000),

    'permission_scan_cap' => (int) env('MEILISEARCH_PERMISSION_SCAN_CAP', 1000),

    'global_search' => filter_var(env('MEILISEARCH_GLOBAL_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'matching_strategy' => env('MEILISEARCH_MATCHING_STRATEGY', 'all'),

    'realtime_sync' => filter_var(env('MEILISEARCH_REALTIME_SYNC', false), FILTER_VALIDATE_BOOLEAN),

    'sync_queue' => env('MEILISEARCH_SYNC_QUEUE', 'default'),

    'reindex_queue' => env('MEILISEARCH_REINDEX_QUEUE', 'meili-reindex'),

    'repair_enabled' => filter_var(env('MEILISEARCH_REPAIR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'repair_at' => env('MEILISEARCH_REPAIR_AT', '03:00'),

    'settings' => [
        'searchable_attributes' => ['label', 'fields', 'table_label'],

        'stop_words' => [],
        // Synonyms, e.g. ['nyc' => ['new york']]. Leave empty if unused.
        'synonyms' => [],

        // Typo tolerance: enabled flag + minimum word lengths for 1/2 typos.
        'typo_enabled' => true,
        'typo_one' => 5,   // words of >=5 chars: allow 1 typo
        'typo_two' => 9,   // words of >=9 chars: allow 2 typos

        // Escape hatches, empty by default (see IndexSettings::build).
        // The attributes the feature needs (table_name, f_date, f_user, facets
        // and the n_<col> range fields) are always added, so these only ADD to
        // them - they cannot break filtering by omission.
        'filterable_attributes' => [],
        'sortable_attributes' => [],
        // Meilisearch ranking rule order. Empty = IndexSettings::DEFAULT_RANKING
        // ['words','typo','proximity','attribute','sort','exactness'].
        'ranking_rules' => [],

        'max_facet_values' => (int) env('MEILISEARCH_MAX_FACET_VALUES', 1000),

        // Query/document language hint (ISO-639-3, comma-separated env).
        'locales' => array_values(array_filter(explode(',', (string) env('MEILISEARCH_LOCALES', 'jpn')))),
    ],

    'filter' => [
        'mode' => env('MEILISEARCH_FILTER_MODE', 'override'),

        'equality_column_types' => ['select', 'select_valtext', 'yesno'],

        'equality_exclude' => [],

        'max_groups' => 12,

        // Values beyond this are dropped, and the sidebar's "N more" only counts
        // what reached the view - so a group past this limit understates by a lot.
        'max_values_per_group' => 20,
    ],
];
