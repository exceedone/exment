<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;

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
    public const COMPOSER_VERSION_CHECK_URL = 'https://repo.packagist.org/p/exceedone/exment.json';
    public const EXMENT_NEWS_API_URL = 'https://exment.net/wp-json/wp/v2/posts';
    public const EXMENT_NEWS_LINK = 'https://exment.net/archives/category/news';
    public const USER_IMAGE_LINK = 'vendor/exment/images/user.png';
    public const ORGANIZATION_IMAGE_LINK = 'vendor/exment/images/organization.png';

    public const RULES_REGEX_VALUE_FORMAT = '\${(.*?)\}';
    public const RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN = '^[a-zA-Z0-9\-_]*$';
    public const RULES_REGEX_SYSTEM_NAME = '^(?=[a-zA-Z])(?!.*[-_]$)[-_a-zA-Z0-9]+$';
    public const RULES_REGEX_LINK_FORMAT = "|<a href=[\"'](.*?)[\"'].*?>(.*?)</a>|mis";
    
    public const DELETE_CONFIRM_KEYWORD = 'delete me';
    public const RESTORE_CONFIRM_KEYWORD = 'restore me';
    public const YES_KEYWORD = 'yes';

    public const API_FEATURE_TEST = 'API_FEATURE_TEST';
    public const API_FEATURE_TEST_APIKEY = 'API_FEATURE_TEST_APIKEY';

    public const MAX_SIZE_NUMBER = 1000000000000;

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
        'system_mail_host' => ['config' => 'mail.host', 'group' => 'advanced'],
        'system_mail_port' => ['config' => 'mail.port', 'group' => 'advanced'],
        'system_mail_username' => ['config' => 'mail.username', 'group' => 'advanced'],
        'system_mail_password' => ['type' => 'password', 'config' => 'mail.password', 'group' => 'advanced'],
        'system_mail_encryption' => ['config' => 'mail.encryption', 'group' => 'advanced'],
        'system_mail_from' => ['default' => 'no-reply@hogehoge.com', 'group' => 'advanced'],

        'default_date_format' => ['default' => 'format_default', 'group' => 'advanced'],
        'grid_pager_count' => ['type' => 'int', 'default' => '20', 'group' => 'advanced'],
        'datalist_pager_count' => ['type' => 'int', 'default' => '5', 'group' => 'advanced'],

        'grid_filter_disable_flg' => ['type' => 'array', 'default' => '', 'group' => 'advanced'] ,

        'complex_password' => ['type' => 'boolean', 'group' => 'advanced', 'default' => false],
        'password_expiration_days' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],
        'password_history_cnt' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],

        'web_ip_filters' => ['default' => '', 'group' => 'advanced'] ,
        'api_ip_filters' => ['default' => '', 'group' => 'advanced'] ,

        // org_joined_type
        'org_joined_type_role_group' => ['type' => 'int', 'default' => '99', 'group' => 'advanced'],
        'org_joined_type_custom_value' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],
        'custom_value_save_autoshare' => ['type' => 'int', 'default' => '0', 'group' => 'advanced'],
        
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

    public const CACHE_CLEAR_MINUTE = 60;
    public const SYSTEM_KEY_SESSION_SYSTEM_CONFIG = "setting.%s";
    public const SYSTEM_KEY_SESSION_INITIALIZE = "initialize";
    public const SYSTEM_KEY_SESSION_AUTHORITY = "role";
    public const SYSTEM_KEY_SESSION_USER_SETTING = "user_setting";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION = "system_version";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE = "system_version_execute";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS = "organization_ids";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS_ORG = "organization_ids_org_%s_%s";
    public const SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID = "file_uploaded_uuid";
    public const SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_ORGS = "table_accessible_orgs_%s";
    public const SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS = "table_accessible_users_%s";
    public const SYSTEM_KEY_SESSION_VALUE_ACCRSSIBLE_USERS = "value_accessible_users_%s_%s";
    public const SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE = "can_connection_database";
    public const SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES = "all_database_table_names";
    public const SYSTEM_KEY_SESSION_ALL_RECORDS = "all_records_%s";
    public const SYSTEM_KEY_SESSION_ALL_CUSTOM_TABLES = "all_custom_tables";
    public const SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES = "custom_table_relation_tables.%s";
    public const SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE = "custom_value_value.%s.%s";
    public const SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE = "database_column_names_in_table_%s";
    public const SYSTEM_KEY_SESSION_HAS_CUSTOM_TABLE_ORDER = "has_custom_table_order";
    public const SYSTEM_KEY_SESSION_HAS_CUSTOM_COLUMN_ORDER = "has_custom_column_order";
    public const SYSTEM_KEY_SESSION_AUTH_2FACTOR = "auth_2factor";
    public const SYSTEM_KEY_SESSION_PROVIDER_TOKEN = "provider_token";
    public const SYSTEM_KEY_SESSION_PLUGINS = "plugins";
    public const SYSTEM_KEY_SESSION_PLUGIN_ALL_SETTING_IDS = "plugin_all_setting_ids";
    public const SYSTEM_KEY_SESSION_PASSWORD_LIMIT = "password_limit";
    public const SYSTEM_KEY_SESSION_HAS_WORLFLOW = "has_worlflow";
    public const SYSTEM_KEY_SESSION_WORKFLOW_SELECT_TABLE = "workflow_select_table_%s";
    public const SYSTEM_KEY_SESSION_WORKFLOW_DESIGNATED_TABLE = "workflow_designated_table_%s";
    public const SYSTEM_KEY_SESSION_UPDATE_NEWS = "update_news";
    public const SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK = "worlflow_filter_check";
    public const SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK = "worlflow_status_check";
    public const SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE = "import_key_value_%s_%s_%s";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_TREE = "organization_tree";

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
        ['name' => 'form', 'href' => 'form/:table_name', 'icon' => 'fa-keyboard-o', 'roles' => [Permission::CUSTOM_FORM], 'exmtrans' => 'change_page_menu.custom_form', 'description' => 'custom_form.description'],
        ['name' => 'view', 'href' => 'view/:table_name', 'icon' => 'fa-th-list', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_view', 'description' => 'custom_view.description'],
        ['name' => 'copy', 'href' => 'copy/:table_name', 'icon' => 'fa-copy', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_copy', 'description' => 'custom_copy.description'],
        ['name' => 'operation', 'href' => 'operation/:table_name', 'icon' => 'fa-reply-all', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_operation', 'description' => 'custom_operation.description'],
        ['name' => 'data', 'href' => 'data/:table_name', 'icon' => 'fa-database', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_value', 'description' => 'custom_value.description'],
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

    public const DATABASE_TYPE = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'sqlsrv' => 'SQLServer (β)',
    ];

    public const DATABASE_MIN_VERSION = [
        'mysql' => '5.7.8',
        'mariadb' => '10.2.7',
        'sqlsrv' => '13.0.0.0',
    ];

    public const PHP_VERSION = [
        '7.1.3',
        '7.4.0',
    ];

    public const CUSTOM_TABLE_ENDPOINTS = [
        'column',
        'copy',
        'form',
        'formpriority',
        'operation',
        'relation',
        'view',
        'data',
    ];

    public static function FILE_OPTION()
    {
        // get max size
        $maxSize = getUploadMaxFileSize();

        return [
            'showPreview' => false,
            'showCancel' => false,
            'browseLabel' => trans('admin.browse'),
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
        ['uri'=> 'form', 'help_uri'=> 'form'],
        ['uri'=> 'view', 'help_uri'=> 'view'],
        ['uri'=> 'template', 'help_uri'=> 'template'],
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
    public const DISKNAME_TEMPLATE_SYNC = 'template_sync';

    public const CHARTITEM_LABEL = 'chartitem_label';
}
