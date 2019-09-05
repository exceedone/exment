<?php

return [

    'locale' => env('APP_LOCALE', config('app.locale')),

    'timezone' => env('APP_TIMEZONE', config('app.timezone')),

    'system_locale_options' => env('EXMENT_SYSTEM_LOCALE_OPTIONS'),
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
    | exment debug mode add function in sql
    |--------------------------------------------------------------------------
    |
    | if true, function details when calling sql in laravel.log. (only 1 function)
    |
    */
    'debugmode_sqlfunction1' => env('EXMENT_DEBUG_MODE_SQLFUNCTION1', false),

    /*
    |--------------------------------------------------------------------------
    | driver
    |--------------------------------------------------------------------------
    |
    | file upload driver
    |
    */
    'driver' => [
        'default' => env('EXMENT_DRIVER_DEFAULT', 'local'),
    ],

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
    | exment use 2 factor
    |--------------------------------------------------------------------------
    |
    | if true, use 2 factor login.
    |
    */
    'login_use_2factor' =>  env('EXMENT_LOGIN_USE_2FACTOR', false),

    /*
    |--------------------------------------------------------------------------
    | 2factor Valid Period
    |--------------------------------------------------------------------------
    |
    */
    'login_2factor_valid_period' =>  env('EXMENT_LOGIN_2FACTOR_VALID_PERIOD', 10),

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
    'search_list_link_filter' => env('EXMENT_SEARCH_LIST_LINK_FILTER', true),
  
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

    /*
    |--------------------------------------------------------------------------
    | Mail Setting From env file
    |--------------------------------------------------------------------------
    |
    | if false, not use mail setting on system contoller
    |
    */
    'mail_setting_env_force' => env('EXMENT_MAIL_SETTING_ENV_FORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Login throttle
    |--------------------------------------------------------------------------
    |
    | Whether check login throttle. If true, and too many login attempts, cannot login.
    |
    */
    'throttle' => env('EXMENT_THROTTLE', true),

    /*
    |--------------------------------------------------------------------------
    | Login Max Attempts
    |--------------------------------------------------------------------------
    |
    | If you fail to login after this number of times, will not be able to login for a certain period of time.
    |
    */
    'max_attempts' => env('EXMENT_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Login Decay Minutes
    |--------------------------------------------------------------------------
    |
    | It is time (minutes) that can not log in.
    |
    */
    'decay_minutes' => env('EXMENT_DECAY_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | SELECT TABLE LIMIT COUNT
    |--------------------------------------------------------------------------
    |
    | It is limit count whether ajax or select.
    |
    */
    'select_table_limit_count' => env('EXMENT_SELECT_TABLE_LIMIT_COUNT', 100),

    /*
    |--------------------------------------------------------------------------
    | GRID_MIN_WIDTH
    |--------------------------------------------------------------------------
    |
    | set grid min width default
    |
    */
    'grid_min_width' => env('EXEMTN_GRID_MIN_WIDTH', 100),

    /*
    |--------------------------------------------------------------------------
    | GRID_MAX_WIDTH
    |--------------------------------------------------------------------------
    |
    | set grid max width default
    |
    */
    'grid_max_width' => env('EXEMTN_GRID_MAX_WIDTH', 300),

    /*
    |--------------------------------------------------------------------------
    | Expart mode
    |--------------------------------------------------------------------------
    |
    | To use expart function.
    |
    */
    'expart_mode' => env('EXMENT_EXPART_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Archive mail attachments
    |--------------------------------------------------------------------------
    |
    | Archive mail attachments to zip.
    |
    */
    'archive_attachment' => env('ARCHIVE_MAIL_ATTACHMENT', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled user view 
    |--------------------------------------------------------------------------
    |
    | Disabled user view, only system view
    |
    */
    'userview_disabled' => env('USER_VIEW_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled user dashboard 
    |--------------------------------------------------------------------------
    |
    | Disabled user dashboard, only system dashboard
    |
    */
    'userdashboard_disabled' => env('USER_DASHBOARD_DISABLED', false),
    
    /*
    |--------------------------------------------------------------------------
    | 7-zip path(for Windows)
    |--------------------------------------------------------------------------
    |
    | path to 7-zip program.
    |
    */
    '7zip_dir' => env('EXMENT_7ZIP_DIR', 'C:\\Program Files\\7-Zip'),
];
