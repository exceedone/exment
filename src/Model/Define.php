<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemColumn;

/**
 * Define short summary.
 *
 * Define description.
 *
 * @version 1.0
 * @author h-sato
 */
class Define
{
    public const COMPOSER_PACKAGE_NAME = 'exceedone/exment';
    public const COMPOSER_PACKAGE_NAME_EXMENT_ADMIN = 'exceedone/exment-admin-core';
    public const COMPOSER_VERSION_CHECK_URL = 'https://packagist.org/packages/exceedone/exment.json';
    // A static JSON file in the WordPress REST API format (the WordPress path /wp-json/wp/v2/posts redirects here).
    // It takes no query, so Exment picks the news itself (News::filterItems()).
    public const EXMENT_NEWS_API_URL = 'https://exment.net/api/posts.json';
    public const EXMENT_NEWS_LINK = 'https://exment.net/category/news';
    public const USER_IMAGE_LINK = 'vendor/exment/images/user.png';
    public const ORGANIZATION_IMAGE_LINK = 'vendor/exment/images/organization.png';

    public const RULES_REGEX_VALUE_FORMAT = '\${(.*?)\}';
    public const RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN = '^[a-zA-Z0-9\-_]*$';
    public const RULES_REGEX_SYSTEM_NAME = '^(?=[a-zA-Z])(?!.*[-_]$)[-_a-zA-Z0-9]+$';
    public const RULES_REGEX_LINK_FORMAT = "|<a href=[\"'](.*?)[\"'].*?>(.*?)</a>|mis";
    public const RULES_REGEX_BACKUP_FILENAME = '[ぁ-んァ-ヶ亜-熙a-zA-Z0-9]+';

    /**
     * Input keys not to flash to the session when redirecting with input
     */
    public const DONT_FLASH_INPUT_KEYS = ['password', 'password_confirmation', 'current_password'];

    public const DELETE_CONFIRM_KEYWORD = 'delete me';
    public const RESTORE_CONFIRM_KEYWORD = 'restore me';
    // file in a backup zip recording the Exment / Laravel versions it was taken on
    public const BACKUP_VERSION_FILE = 'backup_version.json';
    public const YES_KEYWORD = 'yes';

    public const API_FEATURE_TEST = 'API_FEATURE_TEST';
    public const API_FEATURE_TEST_APIKEY = 'API_FEATURE_TEST_APIKEY';

    public const MAX_SIZE_NUMBER = 1000000000000;
    public const MAX_FLOAT_PRECISION = 14;

    public const SYSTEM_SETTING_NAME_VALUE = [
        'initialized' => ['type' => 'boolean', 'default' => '0'],
        'system_admin_users' => ['type' => 'array'],

        // initialize ----------------------------------
        'site_name' => ['default' => 'Exment', 'group' => 'initialize'],
        'site_name_short' => ['default' => 'Exm', 'group' => 'initialize'],
        'site_logo' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_logo_mini' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_favicon' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_skin' => ['config' => 'admin.skin', 'group' => 'initialize'],
        'site_layout' => ['default' => 'layout_default', 'group' => 'initialize'],
        'ui_design' => ['default' => 'modern', 'group' => 'initialize'],

        'api_available' => ['type' => 'boolean', 'config' => 'exment.api', 'group' => 'initialize'],
        'outside_api' => ['type' => 'boolean', 'group' => 'initialize', 'default' => true],
        'permission_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],
        'organization_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],
        'logging_toggle_available' => ['type' => 'boolean', 'default' => '0', 'group' => 'initialize'],
        // system performance log: save only slow requests (seconds; both 0 = every request), at most N logs in an hour (0 = no limit)
        'logging_slow_request_seconds' => ['type' => 'int', 'default' => '3', 'group' => 'initialize'],
        'logging_slow_sql_seconds' => ['type' => 'int', 'default' => '1', 'group' => 'initialize'],
        'logging_max_per_hour' => ['type' => 'int', 'default' => '100', 'group' => 'initialize'],

        // Advanced ----------------------------------
        'filter_search_type' => ['default' => 'forward', 'group' => 'advanced'],

        'default_date_format' => ['default' => 'format_default', 'group' => 'advanced'],
        'grid_pager_count' => ['type' => 'int', 'default' => '20', 'group' => 'advanced'],
        'datalist_pager_count' => ['type' => 'int', 'default' => '5', 'group' => 'advanced'],
        'data_submit_redirect' => ['type' => 'string', 'default' => null, 'group' => 'advanced'],
        'header_user_info' => ['type' => 'array', 'default' => SystemColumn::CREATED_AT, 'group' => 'advanced'],
        // the languages the users can choose (ISS02835). Empty: all the installed languages
        'allowed_locales' => ['type' => 'array', 'default' => null, 'group' => 'advanced'],
        // the language of the browser for a user who has not chosen one (ISS02835). On by default
        'locale_from_browser' => ['type' => 'boolean', 'default' => '1', 'group' => 'advanced'],
        // inline edit of the data list (#2163). On by default, as before
        'grid_inline_edit_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'advanced'],

        // name is "flg", but array is OK.
        'grid_filter_disable_flg' => ['type' => 'array', 'default' => 'comment', 'group' => 'advanced'] ,

        'system_values_pos' => ['default' => 'top', 'group' => 'advanced'],

        'web_ip_filters' => ['default' => '', 'group' => 'advanced'] ,
        'api_ip_filters' => ['default' => '', 'group' => 'advanced'] ,

        'userview_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],
        'userdashboard_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],

        // public form ----------------------------------------------------
        'publicform_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],
        // the features an administrator turns on (Services\Features; Admin setting → Feature management)
        'features' => ['type' => 'json', 'default' => []],
        // the menu before "Arrange in the standard order" (Services\MenuArranger), for undoing it
        'menu_arrange_backup' => ['type' => 'json', 'default' => []],
        // maintenance mode of the site (Services\MaintenanceMode)
        'maintenance' => ['type' => 'json', 'default' => []],
        // an exment:schedule skipped in a maintenance, to run after it
        'maintenance_missed_schedule' => ['default' => null],
        // the id of the site (a UUID made once; the market and the licenses of the plugins know the site by it)
        'site_id' => ['type' => 'string', 'default' => null],
        // the notices told to the system administrators (Services\PluginDistribution\AdminNotices)
        'admin_notices_sent' => ['type' => 'json', 'default' => []],
        // the scheduled jobs told to the system administrators as failing, until they run well (Services\ScheduledJobs\Recorder)
        'scheduled_job_alerted' => ['type' => 'json', 'default' => []],
        'recaptcha_type' => ['type' => 'string', 'group' => 'advanced'],
        'recaptcha_site_key' => ['type' => 'password', 'group' => 'advanced'],
        'recaptcha_secret_key' => ['type' => 'password', 'group' => 'advanced'],

        'complex_password' => ['type' => 'boolean', 'group' => 'login', 'default' => false],
        'password_expiration_days' => ['type' => 'int', 'default' => '0', 'group' => 'login'],
        'first_change_password' => ['type' => 'boolean', 'group' => 'login', 'default' => false],
        'password_history_cnt' => ['type' => 'int', 'default' => '0', 'group' => 'login'],
        // a notice of a login from a new IP address (ISS00104)
        'login_notify_new_ip' => ['type' => 'boolean', 'default' => '0', 'group' => 'login'],
        'login_notify_new_ip_mail' => ['type' => 'boolean', 'default' => '0', 'group' => 'login'],

        'login_background_color' => ['type' => 'string', 'default' => '#d2d6de', 'group' => 'login'],
        'login_page_image' => ['type' => 'file', 'move' => 'system', 'group' => 'login'],
        'login_page_image_type' => ['type' => 'string', 'default' => 'repeat', 'group' => 'login'],

        'show_default_login_provider' => ['type' => 'boolean', 'default' => '1', 'group' => 'login'],
        'sso_redirect_force' => ['type' => 'boolean', 'default' => '0', 'group' => 'login'],
        'sso_jit' => ['type' => 'boolean', 'default' => '0', 'group' => 'login'],
        'sso_accept_mail_domain' => ['default' => '', 'group' => 'login'],
        'jit_rolegroups' => ['type' => 'array', 'default' => '', 'group' => 'login'],

        // org_joined_type
        'org_joined_type_role_group' => ['type' => 'int', 'default' => '99', 'group' => 'advanced'],
        'org_joined_type_custom_value' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],
        'custom_value_save_autoshare' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],
        'filter_multi_user' => ['type' => 'int', 'default' => '-1', 'group' => 'advanced'],

        // Meilisearch (full-text search) — 'config' maps let get_system_value fall back to .env.
        // Group "meili": the page of its own (MeiliSettingController, #2157), not the detail page.
        'meili_host' => ['config' => 'meilisearch.host', 'group' => 'meili'],
        'meili_key' => ['type' => 'password', 'config' => 'meilisearch.key', 'group' => 'meili'],
        'meili_index' => ['config' => 'meilisearch.index', 'group' => 'meili'],
        'meili_global_search' => ['type' => 'boolean', 'config' => 'meilisearch.global_search', 'group' => 'meili'],
        'meili_realtime_sync' => ['type' => 'boolean', 'config' => 'meilisearch.realtime_sync', 'group' => 'meili'],
        'meili_batch_size' => ['type' => 'int', 'config' => 'meilisearch.batch_size', 'group' => 'meili'],
        'meili_repair_enabled' => ['type' => 'boolean', 'config' => 'meilisearch.repair_enabled', 'group' => 'meili'],
        'meili_repair_at' => ['config' => 'meilisearch.repair_at', 'group' => 'meili'],
        'meili_filter_mode' => ['config' => 'meilisearch.filter.mode', 'group' => 'meili'],
        'meili_indexed_at' => ['type' => 'json', 'group' => 'meili_state'],


        // notify
        'system_mail_host' => ['config' => 'mail.host', 'group' => 'notify'],
        'system_mail_port' => ['config' => 'mail.port', 'group' => 'notify'],
        'system_mail_username' => ['config' => 'mail.username', 'group' => 'notify'],
        'system_mail_password' => ['type' => 'password', 'config' => 'mail.password', 'group' => 'notify'],
        'system_mail_encryption' => ['config' => 'mail.encryption', 'group' => 'notify'],
        'system_mail_from' => ['default' => 'no-reply@hogehoge.com', 'group' => 'notify'],
        'system_mail_from_view_name' => ['group' => 'notify'],
        'system_mail_body_type' => ['default' => 'html', 'group' => 'notify'],

        'system_slack_user_column' => ['group' => 'notify'],

        'system_line_channel_token' => ['type' => 'password', 'config' => 'exment.line.channel_access_token', 'group' => 'notify'],
        'system_line_channel_secret' => ['type' => 'password', 'config' => 'exment.line.channel_secret', 'group' => 'notify'],
        // the address of the Teams workflow (ISS02863): a secret, never shown again
        'system_teams_workflow_url' => ['type' => 'password', 'group' => 'notify'],
        'system_line_oa_basic_id' => ['config' => 'exment.line.oa_basic_id', 'group' => 'notify'],

        // Group "safety_check": the page of its own (SafetyCheckSettingController, #2157)
        'safety_check_auto_enabled' => ['type' => 'boolean', 'default' => '0', 'group' => 'safety_check'],
        'safety_check_min_scale' => ['type' => 'int', 'default' => '45', 'group' => 'safety_check'],
        'safety_check_cooldown_minutes' => ['type' => 'int', 'default' => '60', 'group' => 'safety_check'],
        'safety_check_comment_window_minutes' => ['type' => 'int', 'default' => '60', 'group' => 'safety_check'],
        'safety_check_max_bulletin_age_minutes' => ['type' => 'int', 'default' => '10', 'group' => 'safety_check'],
        'safety_check_resend_throttle_minutes' => ['type' => 'int', 'default' => '5', 'group' => 'safety_check'],
        'safety_check_answer_url_expire_days' => ['type' => 'int', 'default' => '7', 'group' => 'safety_check'],
        // 送信対象 (GitHub #2180): empty is every user
        'safety_check_target_organizations' => ['type' => 'array', 'default' => null, 'group' => 'safety_check'],
        'safety_check_target_view' => ['type' => 'int', 'default' => null, 'group' => 'safety_check'],
        'safety_check_excluded_users' => ['type' => 'array', 'default' => null, 'group' => 'safety_check'],
        'safety_check_last_feed_time' => ['type' => 'datetime'],
        // Comments written before this time ('Y-m-d H:i:s') are shown as the plain text they were
        // written as; later ones are shown as Markdown. Set once, by the migration that brings
        // Markdown comments. Not set: every comment is Markdown.
        'comment_markdown_start' => [],

        // Backup
        'backup_enable_automatic' => ['type' => 'boolean', 'default' => '0', 'group' => 'backup'],
        'backup_automatic_term' => ['type' => 'int', 'default' => '1', 'group' => 'backup'],
        'backup_automatic_hour' => ['type' => 'int', 'default' => '3', 'group' => 'backup'],
        'backup_target' => ['type' => 'array', 'default' => 'database,plugin,attachment,log,config', 'group' => 'backup'] ,
        'backup_automatic_executed' => ['type' => 'datetime'],
        'backup_history_files' => ['type' => 'int', 'default' => '0', 'group' => 'backup'],

        // Operation log auto-delete
        // Opt-in (default '0') like backup_enable_automatic above: a destructive housekeeping task
        // must not silently purge audit logs on a fresh install without an explicit admin choice.
        'operation_log_enable_automatic' => ['type' => 'boolean', 'default' => '0', 'group' => 'operation_log'],
        'operation_log_keep_days' => ['type' => 'int', 'default' => '180', 'group' => 'operation_log'],
        'operation_log_automatic_week' => ['group' => 'operation_log'],
        'operation_log_automatic_month' => ['group' => 'operation_log'],
        'operation_log_automatic_day' => ['group' => 'operation_log'],
        'operation_log_automatic_hour' => ['group' => 'operation_log'],
        'operation_log_automatic_minute' => ['group' => 'operation_log'],
        'operation_log_automatic_executed' => ['type' => 'datetime'],
        // the days the logs are kept (ISS02839, Services\LogManagement\LogCatalog); empty: the default
        'log_retention_login_log' => ['type' => 'int', 'group' => 'log_management'],
        'log_retention_permission_change' => ['type' => 'int', 'group' => 'log_management'],
        'audit_report_schedule' => ['default' => 'off', 'group' => 'log_management'],
        'audit_report_mail' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'log_retention_app_log' => ['type' => 'int', 'group' => 'log_management'],
        'log_app_level' => ['group' => 'log_management'],
        'log_app_daily' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'operation_log_scope' => ['default' => 'all', 'group' => 'log_management'],
        // the archive of the old logs before they are deleted (part 3)
        'log_archive_enabled' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'log_archive_driver' => ['default' => 'local', 'group' => 'log_management'],
        'log_archive_years' => ['type' => 'int', 'group' => 'log_management'],
        // what the logs keep of personal data (part 4)
        'log_store_input' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'log_ip_mode' => ['default' => 'keep', 'group' => 'log_management'],
        'log_archive_encrypt' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'mail_log_no_body' => ['type' => 'boolean', 'default' => '0', 'group' => 'log_management'],
        'log_privacy_notice_shown' => ['type' => 'boolean', 'default' => '0'],

        // 2factor ----------------------------------
        'login_use_2factor' => ['type' => 'boolean', 'default' => '0', 'group' => '2factor'],
        'login_2factor_provider' => ['default' => 'email', 'group' => '2factor'],
    ];

    public const SYSTEM_SKIN = [
        "skin-blue",
        "skin-blue-light",
        "skin-yellow",
        "skin-yellow-light",
        "skin-green",
        "skin-green-light",
        "skin-purple",
        "skin-purple-light",
        "skin-red",
        "skin-red-light",
        "skin-black",
        "skin-black-light",
    ];

    /**
     * Screen designs. The first one is the default.
     */
    public const SYSTEM_UI_DESIGN = [
        'modern',
        'classic',
    ];

    /** Cookie holding how the sidebar was last left: "collapsed" or "expanded". */
    public const COOKIE_SIDEBAR_STATE = 'exment_sidebar';

    public const SYSTEM_LAYOUT = [
        'layout_default' => ['sidebar-mini'],
        'layout_mini' => ['sidebar-collapse', 'sidebar-mini'],
    ];

    public const SYSTEM_DATE_FORMAT = [
        'format_default',
        'format_slash',
        'format_local',
    ];

    public const CACHE_CLEAR_MINUTE = 3600;
    public const SYSTEM_KEY_SESSION_SYSTEM_CONFIG = "setting.%s";
    public const SYSTEM_KEY_SESSION_INITIALIZE = "initialize";
    public const SYSTEM_KEY_SESSION_INITIALIZE_INPUTS = "initialize_inputs";
    public const SYSTEM_KEY_SESSION_AUTHORITY = "role";
    public const SYSTEM_KEY_SESSION_USER_SETTING = "user_setting";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION = "system_version";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE = "system_version_execute";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS = "organization_ids";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS_ORG = "organization_ids_org_%s_%s";
    public const SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID = "file_uploaded_uuid";
    public const SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_ORGS = "table_accessible_orgs_%s";
    public const SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS = "table_accessible_users_%s";
    public const SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS_ORGS = "table_accessible_users_orgs_%s";
    public const SYSTEM_KEY_SESSION_VALUE_ACCRSSIBLE_USERS = "value_accessible_users_%s_%s";
    public const SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE = "can_connection_database";
    public const SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES = "all_database_table_names";
    public const SYSTEM_KEY_SESSION_ALL_RECORDS = "all_records_%s";
    public const SYSTEM_KEY_SESSION_ALL_CUSTOM_TABLES = "all_custom_tables";
    public const SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES = "custom_table_relation_tables_%s_%s";
    public const SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE = "custom_value_value.%s.%s";
    public const SYSTEM_KEY_SESSION_CUSTOM_VALUE_COUNT = "custom_value_count.%s";
    public const SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE = "database_column_names_in_table_%s";
    public const SYSTEM_KEY_SESSION_HAS_CUSTOM_TABLE_ORDER = "has_custom_table_order";
    public const SYSTEM_KEY_SESSION_HAS_CUSTOM_COLUMN_ORDER = "has_custom_column_order";
    public const SYSTEM_KEY_SESSION_AUTH_2FACTOR = "auth_2factor";
    public const SYSTEM_KEY_SESSION_CUSTOM_LOGIN_USER = "custom_login_user";
    public const SYSTEM_KEY_SESSION_PROVIDER_TOKEN = "provider_token";
    public const SYSTEM_KEY_SESSION_SAML_SESSION = "saml_session";
    public const SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE = "sso_test_message";
    public const SYSTEM_KEY_SESSION_PLUGINS = "plugins";
    public const SYSTEM_KEY_SESSION_PLUGIN_ALL_SETTING_IDS = "plugin_all_setting_ids";
    public const SYSTEM_KEY_SESSION_PASSWORD_LIMIT = "password_limit";
    public const SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD = "first_change_password";
    public const SYSTEM_KEY_SESSION_HAS_WORLFLOW = "has_worlflow";
    public const SYSTEM_KEY_SESSION_WORKFLOW_SELECT_TABLE = "workflow_select_table_%s";
    public const SYSTEM_KEY_SESSION_WORKFLOW_DESIGNATED_TABLE = "workflow_designated_table_%s";
    public const SYSTEM_KEY_SESSION_UPDATE_NEWS = "update_news";
    public const SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK = "worlflow_filter_check";
    public const SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK = "worlflow_status_check";
    public const SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE = "import_key_value_%s_%s_%s";
    public const SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE_PREFIX = "import_key_value_";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_TREE = "organization_tree";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_PARENT_INDEX = "organization_parent_index";
    // the row of the table systems that holds the generation of the cache (System::cacheGeneration)
    public const SYSTEM_KEY_CACHE_GENERATION = "cache_generation";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_PARENT_IDS = "organization_parent_ids";
    public const SYSTEM_KEY_SESSION_GRID_AUTHORITABLE = "grid_authoritable_%s";
    public const SYSTEM_KEY_SESSION_ACCESSIBLE_TABLE = "accessible_table_%s_%s";
    public const SYSTEM_KEY_SESSION_DISABLE_DATA_URL_TAG = "disable_data_url_tag";
    public const SYSTEM_KEY_SESSION_FORM_DATA_TYPE = "form_data_type";
    public const SYSTEM_KEY_SESSION_FILE_NODELIST = "file_treelist";
    public const SYSTEM_KEY_SESSION_COMPOSER_VERSION = "exment_composer_version";
    public const SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT = "public_form_input";
    public const SYSTEM_KEY_SESSION_PUBLIC_FORM_INPUT_FILENAMES = "public_form_input_filenames";
    public const SYSTEM_KEY_SESSION_PUBLIC_FORM_SAVED_FILENAMES = "public_form_saved_filenames";


    public const APPEND_QUERY_WORK_STATUS_SUB_QUERY = 'APPEND_QUERY_WORK_STATUS_SUB_QUERY';
    public const APPEND_QUERY_WORK_USERS_SUB_QUERY = 'APPEND_QUERY_WORK_USERS_SUB_QUERY';


    // Authenticate ----------------------------------------------------
    public const AUTHENTICATE_KEY_WEB = 'admin';
    public const AUTHENTICATE_KEY_API = 'adminapi';
    public const AUTHENTICATE_KEY_PUBLIC_FORM = 'publicform';

    /**
     * MENU SYSTEM DIFINITION
     */
    /**
     * The groups of the left menu (parent nodes), in the order of the feature
     * map (exment.net/feature-map/): the first menu of a new site, the bundled
     * templates and the official plugins put their menus under them
     * (Menu::groupParentId()). The settings (admin_data, admin_user, admin)
     * come at the end, after 90.
     */
    public const MENU_GROUP_DEFINITION = [
        'groupware' => ['icon' => 'fa-comments', 'order' => 2],
        'request' => ['icon' => 'fa-check-square-o', 'order' => 10],
        'sales' => ['icon' => 'fa-briefcase', 'order' => 20],
        'asset' => ['icon' => 'fa-archive', 'order' => 25],
        'project' => ['icon' => 'fa-tasks', 'order' => 30],
        'it_support' => ['icon' => 'fa-life-ring', 'order' => 40],
        'hr' => ['icon' => 'fa-users', 'order' => 50],
        'master' => ['icon' => 'fa-database', 'order' => 90],
    ];

    public const MENU_SYSTEM_DEFINITION = [
        'home' => [
            'uri' => '/',
            'icon' => 'fa-home',
        ],
        'system' => [
            'uri' => 'system',
            'icon' => 'fa-cogs',
        ],
        // the settings once on the detail page of the system settings (#2157)
        'safety_check_setting' => [
            'uri' => 'safety_check_setting',
            'icon' => 'fa-heartbeat',
        ],
        'meili_setting' => [
            'uri' => 'meili_setting',
            'icon' => 'fa-search',
        ],
        'meili_filter' => [
            'uri' => 'meili-filter',
            'icon' => 'fa-filter',
        ],
        'meili_dictionary' => [
            'uri' => 'meili-dictionary',
            'icon' => 'fa-language',
        ],
        'file_server' => [
            'uri' => 'file_server',
            'icon' => 'fa-folder-open',
        ],
        'custom_table' => [
            'uri' => 'table',
            'icon' => 'fa-table',
        ],
        'studio' => [
            'uri' => 'studio',
            'icon' => 'fa-th-large',
        ],
        'tuning' => [
            'uri' => 'tuning',
            'icon' => 'fa-tachometer',
        ],
        'security_check' => [
            'uri' => 'security_check',
            'icon' => 'fa-shield',
        ],
        'log_management' => [
            'uri' => 'log_management',
            'icon' => 'fa-archive',
        ],
        'scheduled_jobs' => [
            'uri' => 'scheduled_jobs',
            'icon' => 'fa-clock-o',
        ],
        'maintenance' => [
            'uri' => 'maintenance',
            'icon' => 'fa-wrench',
        ],
        'role_group' => [
            'uri' => 'role_group',
            'icon' => 'fa-user-secret',
        ],
        'feature' => [
            'uri' => 'feature',
            'icon' => 'fa-toggle-on',
        ],
        'menu' => [
            'uri' => 'auth/menu',
            'icon' => 'fa-sitemap',
        ],
        'template' => [
            'uri' => 'template',
            'icon' => 'fa-clone',
        ],
        'cell_style_preset' => [
            'uri' => 'cell_style_preset',
            'icon' => 'fa-paint-brush',
        ],
        'workflow' => [
            'uri' => 'workflow',
            'icon' => 'fa-share-alt',
        ],
        'workflow_task' => [
            'uri' => 'workflow_task',
            'icon' => 'fa-check-square-o',
        ],
        'backup' => [
            'uri' => 'backup',
            'icon' => 'fa-database',
        ],
        'plugin' => [
            'uri' => 'plugin',
            'icon' => 'fa-plug',
        ],
        'notify' => [
            'uri' => 'notify',
            'icon' => 'fa-bell',
        ],
        'loginuser' => [
            'uri' => 'loginuser',
            'icon' => 'fa-user-plus',
        ],
        'operation_log' => [
            'uri' => 'auth/logs',
            'icon' => 'fa-file-text',
        ],
        'api_setting' => [
            'uri' => 'api_setting',
            'icon' => 'fa-code-fork',
        ],
        'login_setting' => [
            'uri' => 'login_setting',
            'icon' => 'fa-sign-in',
        ],
    ];

    public const CUSTOM_COLUMN_AVAILABLE_CHARACTERS = [
        [
            'key' => 'lower',
            'regex' => 'a-z'
        ],
        [
            'key' => 'upper',
            'regex' => 'A-Z'
        ],
        [
            'key' => 'number',
            'regex' => '0-9'
        ],
        [
            'key' => 'hyphen_underscore',
            'regex' => '_\-'
        ],
        [
            'key' => 'dot',
            'regex' => '\.'
        ],
        [
            'key' => 'symbol',
            'regex' => '!"#$%&\'()\*\+\-\.,\/:;<=>?@\[\]^_`{}~'
        ],
    ];

    public const CUSTOM_VALUE_IMPORT_KEY = [
        'id',
        'suuid',
    ];
    public const CUSTOM_VALUE_IMPORT_ERROR = [
        'stop',
        //'skip', //TODO:how to develop
    ];

    public const GRID_CHANGE_PAGE_MENULIST = [
        ['name' => 'table', 'href' => 'table', 'icon' => 'fa-table', 'move_edit' => true, 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_table', 'description' => 'custom_table.description', 'buttons' => [
            [
                'name' => 'default_setting',
                'href' => 'table/:id/edit',
                'exmtrans' => 'custom_table.default_setting',
                'icon' => 'fa-table',
            ],
            [
                'name' => 'expand_setting',
                'href' => 'table/:id/edit?columnmulti=1',
                'exmtrans' => 'custom_table.expand_setting',
                'icon' => 'fa-cogs',
            ],
        ]],
        ['name' => 'column', 'href' => 'column/:table_name', 'icon' => 'fa-list', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_column', 'description' => 'custom_column.description'],
        ['name' => 'relation', 'href' => 'relation/:table_name', 'icon' => 'fa-compress', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_relation', 'description' => 'custom_relation.description'],
        ['name' => 'form', 'href' => 'form/:table_name', 'icon' => 'fa-keyboard-o', 'roles' => Permission::AVAILABLE_CUSTOM_FORM, 'exmtrans' => 'change_page_menu.custom_form', 'description' => 'custom_form.description'],
        ['name' => 'view', 'href' => 'view/:table_name', 'icon' => 'fa-th-list', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_view', 'description' => 'custom_view.description'],
        ['name' => 'copy', 'href' => 'copy/:table_name', 'icon' => 'fa-copy', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_copy', 'description' => 'custom_copy.description'],
        ['name' => 'operation', 'href' => 'operation/:table_name', 'icon' => 'fa-reply-all', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_operation', 'description' => 'custom_operation.description'],
        ['name' => 'notify', 'href' => 'notify/:table_name', 'icon' => 'fa-bell', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.notify', 'description' => 'notify.description'],
        ['name' => 'data', 'href' => 'data/:table_name', 'icon' => 'fa-database', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_value', 'description' => 'custom_value.description'],
        ['name' => 'qrcode', 'href' => 'table/:id/edit?qrcodesetting=1', 'icon' => 'fa-qrcode', 'roles' => Permission::CUSTOM_TABLE, 'exmtrans' => 'change_page_menu.qrcode', 'description' => 'qrcode.description'],
        ['name' => 'jancode', 'href' => 'table/:id/edit?jancodesetting=1', 'icon' => 'fa-barcode', 'roles' => Permission::CUSTOM_TABLE, 'exmtrans' => 'change_page_menu.jancode', 'description' => 'jancode.description'],
        // only when the feature is used (Features::DATA_MAINTENANCE, #1829)
        ['name' => 'data_maintenance', 'href' => 'data_maintenance/:table_name', 'icon' => 'fa-wrench', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.data_maintenance', 'description' => 'data_maintenance.description', 'feature' => 'data_maintenance'],
    ];

    public const CUSTOM_VALUE_TRAITS = [
        'user' => "\Exceedone\Exment\Model\Traits\UserTrait",
        'organization' => "\Exceedone\Exment\Model\Traits\OrganizationTrait",
        'mail_template' => "\Exceedone\Exment\Model\Traits\MailTemplateTrait",
        'document' => "\Exceedone\Exment\Model\Traits\DocumentTrait",
    ];

    public const GRID_MAX_LENGTH = 50;

    public const PAGER_GRID_COUNTS = [10, 20, 30, 50, 100];
    public const PAGER_DATALIST_COUNTS = [5, 10, 20];

    public const WORKFLOW_START_KEYNAME = 'start';

    // Template --------------------------------------------------
    public const TEMPLATE_IMPORT_EXCEL_SHEETNAME = [
        'custom_tables',
        'custom_columns',
        'custom_column_multisettings',
        'custom_relations',
        // 'custom_forms',
        // 'custom_form_blocks',
        // 'custom_form_columns',
        // 'custom_views',
        // 'custom_view_columns',
        // 'custom_view_filters',
        // 'custom_view_sorts',
        // 'custom_copies',
        // 'custom_copy_columns',
        'admin_menu',
    ];

    public const CUSTOM_COLUMN_TYPE_PARENT_ID = 0;
    public const PARENT_ID_NAME = 'parent_id';

    public const COLUMN_ITEM_UNIQUE_PREFIX = "ckey_";

    public const DATABASE_TYPE = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'sqlsrv' => 'SQLServer',
    ];

    // min: the install stops below it. recommended: the versions that still get fixes from their
    // makers (the security check and exment:update say so below it; ISS00216)
    public const DATABASE_VERSION = [
        'mysql' => ['min' => '5.7.8', 'recommended' => '8.0'],
        'mariadb' => ['min' => '10.2.7', 'recommended' => '10.11'],
        'sqlsrv' => ['min' => '13.0.0.0', 'recommended' => '13.0'],
    ];

    /**
     * PHP version range checked on the install screen: [min (inclusive), max (exclusive)].
     * min must match "php" in composer.json.
     */
    public const PHP_VERSION = [
        '8.2.0',
        '8.5.0',
    ];

    public const CUSTOM_TABLE_ENDPOINTS = [
        'column',
        'copy',
        'form',
        'formpriority',
        'formpublic',
        'operation',
        'relation',
        'view',
        'data',
    ];


    // @phpstan-ignore-next-line
    public static function FILE_OPTION()
    {
        // get max size
        $maxSize = \Exment::getUploadMaxFileSize();

        return [
            'showPreview' => true,
            'showCancel' => false,
            'fileActionSettings' => [
                'showZoom' => false,
                'showDrag' => false,
            ],
            'dropZoneEnabled' => !boolval(config('exment.file_drag_drop_disabled', false)),
            'dropZoneTitle' => exmtrans('common.message.file_drag_drop'),
            'browseLabel' => trans('admin.browse'),
            'uploadLabel' => trans('admin.upload'),
            'maxFileSize' => $maxSize / 1024,
            'maxFileSizeHuman' => bytesToHuman($maxSize),
            'maxFileSizeHelp' => sprintf(exmtrans('common.max_file_size') . ' : %s', bytesToHuman($maxSize)),
            'msgSizeTooLarge' => exmtrans('error.size_too_large'),
        ];
    }

    public const HELP_URLS = [
        ['uri'=> 'template', 'help_uri'=> 'template'],
        ['uri'=> 'search', 'help_uri'=> 'search'],
        // if has "query", match only when the url has the query string parameter. Set before the entry without "query".
        ['uri'=> 'table', 'query'=> 'qrcodesetting', 'help_uri'=> '2d_barcode'],
        ['uri'=> 'table', 'query'=> 'jancodesetting', 'help_uri'=> 'jancode'],
        ['uri'=> 'table', 'help_uri'=> 'table'],
        ['uri'=> 'column', 'help_uri'=> 'column'],
        ['uri'=> 'relation', 'help_uri'=> 'relation'],
        ['uri'=> 'formpublic', 'help_uri'=> 'publicform'],
        ['uri'=> 'form', 'help_uri'=> 'form'],
        ['uri'=> 'view', 'help_uri'=> 'view'],
        ['uri'=> 'relation', 'help_uri'=> 'relation'],
        ['uri'=> 'operation', 'help_uri'=> 'operation'],
        ['uri'=> 'plugin', 'help_uri'=> 'plugin'],
        ['uri'=> 'api_setting', 'help_uri'=> 'api'],
        ['uri'=> 'backup', 'help_uri'=> 'backup'],
        ['uri'=> 'role_group', 'help_uri'=> 'permission'],
        ['uri'=> 'auth/menu', 'help_uri'=> 'menu'],
        ['uri'=> 'auth/logs', 'help_uri'=> 'logs'],
        ['uri'=> 'notify', 'help_uri'=> 'notify'],
        ['uri'=> 'loginuser', 'help_uri'=> 'user'],
        ['uri'=> 'data/user', 'help_uri'=> 'user'],
        ['uri'=> 'data/mail_template', 'help_uri'=> 'mail'],
        ['uri'=> 'data/base_info', 'help_uri'=> 'base_info'],
        ['uri'=> 'data', 'help_uri'=> 'data'],
        ['uri'=> 'dashboard', 'help_uri'=> 'dashboard'],
        ['uri'=> 'dashboardbox', 'help_uri'=> 'dashboard'],
        ['uri'=> 'system', 'help_uri'=> 'system_setting'],
        ['uri'=> 'safety_check_setting', 'help_uri'=> 'safety_check'],
        ['uri'=> 'meili_setting', 'help_uri'=> 'additional_meilisearch'],
        // the flow designer, before "workflow" (a "*" is one part of the path, as the id)
        ['uri'=> 'workflow/design/create', 'help_uri'=> 'workflow_design'],
        ['uri'=> 'workflow/*/design', 'help_uri'=> 'workflow_design'],
        ['uri'=> 'workflow', 'help_uri'=> 'workflow_setting'],
        ['uri'=> 'line/link', 'help_uri'=> 'line'],
        ['uri'=> 'login_setting', 'help_uri'=> 'login_setting'],
        ['uri'=> 'tuning', 'help_uri'=> 'tuning'],
        ['uri'=> 'security_check', 'help_uri'=> 'security_check'],
        ['uri'=> 'scheduled_jobs', 'help_uri'=> 'scheduled_jobs'],
        ['uri'=> 'log_management', 'help_uri'=> 'log_management'],
        ['uri'=> 'feature', 'help_uri'=> 'feature'],
        ['uri'=> '/', 'help_uri'=> 'dashboard'],
    ];

    public const SETTING_SHEET_NAME = '##setting##';

    public const YESNO_RADIO = [
        ''   => 'All',
        0    => 'NO',
        1    => 'YES',
    ];

    public const DISKNAME_ADMIN = 'admin';
    public const DISKNAME_ADMIN_TMP = 'admin_tmp';
    public const DISKNAME_BACKUP = 'backup';
    public const DISKNAME_BACKUP_SYNC = 'backup_sync';
    public const DISKNAME_PLUGIN = 'plugin';
    public const DISKNAME_PLUGIN_SYNC = 'plugin_sync';
    public const DISKNAME_PLUGIN_LOCAL = 'plugin_local';
    public const DISKNAME_PLUGIN_TEST = 'plugin_test';
    public const DISKNAME_TEMPLATE_SYNC = 'template_sync';
    public const DISKNAME_TEMP_UPLOAD = 'tmpupload';
    public const DISKNAME_PUBLIC_FORM_TMP = 'public_form_tmp';

    public const IMAGE_RULE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

    public const CHARTITEM_LABEL = 'chartitem_label';

    public const SAML_NAME_ID_FORMATS = [
        'NAMEID_EMAIL_ADDRESS' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'NAMEID_X509_SUBJECT_NAME' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:X509SubjectName',
        'NAMEID_WINDOWS_DOMAIN_QUALIFIED_NAME' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:WindowsDomainQualifiedName',
        'NAMEID_UNSPECIFIED' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'NAMEID_KERBEROS  ' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:kerberos',
        'NAMEID_ENTITY    ' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:entity',
        'NAMEID_TRANSIENT ' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
        'NAMEID_PERSISTENT' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'NAMEID_ENCRYPTED' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:encrypted',
    ];

    public const ATTRIBUTE_NAME_FORMATS = [
        'ATTRNAME_FORMAT_UNSPECIFIED' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:unspecified',
        'ATTRNAME_FORMAT_URI' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri',
        'ATTRNAME_FORMAT_BASIC' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic',
    ];


    // Safe rich-content elements kept in sync between html_clean() (HTML_ALLOWED_DEFAULT) and the
    // TinyMCE editor (HTML_ALLOWED_EDITOR_DEFAULT). Table markup and common text elements are added
    // so html_clean() does not strip legitimate content. Dangerous/embed elements (script, iframe,
    // object, embed, form, video, audio, svg) are intentionally excluded; a deployment that truly
    // needs them can override via config('exment.html_allowed') / config('exment.html_allowed_attributes').
    public const HTML_ALLOWED_DEFAULT = 'div,b,strong,i,em,u,a[href|title|target],ul,ol,li,p,br,span,img[width|height|alt|src],h1,h2,h3,h4,h5,h6,blockquote,hr,table,thead,tbody,tfoot,tr,td[colspan|rowspan],th[colspan|rowspan|scope],caption,colgroup,col,pre,code,small,sub,sup,s,del,ins,dl,dt,dd';
    public const HTML_ALLOWED_EDITOR_DEFAULT = '@[style],@[class],div,b,strong,i,em,u,a[href|title|target],ul,ol,li,p,br,span,img[width|height|alt|src],h1,h2,h3,h4,h5,h6,blockquote,hr,table,thead,tbody,tfoot,tr,td[colspan|rowspan],th[colspan|rowspan|scope],caption,colgroup,col,pre,code,small,sub,sup,s,del,ins,dl,dt,dd';
    public const HTML_ALLOWED_ATTRIBUTES_DEFAULT = '*.style,*.class';
    public const CSS_ALLOWED_PROPERTIES_DEFAULT = '*';
}
