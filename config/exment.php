<?php

return [

    'locale' => env('APP_LOCALE', config('app.locale')),

    'timezone' => env('APP_TIMEZONE', config('app.timezone')),

    'system_locale_options' => env('EXMENT_SYSTEM_LOCALE_OPTIONS'),



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
    | Document upload max count one request
    |--------------------------------------------------------------------------
    |
    */
    'document_upload_max_count' => env('EXMENT_DOCUMENT_UPLOAD_MAX_COUNT', 5),

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
];
