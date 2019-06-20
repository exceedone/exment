<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Use API
    |--------------------------------------------------------------------------
    |
    | Whether use exment API.
    |
    */
    'api' => env('EXMENT_API', false),

    /*
    |--------------------------------------------------------------------------
    | Directory
    |--------------------------------------------------------------------------
    |
    | set exment directory
    |
    */
    'directory' => app_path('Exment'),

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Path
    |--------------------------------------------------------------------------
    |
    | set exment bootstrap path.
    |
    */
    'bootstrap' => app_path('Exment/bootstrap.php'),

    /*
    |--------------------------------------------------------------------------
    | exment debug mode
    |--------------------------------------------------------------------------
    |
    | if true, output sql log to laravel.log
    |
    */
    'debugmode' => env('EXMENT_DEBUG_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | exment debug mode add function in sql
    |--------------------------------------------------------------------------
    |
    | if true, function details when calling sql in laravel.log
    |
    */
    'debugmode_sqlfunction' => env('EXMENT_DEBUG_MODE_SQLFUNCTION', false),

    /*
    |--------------------------------------------------------------------------
    | password rule
    |--------------------------------------------------------------------------
    |
    | password rule for login
    |
    */
    'password_rule' => [
        // set regex rule
        'rule' => '^[ -~]+$',
        // set min length
        'min' => '8',
        // set max length
        'max' => '32',
    ],

    /*
    |--------------------------------------------------------------------------
    | organization_deeps
    |--------------------------------------------------------------------------
    |
    | set organization deep length.
    |
    */
    'organization_deeps' => env('EXMENT_ORGANIZATION_DEEPS', 4),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Rows
    |--------------------------------------------------------------------------
    |
    | dashboard max row count
    |
    */
    'dashboard_rows' => env('EXMENT_DASHBOARD_ROWS', 4),

    /*
    |--------------------------------------------------------------------------
    | Manual Url
    |--------------------------------------------------------------------------
    |
    | set dashboard manual base url
    |
    */
    'manual_url' => env('EXMENT_MANUAL_URL', 'https://exment.net/docs/#/'),

    /*
    |--------------------------------------------------------------------------
    | Template Search Url[WIP]
    |--------------------------------------------------------------------------
    |
    | set template search url.
    | We can search all templates. (WIP)
    |
    */
    'template_search_url' => env('EXMENT_TEMPLATE_SEARCH_URL', 'https://exment-manage.exment.net/api/template'),

    /*
    |--------------------------------------------------------------------------
    | Show Default Login Provider
    |--------------------------------------------------------------------------
    |
    | If you set SSO login provider, whether showing exment default login provider. 
    |
    */
    'show_default_login_provider' => env('EXMENT_SHOW_DEFAULT_LOGIN_PROVIDER', true),
    
    /*
    |--------------------------------------------------------------------------
    | Login Provider
    |--------------------------------------------------------------------------
    |
    | Set key names SSO login privider
    |
    */
    'login_providers' => env('EXMENT_LOGIN_PROVIDERS', []),
    
    /*
    |--------------------------------------------------------------------------
    | Revision Count Default
    |--------------------------------------------------------------------------
    |
    | Set default rivision count.
    |
    */
    'revision_count_default' => env('EXMENT_REVISION_COUNT', 100),
    
    /*
    |--------------------------------------------------------------------------
    | Backup info
    |--------------------------------------------------------------------------
    |
    | Difinition exment backup
    |
    */
    'backup_info' => [
        'mysql_dir' => env('EXMENT_MYSQL_BIN_DIR'),
        'def_file' => 'table_definition.sql',
        'copy_dir' => [
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notify Saved Skip Minutes
    |--------------------------------------------------------------------------
    |
    | The time to send an email again when sending an email to the same data before.
    |
    */
    'notify_saved_skip_minutes' => env('EXMENT_NOTIFY_SAVED_SKIP_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Chart BackgroundColor
    |--------------------------------------------------------------------------
    |
    | The colors showing chart background
    |
    */
    'chart_backgroundColor' => [
        "#FF6384",
        "#36A2EB",
        "#FFCE56",
        "#339900",
        "#ff6633",
        "#cc0099"
    ],

    /*
    |--------------------------------------------------------------------------
    | Search List Link Filter
    |--------------------------------------------------------------------------
    |
    | Keyword Search or relation search, if click list button, show filtered list. 
    | If true, filtered
    |
    */
    'search_list_link_filter' => env('EXMENT_SEARCH_LIST_LINK_FILTER', false),
  
    /*
    |--------------------------------------------------------------------------
    | Filter Search Full
    |--------------------------------------------------------------------------
    |
    | Default is forward match search.
    | If true, full search
    |
    */
    'filter_search_full' => env('EXMENT_FILTER_SEARCH_FULL', false),
  
    /*
    |--------------------------------------------------------------------------
    | Keyword Search Count
    |--------------------------------------------------------------------------
    |
    | Set max size keyword search (for performance)
    |
    */
    'keyword_search_count' => env('EXMENT_KEYWORD_SEARCH_COUNT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Keyword Search Relation Count
    |--------------------------------------------------------------------------
    |
    | Set max size relation search (for performance)
    |
    */
    'keyword_search_relation_count' => env('EXMENT_KEYWORD_SEARCH_RELATION_COUNT', 5000),
];
