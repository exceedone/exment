<?php

return [

    'locale' => env('APP_LOCALE', config('app.locale')),

    'timezone' => env('APP_TIMEZONE', config('app.timezone')),

    'system_locale_options' => env('EXMENT_SYSTEM_LOCALE_OPTIONS'),

    // the language of the browser (Accept-Language) for a user who has not chosen one (ISS02835).
    // Not set: the system setting (System → Advanced, on by default). Set (true / false): wins over the setting.
    'locale_from_browser' => env('EXMENT_LOCALE_FROM_BROWSER'),



    /*
    |--------------------------------------------------------------------------
    | composer path
    |--------------------------------------------------------------------------
    |
    | If select composer path, set composer path
    |
    */
    'composer_path' => env('EXMENT_COMPOSER_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Plugin upload / code editing
    |--------------------------------------------------------------------------
    |
    | Whether users may upload, install (from market) or edit plugin code.
    | Installing/editing a plugin executes arbitrary PHP on the server, so in
    | shared/multi-tenant deployments this should be set to false and plugins
    | provisioned out-of-band by the operator.
    |
    */
    'plugin_upload_enabled' => env('EXMENT_PLUGIN_UPLOAD_ENABLED', true),


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
    | Use Cache
    |--------------------------------------------------------------------------
    |
    | Whether use exment cache.
    |
    */
    'use_cache' => env('EXMENT_USE_CACHE', false),

    /*
    |--------------------------------------------------------------------------
    | Tuning: server-wide buttons
    |--------------------------------------------------------------------------
    |
    | Whether the tuning page (Admin Setting > Tuning) may change a setting of the
    | whole server from its buttons (make or remove the config cache).
    | Set false where several sites share the code (a multi-tenant server).
    |
    */
    'tuning_server_actions' => env('EXMENT_TUNING_SERVER_ACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Security check: the address of the site, seen from this server
    |--------------------------------------------------------------------------
    |
    | The security check asks the site for some pages (headers, files that must not be seen).
    | It uses APP_URL; set this when that address cannot be reached from the server itself
    | (in a container, for example http://web for docker-exment).
    */
    'site_probe_url' => env('EXMENT_SITE_PROBE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Security check: the APP_KEY of the download zip
    |--------------------------------------------------------------------------
    |
    | The zip of Exment writes here the SHA-256 of the APP_KEY in its .env. While APP_KEY is still
    | that one (php artisan key:generate was not run), the security check says so.
    */
    'shipped_app_key_sha256' => env('EXMENT_SHIPPED_APP_KEY_SHA256'),

    /*
    |--------------------------------------------------------------------------
    | Chart of a list view: max rows
    |--------------------------------------------------------------------------
    |
    | A chart of a list view draws one point per row. It reads at most this many
    | rows and says so when there are more (ISS00169: 5000 by default, more points
    | than a chart can show; before 7.2.6 every row was read and a large table ran
    | out of memory or time). 0 = no limit. A chart of a large table is better made
    | with a summary view.
    |
    */
    'chart_list_max_rows' => env('EXMENT_CHART_LIST_MAX_ROWS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Dashboard aggregation cache
    |--------------------------------------------------------------------------
    |
    | Keep the rows a chart of a summary view aggregates for this many seconds
    | (0 = off: aggregate on every load). Each viewer has their own. Saving or
    | deleting a row aggregates again; a change the models do not see (a share,
    | a membership, a direct SQL) shows when the rows expire.
    |
    */
    'dashboard_cache_seconds' => env('EXMENT_DASHBOARD_CACHE_SECONDS', 0),

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
    | diable exment exception handler
    |--------------------------------------------------------------------------
    |
    */
    'disable_exment_exception_handler' => env('EXMENT_DISABLE_EXMENT_EXCEPTION_HANDLER', false),

    /*
    |--------------------------------------------------------------------------
    | exment debug mode request
    |--------------------------------------------------------------------------
    |
    | if true, log request in laravel.log
    |
    */
    'debugmode_request' => env('EXMENT_DEBUG_MODE_REQUEST', false),

    /*
    |--------------------------------------------------------------------------
    | exment debug mode sql
    |--------------------------------------------------------------------------
    |
    | if true, log sql in laravel.log
    |
    */
    'debugmode_sql' => env('EXMENT_DEBUG_MODE_SQL', false),

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
    | exment debug mode schedule
    |--------------------------------------------------------------------------
    |
    | if true, log schedule in laravel.log
    |
    */
    'debugmode_schedule' => env('EXMENT_DEBUG_MODE_SCHEDULE', false),

    /*
    |--------------------------------------------------------------------------
    | driver
    |--------------------------------------------------------------------------
    |
    | file upload driver
    |
    */
    'driver' => [
        'exment' => env('EXMENT_DRIVER_EXMENT', 'local'),
        'backup' => env('EXMENT_DRIVER_BACKUP', 'local'),
        'plugin' => env('EXMENT_DRIVER_PLUGIN', 'local'),
        'template' => env('EXMENT_DRIVER_TEMPLATE', 'local'),
    ],

    'rootpath' => [
        's3' => [
            'exment' => env('AWS_BUCKET_EXMENT'),
            'backup' => env('AWS_BUCKET_BACKUP'),
            'plugin' => env('AWS_BUCKET_PLUGIN'),
            'template' => env('AWS_BUCKET_TEMPLATE'),
        ],
        'azure' => [
            'exment' => env('AZURE_STORAGE_CONTAINER_EXMENT'),
            'backup' => env('AZURE_STORAGE_CONTAINER_BACKUP'),
            'plugin' => env('AZURE_STORAGE_CONTAINER_PLUGIN'),
            'template' => env('AZURE_STORAGE_CONTAINER_TEMPLATE'),
        ],
        'ftp' => [
            'exment' => env('FTP_ROOT_EXMENT'),
            'backup' => env('FTP_ROOT_BACKUP'),
            'plugin' => env('FTP_ROOT_PLUGIN'),
            'template' => env('FTP_ROOT_TEMPLATE'),
        ],
        'sftp' => [
            'exment' => env('SFTP_ROOT_EXMENT'),
            'backup' => env('SFTP_ROOT_BACKUP'),
            'plugin' => env('SFTP_ROOT_PLUGIN'),
            'template' => env('SFTP_ROOT_TEMPLATE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | file download temporary url minutes
    |--------------------------------------------------------------------------
    |
    | How long the signed url used to download a file from a cloud storage stays valid.
    | The storage checks it when the download starts, so a download already running is not
    | cut when the url expires. But a download interrupted by a network problem is restarted
    | with the same url, and that fails once the url has expired.
    |
    */
    'file_download_temporary_url_minutes' => env('EXMENT_FILE_DOWNLOAD_TEMPORARY_URL_MINUTES', 15),

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
        'rule' => env('EXMENT_PASSWORD_RULE', '^[ -~]+$'),
        // set min length
        'min' => env('EXMENT_PASSWORD_RULE_MIN', '8'),
        // set max length (64: long pass phrases can be used)
        'max' => env('EXMENT_PASSWORD_RULE_MAX', '64'),
    ],

    /*
    |--------------------------------------------------------------------------
    | password policy for a new site
    |--------------------------------------------------------------------------
    |
    | true: a new site starts with the recommended password policy (Login
    | settings): complex passwords, a change at the first login and no reuse
    | of the last 5 passwords. Set when the site is initialized; a site that
    | is there already is not changed. An administrator can change them later.
    |
    */
    'password_policy_new_site' => env('EXMENT_PASSWORD_POLICY_NEW_SITE', false),

    /*
    |--------------------------------------------------------------------------
    | remove response space
    |--------------------------------------------------------------------------
    |
    | If true, remove response space.
    |
    */
    'remove_response_space' => env('EXMENT_REMOVE_RESPONSE_SPACE', false),

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
    | show_organization_tree
    |--------------------------------------------------------------------------
    |
    | whether showing organization tree
    |
    */
    'show_organization_tree' => env('EXMENT_SHOW_ORGANIZATION_TREE', false),

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
    | Disable Latest version Dashboard
    |--------------------------------------------------------------------------
    |
    | If true, disable showing latest version on dashboard
    |
    */
    'disable_latest_version_dashboard' => env('EXMENT_DISABLE_LATEST_VERSION_DASHBOARD', false),

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
    | Disabled custom login
    |--------------------------------------------------------------------------
    |
    | Disabled custom login force
    |
    */
    'custom_login_disabled' => env('EXMENT_CUSTOM_LOGIN_DISABLED', false),
    
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

    // the mails of the 2-step verification: the country and the region from the headers of the CDN in
    // front of the site (CloudFront, Cloudflare). Only with a CDN that sets them: anyone could send them.
    'login_location_headers' => env('EXMENT_LOGIN_LOCATION_HEADERS', false),

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
    | Disable login header logo
    |--------------------------------------------------------------------------
    |
    */
    'disable_login_header_logo' => env('EXMENT_DISABLE_LOGIN_HEADER_LOGO', false),
    
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
    | Notify skip self target
    |--------------------------------------------------------------------------
    |
    | If data update, and send user if login user, whether skip notify.
    |
    */
    'notify_skip_self_target' => env('EXMENT_NOTIFY_SKIP_SELF_TARGET', true),

    /*
    |--------------------------------------------------------------------------
    | Notify navbar
    |--------------------------------------------------------------------------
    |
    | Show notify navbar
    |
    */
    'notify_navbar' => env('EXMENT_NOTIFY_NAVBAR', true),

    /*
    |--------------------------------------------------------------------------
    | Notify navbar interval
    |--------------------------------------------------------------------------
    |
    | How often the bell asks for new notifications, in seconds (at least 30).
    | The open tabs of one browser share one poll, and a hidden tab does not ask.
    |
    */
    'notify_navbar_interval' => env('EXMENT_NOTIFY_NAVBAR_INTERVAL', 60),

    /*
    |--------------------------------------------------------------------------
    | Workflow task navbar
    |--------------------------------------------------------------------------
    |
    | Show the un-actioned workflow task navbar icon
    |
    */
    'workflow_task_navbar' => env('EXMENT_WORKFLOW_TASK_NAVBAR', true),

    /*
    |--------------------------------------------------------------------------
    | Workflow task navbar interval
    |--------------------------------------------------------------------------
    |
    | How often (SECONDS) the browser re-fetches the workflow task badge.
    | Default 300 (5 minutes). Values below 30 are raised to 30: this endpoint scans
    | every pending record of every workflow table, once per logged-in browser.
    |
    */
    'workflow_task_navbar_interval' => env('EXMENT_WORKFLOW_TASK_NAVBAR_INTERVAL', 300),

    /*
    |--------------------------------------------------------------------------
    | Same organization workflow notify
    |--------------------------------------------------------------------------
    |
    | When a member of an assigned organization executes a workflow action, also
    | notify the other members of that organization. The notification body contains
    | the record label, so set false to keep it off other members' navbar.
    |
    */
    'same_org_workflow_notify' => env('EXMENT_SAME_ORG_WORKFLOW_NOTIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Chart BackgroundColor
    |--------------------------------------------------------------------------
    |
    | The colors showing chart background
    |
    */
    'chart_backgroundColor' => env('EXMENT_CHART_BG_COLOR', '#FF6384,#36A2EB,#FFCE56,#339900,#ff6633,#cc0099'),

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
    | Calendar Max size Count
    |--------------------------------------------------------------------------
    |
    | Set max size calendar (for performance)
    |
    */
    'calendar_max_size_count' => env('EXMENT_CALENDAR_MAX_SIZE_COUNT', 300),

    /*
    |--------------------------------------------------------------------------
    | Kanban Max size Count
    |--------------------------------------------------------------------------
    |
    | Default max records drawn on a kanban board (for performance).
    | Each view can override it with its own "kanban_max_count" setting.
    |
    */
    'kanban_max_size_count' => env('EXMENT_KANBAN_MAX_SIZE_COUNT', 300),

    /*
    |--------------------------------------------------------------------------
    | Calendar Data get value
    |--------------------------------------------------------------------------
    |
    | Whether get data value
    |
    */
    'calendar_data_get_value' => env('EXMENT_CALENDAR_DATA_GET_VALUE', false),

    /*
    |--------------------------------------------------------------------------
    | Search Filter Ajax
    |--------------------------------------------------------------------------
    |
    | Custom Value's filter as ajax
    |
    */
    'custom_value_filter_ajax' => env('EXMENT_CUSTOM_VALUE_FILTER_AJAX', true),
  
    /*
    |--------------------------------------------------------------------------
    | search document
    |--------------------------------------------------------------------------
    |
    | Whether searching document file
    |
    */
    'search_document' => env('EXMENT_SEARCH_DOCUMENT', false),
  
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
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | For a public demo site that publishes its login. If true, operations that run code
    | on the server, change the server, send anything outside or show secrets are refused,
    | even for the system administrator: plugin upload and code editing, backup, template
    | import, mail / login / API settings, password changes and so on.
    | Mail is written to the log only, and Slack, Teams and LINE messages are not sent.
    | Console commands (ex. a nightly restore by cron) are not limited.
    | See Exceedone\Exment\Services\DemoMode for the full list.
    |
    */
    'demo_mode' => env('EXMENT_DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | features locked
    |--------------------------------------------------------------------------
    |
    | The features the server keeps off, whatever the administrators of the
    | site choose in Admin setting → Feature management (a comma list of
    | keys, as "file_server,studio"). For a SaaS that does not offer them.
    |
    */
    'features_locked' => env('EXMENT_FEATURES_LOCKED', ''),

    /*
    |--------------------------------------------------------------------------
    | maintenance mode: the operator's file
    |--------------------------------------------------------------------------
    |
    | The file of the operator's maintenance (`php artisan exment:maintenance
    | on --operator`). The screens of the site cannot change it. Empty:
    | storage/framework/exment-maintenance.json. A comma list is read in
    | order (as a file of the site, then one of all the sites of the server);
    | the command writes the first. Keep them where only the server's
    | operator may write.
    |
    */
    'maintenance_operator_file' => env('EXMENT_MAINTENANCE_OPERATOR_FILE', ''),

    // the paths (exact, GET and HEAD) a maintenance does not stop: the health
    // check of a load balancer. "/" only sends on to the login.
    'maintenance_exempt_paths' => env('EXMENT_MAINTENANCE_EXEMPT_PATHS', '/,/up'),

    /*
    |--------------------------------------------------------------------------
    | the plugins bundled with Exment, the id of the site
    |--------------------------------------------------------------------------
    |
    | bundled_plugins_path: the folder of the signed releases of the free
    | official plugins (default: "releases" of exceedone/exment-plugins-release).
    | site_id: the id of the site, when it is given from outside (a SaaS: the
    | uuid of the tenant). Without it, the one made once by Exment is used.
    |
    */
    'bundled_plugins_path' => env('EXMENT_BUNDLED_PLUGINS_PATH'),
    // false: no "use this version" / "back to the version before" (a SaaS keeps the versions to itself)
    'bundled_plugins_updates' => env('EXMENT_BUNDLED_PLUGINS_UPDATES', true),
    'site_id' => env('EXMENT_SITE_ID'),

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
    | IPv6 prefix of the rate limits
    |--------------------------------------------------------------------------
    |
    | The rate limits and the login lockouts count an IPv6 address as its network of this
    | prefix (a client has a /64 and can take a new address in it for every try). 128 counts
    | each address.
    |
    */
    'ipv6_limit_prefix' => env('EXMENT_IPV6_LIMIT_PREFIX', 64),

    /*
    |--------------------------------------------------------------------------
    | session Expire On Close
    |--------------------------------------------------------------------------
    |
    | If true, if close browser, logout
    |
    */
    'session_expire_on_close' => env('EXMENT_SESSION_EXPIRE_ON_CLOSE', false),

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
    | SELECT TABLE MODAL SEARCH DISABLED
    |--------------------------------------------------------------------------
    |
    | It is limit count whether ajax or select.
    |
    */
    'select_table_modal_search_disabled' => env('EXMENT_SELECT_TABLE_MODAL_SEARCH_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | CHARACTER LENGTH LIMIT COUNT
    |--------------------------------------------------------------------------
    |
    | It is character limit count for textbox or editor.
    |
    */
    'char_length_limit' => env('EXMENT_CHAR_LENGTH_LIMIT_COUNT', 63999),

    /*
    |--------------------------------------------------------------------------
    | COMMENT IMAGE MAX SIZE
    |--------------------------------------------------------------------------
    |
    | The largest image (PNG, JPEG, GIF, WebP) that can be pasted or dropped into
    | the comment box of a data, in kilobytes.
    |
    */
    'comment_image_max_size_kb' => env('EXMENT_COMMENT_IMAGE_MAX_SIZE_KB', 10240),

    /*
    |--------------------------------------------------------------------------
    | GRID_MIN_WIDTH
    |--------------------------------------------------------------------------
    |
    | set grid min width default
    |
    */
    'grid_min_width' => env('EXMENT_GRID_MIN_WIDTH', 100),

    /*
    |--------------------------------------------------------------------------
    | GRID_MAX_WIDTH
    |--------------------------------------------------------------------------
    |
    | set grid max width default
    |
    */
    'grid_max_width' => env('EXMENT_GRID_MAX_WIDTH', 300),

    /*
    |--------------------------------------------------------------------------
    | GRID PER PAGES
    |--------------------------------------------------------------------------
    |
    | Set options for grid rows count per page.
    |
    */
    'grid_per_pages' => env('EXMENT_GRID_PER_PAGES', null),

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
    | File Delete Upload Only
    |--------------------------------------------------------------------------
    |
    | Filter only file upload user only
    |
    */
    'file_delete_useronly' => env('EXMENT_FILE_DELETE_USERONLY', false),

    /*
    |--------------------------------------------------------------------------
    | File Drag & Drop form disabled
    |--------------------------------------------------------------------------
    |
    | File Drag & Drop form disabled
    |
    */
    'file_drag_drop_disabled' => env('EXMENT_FILE_DRAG_DROP_DISABLED', false),
    
    /*
    |--------------------------------------------------------------------------
    | Custom Value Show Hide hidefield
    |--------------------------------------------------------------------------
    |
    | If true, hide hidden field
    |
    */
    'hide_hiddenfield' => env('EXMENT_HIDE_HIDDENFIELD', true),

    /*
    |--------------------------------------------------------------------------
    | Archive mail attachments
    |--------------------------------------------------------------------------
    |
    | Archive mail attachments to zip.
    | *KEY MISTAKE. Set double name.
    |
    */
    'archive_attachment' => env('EXMENT_ARCHIVE_MAIL_ATTACHMENT', env('ARCHIVE_MAIL_ATTACHMENT', false)),

    /*
    |--------------------------------------------------------------------------
    | Disabled user view
    |--------------------------------------------------------------------------
    |
    | Disabled user view, only system view
    |
    */
    'userview_disabled' => env('EXMENT_USER_VIEW_DISABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Disabled user dashboard
    |--------------------------------------------------------------------------
    |
    | Disabled user dashboard, only system dashboard
    |
    */
    'userdashboard_disabled' => env('EXMENT_USER_DASHBOARD_DISABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Show edit page when row click
    |--------------------------------------------------------------------------
    |
    | Show edit page when grid-row selected
    |
    */
    'gridrow_select_edit' => env('EXMENT_GRIDROW_SELECT_EDIT', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled show detail or edit page when row click
    |--------------------------------------------------------------------------
    |
    | Disabled Show detail page when grid-row selected
    |
    */
    'gridrow_select_disabled' => env('EXMENT_GRIDROW_SELECT_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Show copy button on customvalue grid page
    |--------------------------------------------------------------------------
    |
    | Show copy button on customvalue grid page
    |
    */
    'gridrow_show_copy_button' => env('EXMENT_GRIDROW_SHOW_COPY_BUTTON', false),

    /*
    |--------------------------------------------------------------------------
    | Screen design
    |--------------------------------------------------------------------------
    |
    | Which screen design is used: "modern" or "classic".
    | "modern" loads the stylesheet vendor/exment/css/modern.css on top of
    | the theme; "classic" is the look Exment had before.
    |
    | Not set (default): the "Screen design" item on the system setting page
    | decides. Its default is "modern".
    | "modern" / "classic": the server decides. The value here wins over the
    | system setting, and the system setting page shows it read-only.
    |
    */
    'ui_design' => env('EXMENT_UI_DESIGN'),

    /*
    |--------------------------------------------------------------------------
    | Disabled ip filter
    |--------------------------------------------------------------------------
    |
    | Disabled ip address filter
    |
    */
    'ip_filter_disabled' => env('EXMENT_DISABLE_IP_FILTER', false),
    
    /*
    |--------------------------------------------------------------------------
    | Impoer row max count
    |--------------------------------------------------------------------------
    |
    | Impoer row max count
    |
    */
    'import_max_row_count' => env('EXMENT_IMPORT_MAX_ROW_COUNT', 5000),
    
    /*
    |--------------------------------------------------------------------------
    | Disabled import & export csv
    |--------------------------------------------------------------------------
    |
    | Disabled import & export csv
    |
    */
    'export_import_export_disabled_csv' => env('EXMENT_IMPORT_EXPORT_DISABLED_CSV', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled import & export excel
    |--------------------------------------------------------------------------
    |
    | Disabled import & export excel
    |
    */
    'export_import_export_disabled_excel' => env('EXMENT_IMPORT_EXPORT_DISABLED_EXCEL', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled import
    |--------------------------------------------------------------------------
    |
    | Disabled import mode
    |
    */
    'import_disabled' => env('EXMENT_IMPORT_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled export mode
    |--------------------------------------------------------------------------
    |
    | Disabled export mode
    |
    */
    'export_disabled' => env('EXMENT_EXPORT_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled export view mode
    |--------------------------------------------------------------------------
    |
    | Disabled export view mode
    |
    */
    'export_view_disabled' => env('EXMENT_EXPORT_VIEW_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | export bom csv
    |--------------------------------------------------------------------------
    |
    | Export csv appending BOM
    |
    */
    'export_append_csv_bom' => env('EXMENT_EXPORT_APPEND_CSV_BOM', false),

    /*
    |--------------------------------------------------------------------------
    | export library
    |--------------------------------------------------------------------------
    |
    | export data library, default is PHP SPREAT SHEET
    |
    */
    'export_library' => env('EXMENT_EXPORT_LIBRARY'),

    /*
    |--------------------------------------------------------------------------
    | import library
    |--------------------------------------------------------------------------
    |
    | import data library, default is SPOUT
    |
    */
    'import_library' => env('EXMENT_IMPORT_LIBRARY'),

    /*
    |--------------------------------------------------------------------------
    | Select relation linkage disabled
    |--------------------------------------------------------------------------
    |
    | Disable select relation linkage
    | "related linkage": When selecting a value, change the choices of other list. It's for 1:n relation.
    |
    */
    'select_relation_linkage_disabled' => env('SELECT_RELATION_LINKAGE_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | SystemUpdateDisplayDisabled
    |--------------------------------------------------------------------------
    |
    | IF true, disabled system update on display.
    |
    */
    'system_update_display_disabled' => env('EXMENT_SYSTEM_UPDATE_DISPLAY_DISABLED', false),

    
    /*
    |--------------------------------------------------------------------------
    | Textarea html space to tag
    |--------------------------------------------------------------------------
    |
    | When showing html if textarea, space to tag.
    |
    */
    'textarea_space_tag' => env('EXMENT_TEXTAREA_SPACE_TAG', true),
    
    /*
    |--------------------------------------------------------------------------
    | API default get data count
    |--------------------------------------------------------------------------
    |
    | get data count (custom_table, custom_value, custom_column...)
    | *KEY MISTAKE. Set double name.
    |
    */
    'api_default_data_count' => env('EXMENT_API_DEFAULT_DATA_COUNT', env('API_DEFAULT_DATA_COUNT', 20)),
    
    /*
    |--------------------------------------------------------------------------
    | API max get data count
    |--------------------------------------------------------------------------
    |
    | get data count (custom_table, custom_value, custom_column...)
    |
    */
    'api_max_data_count' => env('EXMENT_API_MAX_DATA_COUNT', 100),
    
    /*
    |--------------------------------------------------------------------------
    | API max create count
    |--------------------------------------------------------------------------
    |
    | max length create data
    |
    */
    'api_max_create_count' => env('EXMENT_API_MAX_CREATE_COUNT', 100),
    
    /*
    |--------------------------------------------------------------------------
    | API max delete count
    |--------------------------------------------------------------------------
    |
    | max length delete data
    |
    */
    'api_max_delete_count' => env('EXMENT_API_MAX_DELETE_COUNT', 100),
    
    /*
    |--------------------------------------------------------------------------
    | API append label
    |--------------------------------------------------------------------------
    |
    | Whether appending label column
    |
    */
    'api_append_label' => env('EXMENT_API_APPEND_LABEL', false),
    
    /*
    |--------------------------------------------------------------------------
    | Custom Column Index Enabled count
    |--------------------------------------------------------------------------
    |
    | Custom Column Index Enabled count
    |
    */
    'column_index_enabled_count' => env('EXMENT_COLUMN_INDEX_ENABLED_COUNT', 20),
    
    /*
    |--------------------------------------------------------------------------
    | 7-zip path(for Windows)
    |--------------------------------------------------------------------------
    |
    | path to 7-zip program.
    |
    */
    '7zip_dir' => env('EXMENT_7ZIP_DIR', 'C:\\Program Files\\7-Zip'),

    /*
    |--------------------------------------------------------------------------
    | File download inline
    |--------------------------------------------------------------------------
    |
    */
    'file_download_inline_extensions' => env('EXMENT_FILE_DOWNLOAD_INLINE_EXTENSIONS', ''),

    /*
    |--------------------------------------------------------------------------
    | Delete force custom value
    |--------------------------------------------------------------------------
    |
    | Custom value delete always force
    */
    'delete_force_custom_value' => env('EXMENT_DELETE_FORCE_CUSTOM_VALUE', false),

    /*
    |--------------------------------------------------------------------------
    | The archive of the deleted data deleted for good (ISS00147)
    |--------------------------------------------------------------------------
    | exment:trash-purge deletes for good the deleted data older than the days of its table
    | (the table setting), after writing it to deleted_archives and copying its files.
    | keep_days: the days an archive is kept (0: kept forever).
    | disk: the disk of the copied files (empty: the disk of the attachments), folder: the folder in it.
    */
    'deleted_archive_keep_days' => env('EXMENT_DELETED_ARCHIVE_KEEP_DAYS', 365),
    'deleted_archive_disk' => env('EXMENT_DELETED_ARCHIVE_DISK'),
    'deleted_archive_folder' => env('EXMENT_DELETED_ARCHIVE_FOLDER', 'archives/deleted'),

    /*
    |--------------------------------------------------------------------------
    | Document upload max count one request
    |--------------------------------------------------------------------------
    |
    */
    'document_upload_max_count' => env('EXMENT_DOCUMENT_UPLOAD_MAX_COUNT', 5),

    /*
    |--------------------------------------------------------------------------
    | Older versions kept of an attachment
    |--------------------------------------------------------------------------
    |
    | When an attachment is replaced, the file it had is kept as an older
    | version. At most this many older versions are kept per attachment (the
    | oldest are deleted, files too). 0 keeps none.
    |
    */
    'document_version_limit' => env('EXMENT_DOCUMENT_VERSION_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Disabled show datalist table button for all user
    |--------------------------------------------------------------------------
    |
    | Disabled show datalist table button for all user
    |
    */
    'datalist_table_button_disabled' => env('EXMENT_TABLE_BUTTON_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled show table setting button except admin user
    |--------------------------------------------------------------------------
    |
    | Disabled show table setting button except admin user
    |
    */
    'datalist_table_button_disabled_user' => env('EXMENT_TABLE_BUTTON_DISABLED_USER', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled show view button for all user
    |--------------------------------------------------------------------------
    |
    | Disabled show view button for all user
    |
    */
    'datalist_view_button_disabled' => env('EXMENT_VIEW_BUTTON_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Disabled show view button except admin user
    |--------------------------------------------------------------------------
    |
    | Disabled show view button except admin user
    |
    */
    'datalist_view_button_disabled_user' => env('EXMENT_VIEW_BUTTON_DISABLED_USER', false),
    
    /*
    |--------------------------------------------------------------------------
    | html allowed tags(for HTML Purifier)
    |--------------------------------------------------------------------------
    |
    | html allowed tag list. Use default settings if not set
    |
    */
    'html_allowed' => env('EXMENT_HTML_ALLOWED', null),

    /*
    |--------------------------------------------------------------------------
    | html allowed tag attributes(for HTML Purifier)
    |--------------------------------------------------------------------------
    |
    | html allowed tag atrtribute list. Use default settings if not set
    |
    */
    'html_allowed_attributes' => env('EXMENT_HTML_ALLOWED_ATTRIBUTES', null),

    /*
    |--------------------------------------------------------------------------
    | css allowed tag properties(for HTML Purifier)
    |--------------------------------------------------------------------------
    |
    | css allowed tag properties list. Use default settings if not set
    |
    */
    'css_allowed_properties' => env('EXMENT_CSS_ALLOWED_PROPERTIES', null),
    
    /*
    |--------------------------------------------------------------------------
    | html allowed tags(for TinyMCE)
    |--------------------------------------------------------------------------
    |
    | html allowed tag list. Use default settings if not set
    |
    */
    'html_allowed_editor' => env('EXMENT_HTML_ALLOWED_EDITOR', null),

    /*
    |--------------------------------------------------------------------------
    | diable upload images
    |--------------------------------------------------------------------------
    |
    | disable paste image
    |
    */
    'diable_upload_images_editor' => env('EXMENT_DIABLE_UPLOAD_IMAGES_EDITOR', false),


    /*
    |--------------------------------------------------------------------------
    | Public form route prefix
    |--------------------------------------------------------------------------
    |
    | Publicform Route Prefix
    |
    */
    'publicform_route_prefix' => env('EXMENT_PUBLICFORM_ROUTE_PREFIX', 'publicform'),

    /*
    |--------------------------------------------------------------------------
    | Public form api route prefix
    |--------------------------------------------------------------------------
    |
    | Publicform api Route Prefix
    |
    */
    'publicformapi_route_prefix' => env('EXMENT_PUBLICFORMAPI_ROUTE_PREFIX', 'publicformapi'),

    /*
    |--------------------------------------------------------------------------
    | Public form disable footer label
    |--------------------------------------------------------------------------
    |
    */
    'disable_publicform_use_footer_label' => env('EXMENT_DISABLE_PUBLICFORM_USE_FOOTER_LABEL', false),

    /*
    |--------------------------------------------------------------------------
    | PUBLICFORM URLPARAM SUUID
    |--------------------------------------------------------------------------
    |
    | Use suuid when getting the value of select table from a URL parameter.
    |
    */
    'publicform_urlparam_suuid' => env('EXMENT_PUBLICFORM_URLPARAM_SUUID', false),

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA v3 score threshold
    |--------------------------------------------------------------------------
    |
    | reCAPTCHA v3 does not ask the visitor anything: it returns a score from
    | 0.0 (almost certainly a bot) to 1.0 (almost certainly a human), and this
    | is the value below which a submission is rejected. 0.5 is Google's own
    | recommended starting point. Raise it if spam still gets through, lower it
    | if real visitors are being blocked. Only used when the system settings
    | screen selects v3; v2 answers pass or fail and ignores this.
    | Values outside 0..1 are ignored and 0.5 is used instead.
    |
    */
    'recaptcha_v3_score_threshold' => env('EXMENT_RECAPTCHA_V3_SCORE_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA skip ips
    |--------------------------------------------------------------------------
    |
    | Ip addresses that are never asked to pass reCAPTCHA, e.g. an office
    | address used for testing the public form. Leave empty in production: an
    | entry here disables the check completely for that address.
    |
    */
    'recaptcha_skip_ips' => [],

    /*
    |--------------------------------------------------------------------------
    | Show disable field readonly
    |--------------------------------------------------------------------------
    |
    */
    'disable_show_field_readonly' => env('EXMENT_DISABLE_SHOW_FIELD_READONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Show disable field readonly
    |--------------------------------------------------------------------------
    |
    */
    'disable_show_field_viewonly' => env('EXMENT_DISABLE_SHOW_FIELD_VIEWONLY', false),


  
    /*
    |--------------------------------------------------------------------------
    | Reverse Proxy IPs
    |--------------------------------------------------------------------------
    |
    | Set trust proxy IPs.
    | *This config doesn't want to copy backup restore, so set key is ADMIN_, not EXMENT_.*
    */
    'trust_proxy_ips' => env('ADMIN_TRUST_PROXY_IPS', null),
  
    /*
    |--------------------------------------------------------------------------
    | Reverse Proxy headers
    |--------------------------------------------------------------------------
    |
    | Set trust proxy headers. set as Request::{name}.
    | *This config doesn't want to copy backup restore, so set key is ADMIN_, not EXMENT_.*
    */
    'trust_proxy_headers' => env('ADMIN_TRUST_PROXY_HEADERS', null),

    /*
    |--------------------------------------------------------------------------
    | Client certificates of the API keys of the users
    |--------------------------------------------------------------------------
    |
    | The header in which the front server (nginx, ALB) gives the client certificate it checked:
    | its sha256 fingerprint, or the certificate (PEM, URL-encoded, as X-Amzn-Mtls-Clientcert).
    | It is read only from a trusted proxy (ADMIN_TRUST_PROXY_IPS). The front server MUST always
    | set (overwrite) this header, empty when it did not check a certificate: Exment cannot tell a
    | header the proxy wrote from one the client sent through it. With ADMIN_TRUST_PROXY_IPS=*
    | every request counts as from the proxy, so only use it when nothing reaches Exment directly.
    | ADMIN_API_KEY_REQUIRE_CLIENT_CERT=true: no API key works without a client certificate.
    | *Not copied by a backup restore: the keys are ADMIN_.*
    */
    'api_client_cert_header' => env('ADMIN_API_CLIENT_CERT_HEADER', null),
    'api_key_require_client_cert' => env('ADMIN_API_KEY_REQUIRE_CLIENT_CERT', false),

    /*
    | The API keys of the users: the most days of a key (at most 365), of a key made by a login of
    | SSO (shorter: Exment does not hear when the IdP stops the user), and the keys a user has at once.
    */
    'api_key_max_days' => env('EXMENT_API_KEY_MAX_DAYS', 365),
    'api_key_max_days_sso' => env('EXMENT_API_KEY_MAX_DAYS_SSO', 30),
    'api_key_max_per_user' => env('EXMENT_API_KEY_MAX_PER_USER', 10),

    /*
    | How often the use of a key (when, from where, how many times) is written on the key: at
    | most once in this many seconds a key. The uses in between are counted in the cache and
    | written with the next write; the pages of the keys show them already. 0 (default): every
    | call, as always.
    | Only for a cache in memory (Redis, Memcached) and many calls: with the cache in files it is
    | not faster (measured), and the uses not written yet are lost when the cache is emptied
    | (cache:clear, a deploy), so the count is then about, not exact.
    */
    'api_key_touch_seconds' => env('EXMENT_API_KEY_TOUCH_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | MCP server (api/mcp)
    |--------------------------------------------------------------------------
    |
    | The most records one tool call returns (at most 100), and the calls a user makes per minute.
    */
    'mcp_max_rows' => env('EXMENT_MCP_MAX_ROWS', 50),
    'mcp_calls_per_minute' => env('EXMENT_MCP_CALLS_PER_MINUTE', 60),

    /*
    | The longest a search_data call reads records, in seconds: then it answers with what it has
    | and next_cursor to read on. 0: no limit (the time of PHP only).
    */
    'mcp_search_seconds' => env('EXMENT_MCP_SEARCH_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | Show Select field with group option
    |--------------------------------------------------------------------------
    |
    | Show Select field with group option
    |
    */
    'form_column_option_group' => env('EXMENT_FORM_COLUMN_OPTION_GROUP', false),

    
    /*
    |--------------------------------------------------------------------------
    | Workflow show id
    |--------------------------------------------------------------------------
    |
    | If true, show wofkflow's id.
    |
    */
    'show_workflow_id' => env('EXMENT_SHOW_WORKFLOW_ID', false),

    /*
    |--------------------------------------------------------------------------
    | Specifies that the select box of role_group should be sorted by order column
    |--------------------------------------------------------------------------
    |
    | If true, sort select box of organizations by default view.
    |
    */
    'sort_org_by_default_view' => env('EXMENT_SORT_ORG_BY_DEFAULT_VIEW', false),

    /*
    |--------------------------------------------------------------------------
    | Specifies that the select box of role_group should be sorted by order column
    |--------------------------------------------------------------------------
    |
    | If true, sort select box of role_group by order column.
    |
    */
    'sort_role_group_by_order' => env('EXMENT_SORT_ROLE_GROUP_BY_ORDER', false),

    /*
    |--------------------------------------------------------------------------
    | class type of default show page 
    |--------------------------------------------------------------------------
    |
    | set class for container of default show page.
    | 1. only option boxes(default).
    | 2. only show form.
    | 3. both option boxes and show form.
    |
    */
    'show_page_class_type' => env('EXMENT_SHOW_PAGE_CLASS_TYPE', 1),

    /*
    |--------------------------------------------------------------------------
    | Maximum length of strings to display in the grid
    |--------------------------------------------------------------------------
    |
    */
    'grid_mat_length' => env('EXMENT_GRID_MAX_LENGTH', 50),

    /*
    |--------------------------------------------------------------------------
    | Specify custom view sorting options
    |--------------------------------------------------------------------------
    |
    | 0(default):view_type > view_kind_type > id.
    | 1:view_type > view_kind_type > order.
    | 2:view_type > order.
    |
    */
    'sort_custom_view_options' => env('EXMENT_SORT_CUSTOM_VIEW_OPTIONS', 0),

    /*
    |--------------------------------------------------------------------------
    | Maintain default view with freeword search for each table
    |--------------------------------------------------------------------------
    |
    */
    'search_keep_default_view' => env('EXMENT_SEARCH_KEEP_DEFAULT_VIEW', false),

    /*
    |--------------------------------------------------------------------------
    | Default english text for qr code button
    |--------------------------------------------------------------------------
    |
    */
    'text_qr_button_en' => env('EXMENT_TEXT_QR_BUTTON_EN', '2D barcode'),

    /*
    |--------------------------------------------------------------------------
    | Default japanese text for qr code button
    |--------------------------------------------------------------------------
    |
    */
    'text_qr_button_ja' => env('EXMENT_TEXT_QR_BUTTON_JA', '二次元バーコード'),

    /*
    |--------------------------------------------------------------------------
    | show import export button on role group view
    |--------------------------------------------------------------------------
    |
    */
    'role_group_import_export' => env('ROLE_GROUP_IMPORT_EXPORT', false),

    /*
    |--------------------------------------------------------------------------
    | Notify webhook: deny private networks
    |--------------------------------------------------------------------------
    |
    | Slack / Teams webhooks are never sent to the server itself or to the
    | link-local range. With true, private networks (10/8, 172.16/12,
    | 192.168/16, fc00::/7) are refused too, as on a shared service.
    |
    */
    'notify_webhook_deny_private' => env('EXMENT_NOTIFY_WEBHOOK_DENY_PRIVATE', false),

    // hosts webhooks may be sent to although the address is refused above
    // (comma separated), e.g. "localhost,chat.internal"
    'notify_webhook_allow_hosts' => env('EXMENT_NOTIFY_WEBHOOK_ALLOW_HOSTS', ''),

    /*
    |--------------------------------------------------------------------------
    | X-Frame-Options of the admin pages
    |--------------------------------------------------------------------------
    |
    | SAMEORIGIN: other sites may not show the admin pages in a frame.
    | DENY: no frame at all. Empty: the header is not sent.
    | (Public forms do not get it, so they can be embedded.)
    |
    */
    'x_frame_options' => env('EXMENT_X_FRAME_OPTIONS', 'SAMEORIGIN'),
    /*
    |--------------------------------------------------------------------------
    | Unavailable user view ( for tables )
    |--------------------------------------------------------------------------
    |
    | Even if 'userview_disabled' is false
    */
    'userview_unavailable_table' => env('EXMENT_USERVIEW_UNAVAILABLE_TABLE', ''),

    /*
    |--------------------------------------------------------------------------
    | Default english text for SCAN code button
    |--------------------------------------------------------------------------
    |
    */
    'text_scan_button_en' => env('EXMENT_TEXT_SCAN_BUTTON_EN', '2D barcode／JANcode'),

    /*
    |--------------------------------------------------------------------------
    | Default japanese text for SCAN code button
    |--------------------------------------------------------------------------
    |
    */
    'text_scan_button_ja' => env('EXMENT_TEXT_SCAN_BUTTON_JA', '二次元／JANバーコード'),

    /*
    |--------------------------------------------------------------------------
    | allow the deletion of attachments even when deletion from form is restricted.
    |--------------------------------------------------------------------------
    |
    */
    'allow_delete_attachment' => env('EXMENT_ALLOW_DELETE_ATTACHMENT', false),
    
    /*
    |--------------------------------------------------------------------------
    | API rate limit per minute
    |--------------------------------------------------------------------------
    |
    */
    'api_max_rate_limit' => env('EXMENT_API_MAX_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Temporary raises of the API rate limit (ISS00442)
    |--------------------------------------------------------------------------
    | The page "API rate limit raises" (the feature api_rate_raise, admin settings → API):
    | a system administrator lets a user or an API client make more requests per minute
    | until a time, for an import or a move of data.
    | api_max_rate_limit_ceiling: the most requests per minute a raise may give (a SaaS sets
    |   it for its tenants). Empty: no ceiling (on premises).
    | api_rate_raise_max_hours: the longest a raise may last, in hours (default 24).
    */
    'api_max_rate_limit_ceiling' => env('EXMENT_API_MAX_RATE_LIMIT_CEILING'),
    'api_rate_raise_max_hours' => env('EXMENT_API_RATE_RAISE_MAX_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | The files of the application log (ISS02839)
    |--------------------------------------------------------------------------
    | The mode of a new file of the application log (octal): 0640, the owner writes, its group
    | reads, nobody else. A server whose cron runs as another user than the web server and
    | writes the same file needs 0660.
    */
    'log_file_permission' => env('EXMENT_LOG_FILE_PERMISSION', '0640'),

    /*
    |--------------------------------------------------------------------------
    | How the records of the logs are written (ISS02903)
    |--------------------------------------------------------------------------
    | The operation log, the logins and the last use of an API key, without making the response
    | wait. "after_response" (the default): after the response is sent. "queue": by the queue
    | (QUEUE_CONNECTION other than sync; without one, after_response). "sync": at once, as before.
    | A record that cannot be written is kept in storage/logs/exment-log-fallback.log.
    */
    'log_write_mode' => env('EXMENT_LOG_WRITE_MODE', 'after_response'),

    /*
    |--------------------------------------------------------------------------
    | The database of the logs, their size (ISS02905)
    |--------------------------------------------------------------------------
    | log_db_connection: a connection of config/database.php for the log tables (login_logs,
    | permission_change_logs, browser_logs, data_access_logs, data_access_daily), so that the logs
    | do not fill the database of the data. Empty: the default connection. A site that has the
    | tables already: php artisan exment:log-db --copy after setting it.
    | log_size_warn_mb: a warning on the log management page and in the security check when all
    | the logs (tables, files, archives) are larger. 0: no warning.
    */
    'log_db_connection' => env('EXMENT_LOG_DB_CONNECTION'),
    'log_size_warn_mb' => env('EXMENT_LOG_SIZE_WARN_MB', 1024),

    /*
    |--------------------------------------------------------------------------
    | Mask targets for the operation log
    |--------------------------------------------------------------------------
    |
    | Request keys and url segments replaced with "***" when writing the
    | operation log (admin_operation_log). Add or remove a mask target here,
    | without touching the source.
    |
    | WHEN UPGRADING: re-check this block. config/exment.php is published once
    | per install and "exment:update" does not refresh it, and Laravel's
    | mergeConfigFrom() merges top-level keys only - so once an install has its
    | own "operation_log" block, that copy wins as a whole and mask targets
    | added by a later Exment release do NOT reach that install.
    |
    | Because of that, the credentials that authenticate a user or a session
    | (password / password_confirmation / current_password / _token /
    | verify_code / access_token / refresh_token) are not listed below at all:
    | they are owned by requiredHideColumns() in
    | Exceedone\Exment\Middleware\LogOperation and stay masked on every install
    | whatever this file says. Every other mask target - including the
    | auth/reset url - is owned by this file, and lives in exactly one place.
    |
    */
    'operation_log' => [

        /*
         * Keys masked on EVERY screen. Only names that are sensitive wherever
         * they appear belong here: a name listed here is masked even when it is
         * a business column of a user-defined table. Keys that are credentials
         * only on a specific screen go to "mask_columns_by_uri" instead.
         *
         * The session credentials (password / password_confirmation /
         * current_password / _token / token / verify_code / access_token /
         * refresh_token) are NOT repeated here: they are owned by
         * LogOperation::requiredHideColumns() and always masked. Do not add them
         * back - a name in both places could be edited here and look disabled
         * while the class keeps masking it.
         */
        'mask_columns' => [
            // SSO (OAuth / SAML) secrets
            'oauth_client_id',
            'oauth_client_secret',
            'saml_sp_privatekey',
            // System config secrets (admin/system): reCAPTCHA secret, SMTP password
            'recaptcha_secret_key',
            'system_mail_password',
            // Meilisearch API key (admin/system)
            'meili_key',
            // LINE Messaging API channel credentials (admin/system?advanced=1)
            'system_line_channel_token',
            'system_line_channel_secret',
            // the address of the Teams workflow (ISS02863)
            'system_teams_workflow_url',
            'system_teams_workflow_url_new',
            // Plugin DB connection password
            'custom_password',
            // Plugin CRUD page auth (key / id+password)
            'crud_auth_key',
            'crud_auth_password',
        ],

        /*
         * "uri pattern below the admin prefix" => keys masked only when the
         * request path matches ("*" is a wildcard, Str::is). Use this for names
         * that are credentials on one screen but plain business data elsewhere,
         * which therefore must not go into "mask_columns".
         */
        'mask_columns_by_uri' => [
            // API client setting screens (admin/api_setting*). "id" IS the oauth
            // client_id and "secret" the client secret (posted by the edit form /
            // grid filter). "client_api_key" is the real api key, posted nested as
            // client_api_key[key] only by this form's save. None may be masked
            // globally - a business table can legitimately own id/secret columns.
            'api_setting*' => ['id', 'secret', 'client_api_key'],
            // OAuth token endpoints (admin/oauth/*): client_id / client_secret /
            // api_key are posted as credentials here (grant_type: api_key /
            // client_credentials / password). They are plain business data on
            // user-defined tables, so they are masked only on this URI.
            'oauth/*' => ['client_id', 'api_key', 'client_secret'],
            // Password reset form (admin/auth/reset/{token}) posts the raw reset
            // token back as a hidden field named "token" (see auth/reset.blade.php
            // and ResetPasswordController::rules()). "token" is far too generic to
            // mask globally - a user-defined table may well own a "token" column -
            // and this is the only screen that posts it as a credential.
            'auth/reset*' => ['token'],
        ],

        /*
         * "uri prefix below the admin prefix" => whether to keep the first 8
         * characters of the path segment that follows it. Use true for record
         * ids, so the record can still be traced (the prefix is kept only when
         * the segment is longer than 12 characters, e.g. a uuid), and false for
         * bearer credentials, which are masked whole.
         *
         * This list is the only source: nothing here is hard-coded as a fallback,
         * so removing an entry really does stop masking that url.
         */
        'mask_path_prefixes' => [
            'api_setting' => true,
            // Passport client management API: oauth/clients/{client_id}
            'oauth/clients' => true,
            // Password reset url: auth/reset/{token}. Both GET (mail link) and
            // POST (reset form action) carry the raw token in the path, and that
            // route group includes admin.log, so the token reaches the logged
            // path. It is a bearer credential, hence false: masked whole, with no
            // traceability prefix kept. DO NOT remove or set to true.
            'auth/reset' => false,
        ],
    ],

    'line' => [
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
        'channel_secret'       => env('LINE_CHANNEL_SECRET'),
        'oa_basic_id'          => env('LINE_OA_BASIC_ID'),
        'api_base'             => env('LINE_API_BASE', 'https://api.line.me'),
        'timeout'              => env('LINE_HTTP_TIMEOUT', 10),
        'dedupe_minutes'       => env('LINE_DEDUPE_MINUTES', 0),
        'link_code_ttl_minutes'      => env('LINE_LINK_CODE_TTL_MINUTES', 10),
        'link_max_attempts'          => env('LINE_LINK_MAX_ATTEMPTS', 5),
        'link_attempt_decay_minutes' => env('LINE_LINK_ATTEMPT_DECAY_MINUTES', 10),
    ],

    'safety_check' => [
        'feed_url'   => env('EXMENT_SAFETY_CHECK_FEED_URL', 'https://api.p2pquake.net/v2/history'),
        'feed_limit' => env('EXMENT_SAFETY_CHECK_FEED_LIMIT', 10),
        // how often the earthquake feed is watched, in minutes: 1, 5, 10 or 15 (default 5).
        // 5 is enough for a safety check after a quake, and lets "schedule:run" go every 5 minutes.
        'watch_interval' => env('EXMENT_SAFETY_CHECK_WATCH_INTERVAL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled jobs (ISS02832)
    |--------------------------------------------------------------------------
    | The page "Scheduled jobs" (the feature scheduled_jobs) keeps the runs of each job.
    | keep_runs: how many of the last runs of each job are kept (default 20).
    | alert_failures: a job whose last runs failed this many times in a row is shown red,
    |   and the system administrators are told once (the bell and a mail) (default 3).
    */
    'scheduled_jobs' => [
        'keep_runs' => env('EXMENT_SCHEDULED_JOBS_KEEP_RUNS', 20),
        'alert_failures' => env('EXMENT_SCHEDULED_JOBS_ALERT_FAILURES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | File server (ISS00293)
    |--------------------------------------------------------------------------
    | file_server_workspace_create: who may make a workspace. "admin" (system permission; the default) or "all".
    |   A new feature is not open to all users until an administrator says so.
    | file_server_max_file_size: the largest file, in bytes (default 2 GB; 0: no limit but the space of the workspace).
    | file_server_max_uploads: the uploads in parts a user may have not finished at the same time.
    | file_server_max_upload_bytes: the bytes those uploads may say they will send, all together (default 10 GB; 0: no limit).
    | file_server_soffice_prefix: a command put before LibreOffice, to run it without the network
    |   (e.g. "unshare -rn" or "firejail --net=none --quiet"). Office files may link to the outside:
    |   run LibreOffice where it cannot reach the network (ISS00433).
    | file_server_soffice_path: LibreOffice (soffice) to make the PDF preview of Office files. Empty: no preview.
    | file_server_soffice_url: a converter of its own on the inner network instead (Gotenberg with LibreOffice,
    |   e.g. http://office:3000; docker-exment's docker-compose.office.yml). Used before soffice_path (ISS01704).
    |   Put the converter on a network that cannot go out (an Office file may fetch linked pictures or objects):
    |   no port to the outside, no Internet, no cloud metadata, no database; and start Gotenberg with
    |   --libreoffice-deny-private-ips --libreoffice-deny-public-ips --api-disable-download-from --webhook-disable.
    |   With Basic auth on Gotenberg: http://user:password@office:3000 (sent as a header, not in the address).
    |   Not followed to another address (no redirect). file_server_soffice_max_pdf_bytes: the largest PDF taken.
    */
    'file_server_workspace_create' => env('EXMENT_FILE_SERVER_WORKSPACE_CREATE', 'admin'),
    'file_server_max_file_size' => env('EXMENT_FILE_SERVER_MAX_FILE_SIZE', 2147483648),
    'file_server_max_uploads' => env('EXMENT_FILE_SERVER_MAX_UPLOADS', 20),
    'file_server_max_upload_bytes' => env('EXMENT_FILE_SERVER_MAX_UPLOAD_BYTES', 10737418240),
    'file_server_soffice_prefix' => env('EXMENT_FILE_SERVER_SOFFICE_PREFIX'),
    // the shortest password of a share link; whether /share/{token} takes the IP filter of the web too
    // (read when the routes are made: run "php artisan route:cache" again after changing it, when the routes are cached)
    'file_server_share_password_min' => env('EXMENT_FILE_SERVER_SHARE_PASSWORD_MIN', 8),
    'file_server_share_web_ip_filter' => env('EXMENT_FILE_SERVER_SHARE_WEB_IP_FILTER', false),
    'file_server_soffice_path' => env('EXMENT_FILE_SERVER_SOFFICE_PATH'),
    'file_server_soffice_url' => env('EXMENT_FILE_SERVER_SOFFICE_URL'),
    'file_server_soffice_max_pdf_bytes' => env('EXMENT_FILE_SERVER_SOFFICE_MAX_PDF_BYTES', 209715200),
    'file_server_soffice_timeout' => env('EXMENT_FILE_SERVER_SOFFICE_TIMEOUT', 120),
    // share links for people outside (phase 3): false stops them.
    // file_server_share_max_days: the latest end of a new link, in days (0: none, an end is not needed).
    // file_server_share_default_days: the end of a new link given none, and the date the dialog starts with
    // (0: no end, when max_days allows it). The links made before keep their end, or none.
    'file_server_external_share' => env('EXMENT_FILE_SERVER_EXTERNAL_SHARE', true),
    'file_server_share_max_days' => env('EXMENT_FILE_SERVER_SHARE_MAX_DAYS', 90),
    'file_server_share_default_days' => env('EXMENT_FILE_SERVER_SHARE_DEFAULT_DAYS', 30),
    'file_server_share_path' => env('EXMENT_FILE_SERVER_SHARE_PATH', 'share'),
    // the limits of each request link (0: none), and the types it does not take (ISS00410)
    'file_server_request_max_files' => env('EXMENT_FILE_SERVER_REQUEST_MAX_FILES', 100),
    'file_server_request_max_bytes' => env('EXMENT_FILE_SERVER_REQUEST_MAX_BYTES', 1073741824),
    'file_server_request_max_file_size' => env('EXMENT_FILE_SERVER_REQUEST_MAX_FILE_SIZE', 104857600),
    // empty: the list of ShareLinks::DEFAULT_DENIED_EXTENSIONS. allow: only these types (empty: all but the denied)
    'file_server_request_deny_extensions' => env('EXMENT_FILE_SERVER_REQUEST_DENY_EXTENSIONS', ''),
    'file_server_request_allow_extensions' => env('EXMENT_FILE_SERVER_REQUEST_ALLOW_EXTENSIONS', ''),
    // the largest folder downloaded as a zip, in bytes (0: no limit); the largest image a thumbnail is made of, in pixels
    'file_server_zip_max_bytes' => env('EXMENT_FILE_SERVER_ZIP_MAX_BYTES', 2147483648),
    // 0: no limit. About 4 bytes a pixel are used, so the default fits a memory_limit of 128M
    'file_server_preview_max_pixels' => env('EXMENT_FILE_SERVER_PREVIEW_MAX_PIXELS', 20000000),
    // the largest file made into a preview by Imagick or LibreOffice, in bytes (0: no limit)
    'file_server_preview_max_bytes' => env('EXMENT_FILE_SERVER_PREVIEW_MAX_BYTES', 104857600),
    // the search (phase 4): the text of the files. PDF needs pdftotext (poppler-utils; found in /usr/bin when empty);
    // OCR of images and scanned PDF needs tesseract (only when its path is set) and its languages
    'file_server_text_max_chars' => env('EXMENT_FILE_SERVER_TEXT_MAX_CHARS', 1000000),
    'file_server_text_timeout' => env('EXMENT_FILE_SERVER_TEXT_TIMEOUT', 120),
    'file_server_pdftotext_path' => env('EXMENT_FILE_SERVER_PDFTOTEXT_PATH'),
    'file_server_ocr_path' => env('EXMENT_FILE_SERVER_OCR_PATH'),
    'file_server_ocr_lang' => env('EXMENT_FILE_SERVER_OCR_LANG', 'jpn+eng'),
    'file_server_ocr_max_pages' => env('EXMENT_FILE_SERVER_OCR_MAX_PAGES', 10),
    // true: search in Meilisearch (the host of config/meilisearch.php); the database when it cannot be reached.
    // After it is turned on: php artisan exment:file-server-index
    'file_server_meili' => env('EXMENT_FILE_SERVER_MEILI', false),
    'file_server_meili_index' => env('EXMENT_FILE_SERVER_MEILI_INDEX', 'exment_files'),

    /*
    |--------------------------------------------------------------------------
    | File server, phase 5 (ISS00379)
    |--------------------------------------------------------------------------
    | audit: write who did what. audit_days: remove the older lines (0: keep all).
    | notify: tell the followers (the bell); notify_mail: and send mails.
    */
    'file_server_audit' => env('EXMENT_FILE_SERVER_AUDIT', true),
    'file_server_audit_days' => env('EXMENT_FILE_SERVER_AUDIT_DAYS', 0),
    'file_server_notify' => env('EXMENT_FILE_SERVER_NOTIFY', true),
    'file_server_notify_mail' => env('EXMENT_FILE_SERVER_NOTIFY_MAIL', true),
];
