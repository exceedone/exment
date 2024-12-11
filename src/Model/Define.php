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
    public const COMPOSER_PACKAGE_NAME_LARAVEL_ADMIN = 'exceedone/laravel-admin';
    public const COMPOSER_VERSION_CHECK_URL = 'https://repo.packagist.org/p/exceedone/exment.json';
    public const EXMENT_NEWS_API_URL = 'https://exment.net/wp-json/wp/v2/posts';
    public const EXMENT_NEWS_LINK = 'https://exment.net/archives/category/news';
    public const USER_IMAGE_LINK = 'vendor/exment/images/user.png';
    public const ORGANIZATION_IMAGE_LINK = 'vendor/exment/images/organization.png';

    public const RULES_REGEX_VALUE_FORMAT = '\${(.*?)\}';
    public const RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN = '^[a-zA-Z0-9\-_]*$';
    public const RULES_REGEX_SYSTEM_NAME = '^(?=[a-zA-Z])(?!.*[-_]$)[-_a-zA-Z0-9]+$';
    public const RULES_REGEX_LINK_FORMAT = "|<a href=[\"'](.*?)[\"'].*?>(.*?)</a>|mis";
    public const RULES_REGEX_BACKUP_FILENAME = '[ぁ-んァ-ヶ亜-熙a-zA-Z0-9]+';

    public const DELETE_CONFIRM_KEYWORD = 'delete me';
    public const RESTORE_CONFIRM_KEYWORD = 'restore me';
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

        'api_available' => ['type' => 'boolean', 'config' => 'exment.api', 'group' => 'initialize'],
        'outside_api' => ['type' => 'boolean', 'group' => 'initialize', 'default' => true],
        'permission_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],
        'organization_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],

        // Advanced ----------------------------------
        'filter_search_type' => ['default' => 'forward', 'group' => 'advanced'],

        'default_date_format' => ['default' => 'format_default', 'group' => 'advanced'],
        'grid_pager_count' => ['type' => 'int', 'default' => '20', 'group' => 'advanced'],
        'datalist_pager_count' => ['type' => 'int', 'default' => '5', 'group' => 'advanced'],
        'data_submit_redirect' => ['type' => 'string', 'default' => null, 'group' => 'advanced'],
        'header_user_info' => ['type' => 'array', 'default' => SystemColumn::CREATED_AT, 'group' => 'advanced'],

        // name is "flg", but array is OK.
        'grid_filter_disable_flg' => ['type' => 'array', 'default' => '', 'group' => 'advanced'] ,

        'system_values_pos' => ['default' => 'top', 'group' => 'advanced'],

        'web_ip_filters' => ['default' => '', 'group' => 'advanced'] ,
        'api_ip_filters' => ['default' => '', 'group' => 'advanced'] ,

        'userview_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],
        'userdashboard_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],

        // public form ----------------------------------------------------
        'publicform_available' => ['type' => 'boolean', 'default' => false, 'group' => 'advanced'],
        'recaptcha_type' => ['type' => 'string', 'group' => 'advanced'],
        'recaptcha_site_key' => ['type' => 'password', 'group' => 'advanced'],
        'recaptcha_secret_key' => ['type' => 'password', 'group' => 'advanced'],

        'complex_password' => ['type' => 'boolean', 'group' => 'login', 'default' => false],
        'password_expiration_days' => ['type' => 'int', 'default' => '0', 'group' => 'login'],
        'first_change_password' => ['type' => 'boolean', 'group' => 'login', 'default' => false],
        'password_history_cnt' => ['type' => 'int', 'default' => '0', 'group' => 'login'],

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

        // Backup
        'backup_enable_automatic' => ['type' => 'boolean', 'default' => '0', 'group' => 'backup'],
        'backup_automatic_term' => ['type' => 'int', 'default' => '1', 'group' => 'backup'],
        'backup_automatic_hour' => ['type' => 'int', 'default' => '3', 'group' => 'backup'],
        'backup_target' => ['type' => 'array', 'default' => 'database,plugin,attachment,log,config', 'group' => 'backup'] ,
        'backup_automatic_executed' => ['type' => 'datetime'],
        'backup_history_files' => ['type' => 'int', 'default' => '0', 'group' => 'backup'],

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
    public const MENU_SYSTEM_DEFINITION = [
        'home' => [
            'uri' => '/',
            'icon' => 'fa-home',
        ],
        'system' => [
            'uri' => 'system',
            'icon' => 'fa-cogs',
        ],
        'custom_table' => [
            'uri' => 'table',
            'icon' => 'fa-table',
        ],
        'role_group' => [
            'uri' => 'role_group',
            'icon' => 'fa-user-secret',
        ],
        'menu' => [
            'uri' => 'auth/menu',
            'icon' => 'fa-sitemap',
        ],
        'template' => [
            'uri' => 'template',
            'icon' => 'fa-clone',
        ],
        'workflow' => [
            'uri' => 'workflow',
            'icon' => 'fa-share-alt',
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

    public const DATABASE_VERSION = [
        'mysql' => ['min' => '5.7.8', 'max_lt' => '8.1.0'],
        'mariadb' => ['min' => '10.2.7'],
        'sqlsrv' => ['min' => '13.0.0.0'],
    ];

    public const PHP_VERSION = [
        '8.1.0',
        '8.3.0',
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
        ['uri'=> 'loginuser', 'help_uri'=> 'user'],
        ['uri'=> 'data/user', 'help_uri'=> 'user'],
        ['uri'=> 'data/mail_template', 'help_uri'=> 'mail'],
        ['uri'=> 'data/base_info', 'help_uri'=> 'base_info'],
        ['uri'=> 'data', 'help_uri'=> 'data'],
        ['uri'=> 'dashboard', 'help_uri'=> 'dashboard'],
        ['uri'=> 'dashboardbox', 'help_uri'=> 'dashboard'],
        ['uri'=> 'system', 'help_uri'=> 'system_setting'],
        ['uri'=> 'workflow', 'help_uri'=> 'workflow_setting'],
        ['uri'=> 'login_setting', 'help_uri'=> 'login_setting'],
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

    public const IMAGE_RULE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'];

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


    public const HTML_ALLOWED_DEFAULT = 'div,b,strong,i,em,u,a[href|title|target],ul,ol,li,p,br,span,img[width|height|alt|src],h1,h2,h3,h4,h5,h6,blockquote,hr';
    public const HTML_ALLOWED_EDITOR_DEFAULT = '@[style],@[class],div,b,strong,i,em,u,a[href|title|target],ul,ol,li,p,br,span,img[width|height|alt|src],h1,h2,h3,h4,h5,h6,blockquote,hr';
    public const HTML_ALLOWED_ATTRIBUTES_DEFAULT = '*.style,*.class';
    public const CSS_ALLOWED_PROPERTIES_DEFAULT = '*';
}
