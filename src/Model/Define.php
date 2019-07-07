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
    /**
     * TIMEZONE LIST
     * *Please replace "_" to "/"!!
     */
    public const TIMEZONE = [
        'Pacific_Midway'       => "(GMT-11:00) Midway Island",
        'US_Samoa'             => "(GMT-11:00) Samoa",
        'US_Hawaii'            => "(GMT-10:00) Hawaii",
        'US_Alaska'            => "(GMT-09:00) Alaska",
        'US_Pacific'           => "(GMT-08:00) Pacific Time (US &amp; Canada)",
        'America_Tijuana'      => "(GMT-08:00) Tijuana",
        'US_Arizona'           => "(GMT-07:00) Arizona",
        'US_Mountain'          => "(GMT-07:00) Mountain Time (US &amp; Canada)",
        'America_Chihuahua'    => "(GMT-07:00) Chihuahua",
        'America_Mazatlan'     => "(GMT-07:00) Mazatlan",
        'America_Mexico_City'  => "(GMT-06:00) Mexico City",
        'America_Monterrey'    => "(GMT-06:00) Monterrey",
        'Canada_Saskatchewan'  => "(GMT-06:00) Saskatchewan",
        'US_Central'           => "(GMT-06:00) Central Time (US &amp; Canada)",
        'US_Eastern'           => "(GMT-05:00) Eastern Time (US &amp; Canada)",
        'US_East-Indiana'      => "(GMT-05:00) Indiana (East)",
        'America_Bogota'       => "(GMT-05:00) Bogota",
        'America_Lima'         => "(GMT-05:00) Lima",
        'America_Caracas'      => "(GMT-04:30) Caracas",
        'Canada_Atlantic'      => "(GMT-04:00) Atlantic Time (Canada)",
        'America_La_Paz'       => "(GMT-04:00) La Paz",
        'America_Santiago'     => "(GMT-04:00) Santiago",
        'Canada_Newfoundland'  => "(GMT-03:30) Newfoundland",
        'America_Buenos_Aires' => "(GMT-03:00) Buenos Aires",
        'Greenland'            => "(GMT-03:00) Greenland",
        'Atlantic_Stanley'     => "(GMT-02:00) Stanley",
        'Atlantic_Azores'      => "(GMT-01:00) Azores",
        'Atlantic_Cape_Verde'  => "(GMT-01:00) Cape Verde Is.",
        'Africa_Casablanca'    => "(GMT) Casablanca",
        'Europe_Dublin'        => "(GMT) Dublin",
        'Europe_Lisbon'        => "(GMT) Lisbon",
        'Europe_London'        => "(GMT) London",
        'Africa_Monrovia'      => "(GMT) Monrovia",
        'Europe_Amsterdam'     => "(GMT+01:00) Amsterdam",
        'Europe_Belgrade'      => "(GMT+01:00) Belgrade",
        'Europe_Berlin'        => "(GMT+01:00) Berlin",
        'Europe_Bratislava'    => "(GMT+01:00) Bratislava",
        'Europe_Brussels'      => "(GMT+01:00) Brussels",
        'Europe_Budapest'      => "(GMT+01:00) Budapest",
        'Europe_Copenhagen'    => "(GMT+01:00) Copenhagen",
        'Europe_Ljubljana'     => "(GMT+01:00) Ljubljana",
        'Europe_Madrid'        => "(GMT+01:00) Madrid",
        'Europe_Paris'         => "(GMT+01:00) Paris",
        'Europe_Prague'        => "(GMT+01:00) Prague",
        'Europe_Rome'          => "(GMT+01:00) Rome",
        'Europe_Sarajevo'      => "(GMT+01:00) Sarajevo",
        'Europe_Skopje'        => "(GMT+01:00) Skopje",
        'Europe_Stockholm'     => "(GMT+01:00) Stockholm",
        'Europe_Vienna'        => "(GMT+01:00) Vienna",
        'Europe_Warsaw'        => "(GMT+01:00) Warsaw",
        'Europe_Zagreb'        => "(GMT+01:00) Zagreb",
        'Europe_Athens'        => "(GMT+02:00) Athens",
        'Europe_Bucharest'     => "(GMT+02:00) Bucharest",
        'Africa_Cairo'         => "(GMT+02:00) Cairo",
        'Africa_Harare'        => "(GMT+02:00) Harare",
        'Europe_Helsinki'      => "(GMT+02:00) Helsinki",
        'Europe_Istanbul'      => "(GMT+02:00) Istanbul",
        'Asia_Jerusalem'       => "(GMT+02:00) Jerusalem",
        'Europe_Kiev'          => "(GMT+02:00) Kyiv",
        'Europe_Minsk'         => "(GMT+02:00) Minsk",
        'Europe_Riga'          => "(GMT+02:00) Riga",
        'Europe_Sofia'         => "(GMT+02:00) Sofia",
        'Europe_Tallinn'       => "(GMT+02:00) Tallinn",
        'Europe_Vilnius'       => "(GMT+02:00) Vilnius",
        'Asia_Baghdad'         => "(GMT+03:00) Baghdad",
        'Asia_Kuwait'          => "(GMT+03:00) Kuwait",
        'Africa_Nairobi'       => "(GMT+03:00) Nairobi",
        'Asia_Riyadh'          => "(GMT+03:00) Riyadh",
        'Europe_Moscow'        => "(GMT+03:00) Moscow",
        'Asia_Tehran'          => "(GMT+03:30) Tehran",
        'Asia_Baku'            => "(GMT+04:00) Baku",
        'Europe_Volgograd'     => "(GMT+04:00) Volgograd",
        'Asia_Muscat'          => "(GMT+04:00) Muscat",
        'Asia_Tbilisi'         => "(GMT+04:00) Tbilisi",
        'Asia_Yerevan'         => "(GMT+04:00) Yerevan",
        'Asia_Kabul'           => "(GMT+04:30) Kabul",
        'Asia_Karachi'         => "(GMT+05:00) Karachi",
        'Asia_Tashkent'        => "(GMT+05:00) Tashkent",
        'Asia_Kolkata'         => "(GMT+05:30) Kolkata",
        'Asia_Kathmandu'       => "(GMT+05:45) Kathmandu",
        'Asia_Yekaterinburg'   => "(GMT+06:00) Ekaterinburg",
        'Asia_Almaty'          => "(GMT+06:00) Almaty",
        'Asia_Dhaka'           => "(GMT+06:00) Dhaka",
        'Asia_Novosibirsk'     => "(GMT+07:00) Novosibirsk",
        'Asia_Bangkok'         => "(GMT+07:00) Bangkok",
        'Asia_Jakarta'         => "(GMT+07:00) Jakarta",
        'Asia_Krasnoyarsk'     => "(GMT+08:00) Krasnoyarsk",
        'Asia_Chongqing'       => "(GMT+08:00) Chongqing",
        'Asia_Hong_Kong'       => "(GMT+08:00) Hong Kong",
        'Asia_Kuala_Lumpur'    => "(GMT+08:00) Kuala Lumpur",
        'Australia_Perth'      => "(GMT+08:00) Perth",
        'Asia_Singapore'       => "(GMT+08:00) Singapore",
        'Asia_Taipei'          => "(GMT+08:00) Taipei",
        'Asia_Ulaanbaatar'     => "(GMT+08:00) Ulaan Bataar",
        'Asia_Urumqi'          => "(GMT+08:00) Urumqi",
        'Asia_Irkutsk'         => "(GMT+09:00) Irkutsk",
        'Asia_Seoul'           => "(GMT+09:00) Seoul",
        'Asia_Tokyo'           => "(GMT+09:00) 日本",
        'Australia_Adelaide'   => "(GMT+09:30) Adelaide",
        'Australia_Darwin'     => "(GMT+09:30) Darwin",
        'Asia_Yakutsk'         => "(GMT+10:00) Yakutsk",
        'Australia_Brisbane'   => "(GMT+10:00) Brisbane",
        'Australia_Canberra'   => "(GMT+10:00) Canberra",
        'Pacific_Guam'         => "(GMT+10:00) Guam",
        'Australia_Hobart'     => "(GMT+10:00) Hobart",
        'Australia_Melbourne'  => "(GMT+10:00) Melbourne",
        'Pacific_Port_Moresby' => "(GMT+10:00) Port Moresby",
        'Australia_Sydney'     => "(GMT+10:00) Sydney",
        'Asia_Vladivostok'     => "(GMT+11:00) Vladivostok",
        'Asia_Magadan'         => "(GMT+12:00) Magadan",
        'Pacific_Auckland'     => "(GMT+12:00) Auckland",
        'Pacific_Fiji'         => "(GMT+12:00) Fiji",
    ];

    public const COMPOSER_PACKAGE_NAME = 'exceedone/exment';
    public const COMPOSER_VERSION_CHECK_URL = 'https://repo.packagist.org/p/exceedone/exment.json';
    public const EXMENT_NEWS_API_URL = 'https://exment.net/wp-json/wp/v2/posts';
    public const EXMENT_NEWS_LINK = 'https://exment.net/archives/category/news';

    public const RULES_REGEX_VALUE_FORMAT = '\${(.*?)\}';
    public const RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN = '^[a-zA-Z0-9\-_]*$';
    public const RULES_REGEX_SYSTEM_NAME = '^(?=[a-zA-Z])(?!.*[-_]$)[-_a-zA-Z0-9]+$';
    
    public const DELETE_CONFIRM_KEYWORD = 'delete me';

    public const SYSTEM_SETTING_NAME_VALUE = [
        'initialized' => ['type' => 'boolean', 'default' => '0'],
        'site_name' => ['default' => 'Exment', 'group' => 'initialize'],
        'site_name_short' => ['default' => 'Exm', 'group' => 'initialize'],
        'site_logo' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_logo_mini' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_favicon' => ['type' => 'file', 'move' => 'system', 'group' => 'initialize'],
        'site_skin' => ['config' => 'admin.skin', 'group' => 'initialize'],
        'outside_api' => ['type' => 'boolean', 'group' => 'initialize', 'default' => true],
        'permission_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],
        'organization_available' => ['type' => 'boolean', 'default' => '1', 'group' => 'initialize'],
        'system_mail_host' => ['config' => 'mail.host', 'group' => 'system'],
        'system_mail_port' => ['config' => 'mail.port', 'group' => 'system'],
        'system_mail_username' => ['config' => 'mail.username', 'group' => 'system'],
        'system_mail_password' => ['type' => 'password', 'config' => 'mail.password', 'group' => 'system'],
        'system_mail_encryption' => ['config' => 'mail.encryption', 'group' => 'system'],
        'system_mail_from' => ['default' => 'no-reply@hogehoge.com', 'group' => 'initialize'],
        'site_layout' => ['default' => 'layout_default', 'group' => 'initialize'],
        'grid_pager_count' => ['type' => 'int', 'default' => '20', 'group' => 'initialize'],
        'datalist_pager_count' => ['type' => 'int', 'default' => '5', 'group' => 'initialize'],
        // cannot call getValue function
        'backup_enable_automatic' => ['type' => 'boolean', 'default' => '0', 'group' => 'backup'],
        'backup_automatic_term' => ['type' => 'int', 'default' => '1', 'group' => 'backup'],
        'backup_automatic_hour' => ['type' => 'int', 'default' => '3', 'group' => 'backup'],
        'backup_target' => ['type' => 'array', 'default' => 'database,plugin,attachment,log,config', 'group' => 'backup'] ,
        'backup_automatic_executed' => ['type' => 'datetime'],
        'backup_history_files' => ['type' => 'int', 'default' => '0', 'group' => 'backup'],
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

    public const SYSTEM_KEY_SESSION_SYSTEM_CONFIG = "setting.%s";
    public const SYSTEM_KEY_SESSION_INITIALIZE = "initialize";
    public const SYSTEM_KEY_SESSION_AUTHORITY = "role";
    public const SYSTEM_KEY_SESSION_USER_SETTING = "user_setting";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION = "system_version";
    public const SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE = "system_version_execute";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS = "organization_ids";
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

    public const PLUGIN_EVENT_TRIGGER = [
        'saving',
        'saved',
        'loading',
        'loaded',
        'grid_menubutton',
        'form_menubutton_create',
        'form_menubutton_edit',
    ];

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
        'role' => [
            'uri' => 'role',
            'icon' => 'fa-user-secret',
        ],
        'user' => [
            'uri' => 'data/user',
            'icon' => 'fa-users',
        ],
        'organization' => [
            'uri' => 'data/organization',
            'icon' => 'fa-building',
        ],
        'menu' => [
            'uri' => 'auth/menu',
            'icon' => 'fa-sitemap',
        ],
        'template' => [
            'uri' => 'template',
            'icon' => 'fa-clone',
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
        'mail' => [
            'uri' => 'mail',
            'icon' => 'fa-envelope',
        ],
    ];

    public const CUSTOM_COLUMN_AVAILABLE_CHARACTERS_OPTIONS = [
        'lower','upper','number','hyphen_underscore','symbol'
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
        ['url' => 'table', 'icon' => 'fa-table', 'move_edit' => true, 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_table'],
        ['url' => 'column', 'icon' => 'fa-list', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_column'],
        ['url' => 'relation', 'icon' => 'fa-compress', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_relation'],
        ['url' => 'form', 'icon' => 'fa-keyboard-o', 'roles' => [Permission::CUSTOM_FORM], 'exmtrans' => 'change_page_menu.custom_form'],
        ['url' => 'view', 'icon' => 'fa-th-list', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_view'],
        ['url' => 'copy', 'icon' => 'fa-copy', 'roles' => [Permission::CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_copy'],
        ['url' => 'data', 'icon' => 'fa-database', 'roles' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_value'],
    ];

    public const CUSTOM_VALUE_TRAITS = [
        'user' => "\Exceedone\Exment\Model\Traits\UserTrait",
        'organization' => "\Exceedone\Exment\Model\Traits\OrganizationTrait",
        'mail_template' => "\Exceedone\Exment\Model\Traits\MailTemplateTrait",
    ];

    public const GRID_MAX_LENGTH = 50;

    public const PAGER_GRID_COUNTS = [10, 20, 30, 50, 100];
    public const PAGER_DATALIST_COUNTS = [5, 10, 20];

    // Template --------------------------------------------------
    public const TEMPLATE_IMPORT_EXCEL_SHEETNAME = [
        'custom_tables',
        'custom_columns',
        'custom_relations',
        'custom_forms',
        'custom_form_blocks',
        'custom_form_columns',
        'custom_views',
        'custom_view_columns',
        'custom_view_filters',
        'custom_view_sorts',
        'custom_copies',
        'custom_copy_columns',
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
        ['uri'=> 'backup', 'help_uri'=> 'backup'],
        ['uri'=> 'role', 'help_uri'=> 'permission'],
        ['uri'=> 'auth/menu', 'help_uri'=> 'menu'],
        ['uri'=> 'loginuser', 'help_uri'=> 'user'],
        ['uri'=> 'data/user', 'help_uri'=> 'user'],
        ['uri'=> 'data/mail_template', 'help_uri'=> 'mail'],
        ['uri'=> 'data/base_info', 'help_uri'=> 'base_info'],
        ['uri'=> 'data', 'help_uri'=> 'data'],
        ['uri'=> 'dashboard', 'help_uri'=> 'dashboard'],
        ['uri'=> 'dashboardbox', 'help_uri'=> 'dashboard'],
        ['uri'=> '/', 'help_uri'=> 'dashboard'],
    ];

    public const SETTING_SHEET_NAME = '##setting##';

    public const YESNO_RADIO = [
        ''   => 'All',
        0    => 'NO',
        1    => 'YES',
    ];

    public const DISKNAME_ADMIN_TMP = 'admin_tmp';
    public const DISKNAME_BACKUP = 'backup';
}
