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
    
    'reindex_chunk_size' => (int) env('MEILISEARCH_REINDEX_CHUNK_SIZE', 500),

    'permission_scan_cap' => (int) env('MEILISEARCH_PERMISSION_SCAN_CAP', 1000),

    'global_search' => filter_var(env('MEILISEARCH_GLOBAL_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'matching_strategy' => env('MEILISEARCH_MATCHING_STRATEGY', 'all'),

    'realtime_sync' => filter_var(env('MEILISEARCH_REALTIME_SYNC', false), FILTER_VALIDATE_BOOLEAN),

    'sync_queue' => env('MEILISEARCH_SYNC_QUEUE', 'default'),

    'reindex_queue' => env('MEILISEARCH_REINDEX_QUEUE', 'meili-reindex'),

    'attachment_queue' => env('MEILISEARCH_ATTACHMENT_QUEUE', 'meili-attachments'),

    // Phase 2 attachment-text extraction limits. These protect queue workers
    // independently of the application upload-size limit.
    'attachment_extraction' => [
        'max_bytes' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_BYTES', 25 * 1024 * 1024),
        'max_characters' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_CHARACTERS', 500000),
        'max_zip_entries' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_ZIP_ENTRIES', 5000),
        'max_zip_uncompressed_bytes' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_ZIP_UNCOMPRESSED_BYTES', 100 * 1024 * 1024),
        'max_zip_compression_ratio' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_ZIP_COMPRESSION_RATIO', 100),
        // An entry below this size is never judged by the compression ratio:
        // legitimate OOXML with repeated rows compresses well past 100:1.
        'min_zip_ratio_bytes' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MIN_ZIP_RATIO_BYTES', 1024 * 1024),
        'max_spreadsheet_cells' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_SPREADSHEET_CELLS', 200000),
        // Checked before PhpSpreadsheet expands the workbook: the cell cap
        // above is only reached once load() has already allocated it.
        'max_spreadsheet_bytes' => (int) env('MEILISEARCH_ATTACHMENT_EXTRACT_MAX_SPREADSHEET_BYTES', 20 * 1024 * 1024),
        // A record can own more than one file. Keep the resulting Meili
        // document bounded independently from each individual extraction.
        'max_record_characters' => (int) env('MEILISEARCH_ATTACHMENT_INDEX_MAX_CHARACTERS', 1000000),
    ],

    'repair_enabled' => filter_var(env('MEILISEARCH_REPAIR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'repair_at' => env('MEILISEARCH_REPAIR_AT', '03:00'),

    'settings' => [
        'searchable_attributes' => ['label', 'fields', 'attachments.name', 'attachments.text', 'table_label'],

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
