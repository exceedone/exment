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
    ],

    'filter' => [
        'mode' => env('MEILISEARCH_FILTER_MODE', 'override'),

        'equality_column_types' => ['select', 'select_valtext', 'yesno'],

        'equality_exclude' => [],

        'max_groups' => 12,

        'max_values_per_group' => 8,
    ],
];
