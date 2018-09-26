<?php

namespace Exceedone\Exment\Model;

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
    public const RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN = '^[a-zA-Z0-9\-_]*$';
    public const SYSTEM_TABLE_NAME_LOGIN_USER = 'login_user';
    public const SYSTEM_TABLE_NAME_USER = 'user';
    public const SYSTEM_TABLE_NAME_ORGANIZATION = 'organization';
    public const SYSTEM_TABLE_NAME_BASEINFO = 'base_info';
    public const SYSTEM_TABLE_NAME_DOCUMENT = 'document';

    public const SYSTEM_SETTING_ID_VALUE = [
        'initialized' => ['id' => 1, 'type' => 'boolean', 'default' => '0'],
        'site_name' => ['id' => 2, 'default' => 'Exment'],
        'site_name_short' => ['id' => 3, 'default' => 'ExM'],
        'site_logo' => ['id' => 4, 'type' => 'file', 'move' => 'system'],
        'site_logo_mini' => ['id' => 5, 'type' => 'file', 'move' => 'system'],
        'site_skin' => ['id' => 6, 'config' => 'admin.skin'],
        'authority_available' => ['id' => 7, 'type' => 'boolean', 'default' => '1'],
        'organization_available' => ['id' => 8, 'type' => 'boolean', 'default' => '1'],
        ///'system_authority' => ['id' => 9, 'type' => 'json'],
        'system_mail_from' => ['id' => 10, 'default' => 'no-reply@hogehoge.com'],
        'site_layout' => ['id' => 11, 'default' => 'layout_default'],
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

    // Dashboard --------------------------------------------------
    public const DASHBOARD_BOX_TYPE_SYSTEM = 'system';
    public const DASHBOARD_BOX_TYPE_LIST = 'list';
    
    public const DASHBOARD_BOX_TYPE_OPTIONS = [
        ['dashboard_box_type' => self::DASHBOARD_BOX_TYPE_LIST, 'icon' => 'fa-list'],
        ['dashboard_box_type' => self::DASHBOARD_BOX_TYPE_SYSTEM, 'icon' => 'fa-wrench'],
    ];

    public const DASHBOARD_BOX_SYSTEM_PAGES = [
        ['id' => 1, 'name' => 'guideline']
    ];

    public const PLUGIN_TYPE_PAGE = 'page';
    public const PLUGIN_TYPE_TRIGGER = 'trigger';
    public const PLUGIN_TYPE = [
        self::PLUGIN_TYPE_PAGE,
        self::PLUGIN_TYPE_TRIGGER,
    ];
    
    public const PLUGIN_EVENT_TRIGGER = [
        'saving',
        'saved',
        'loading',
        'loaded',
        'grid_menubutton',
        'form_menubutton_create',
        'form_menubutton_edit',
    ];

    public const TABLE_COLUMN_TYPE = [
        "text", 
        "textarea", 
        "url", 
        "email", 
        "integer", 
        "decimal",
        "calc", 
        "date", 
        "time", 
        "datetime", 
        "select", 
        "select_valtext", 
        "select_table", 
        "yesno", 
        "boolean", 
        "auto_number", 
        "image", 
        "file", 
        "user", 
        "organization",
        "document",
    ];

    public const TABLE_COLUMN_TYPE_CALC = [
        "integer", 
        "decimal",
    ];
    
    public const RELATION_TYPE_ONE_TO_MANY = 'one_to_many';
    public const RELATION_TYPE_MANY_TO_MANY = 'many_to_many';
    public const RELATION_TYPE = [
        self::RELATION_TYPE_ONE_TO_MANY,
        self::RELATION_TYPE_MANY_TO_MANY,
    ];

    public const CUSTOM_FORM_BLOCK_TYPE_DEFAULT = 'default';
    public const CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY = 'one_to_many';
    public const CUSTOM_FORM_BLOCK_TYPE_RELATION_MANY_TO_MANY = 'many_to_many';
    public const CUSTOM_FORM_BLOCK_TYPE = [
        self::CUSTOM_FORM_BLOCK_TYPE_DEFAULT,
        self::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY,
        self::CUSTOM_FORM_BLOCK_TYPE_RELATION_MANY_TO_MANY,
    ];
    public const CUSTOM_FORM_COLUMN_TYPE_COLUMN = 'column';
    public const CUSTOM_FORM_COLUMN_TYPE_OTHER = 'other';
    public const CUSTOM_FORM_COLUMN_TYPE = [
        self::CUSTOM_FORM_COLUMN_TYPE_COLUMN,
        self::CUSTOM_FORM_COLUMN_TYPE_OTHER,
    ];
    
    public const CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE = [
        1 => ['id' => 1, 'column_name' => 'header'],
        2 => ['id' => 2, 'column_name' => 'explain'],
        3 => ['id' => 3, 'column_name' => 'html'],
    ];

    public const AUTHORITY_TYPE_SYSTEM = 'system';
    public const AUTHORITY_TYPE_TABLE = 'table';
    public const AUTHORITY_TYPE_VIEW = 'view';
    public const AUTHORITY_TYPE_VALUE = 'value';
    public const AUTHORITY_TYPE_PLUGIN = 'plugin';
    public const AUTHORITY_TYPES = [
        self::AUTHORITY_TYPE_SYSTEM,
        self::AUTHORITY_TYPE_TABLE,
        //self::AUTHORITY_TYPE_VIEW,
        self::AUTHORITY_TYPE_VALUE,
        self::AUTHORITY_TYPE_PLUGIN,
    ];

    public const AUTHORITY_VALUE_SYSTEM = 'system';
    public const AUTHORITY_VALUE_DASHBOARD = 'dashboard';
    public const AUTHORITY_VALUE_CUSTOM_TABLE = 'custom_table';
    public const AUTHORITY_VALUE_CUSTOM_FORM = 'custom_form';
    public const AUTHORITY_VALUE_CUSTOM_VIEW = 'custom_view';
    public const AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL = 'custom_value_edit_all';
    public const AUTHORITY_VALUE_CUSTOM_VALUE_EDIT = 'custom_value_edit';
    public const AUTHORITY_VALUE_CUSTOM_VALUE_VIEW = 'custom_value_view';
    public const AUTHORITY_VALUE_PLUGIN_ACCESS = 'plugin_access';
    public const AUTHORITY_VALUE_PLUGIN_SETTING = 'plugin_setting';
    // available access custom value
    public const AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE = [self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL, self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT, self::AUTHORITY_VALUE_CUSTOM_VALUE_VIEW];
    // available edit custom value
    public const AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE = [self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL, self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT];

    /**
     * permission all
     */
    public const AUTHORITIES = [
        self::AUTHORITY_TYPE_SYSTEM => [
            self::AUTHORITY_VALUE_SYSTEM,
            self::AUTHORITY_VALUE_CUSTOM_TABLE,
            self::AUTHORITY_VALUE_CUSTOM_FORM,
            self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL,
        ]
        , self::AUTHORITY_TYPE_TABLE => [
            self::AUTHORITY_VALUE_CUSTOM_TABLE,
            self::AUTHORITY_VALUE_CUSTOM_FORM,
            self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT_ALL,
            self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT,
            self::AUTHORITY_VALUE_CUSTOM_VALUE_VIEW,
        ]
        // , self::AUTHORITY_TYPE_VIEW => [
        //     'label' => 'ビュー', 'defines' =>[
        //         'custom_view_edit' => ['label' => 'ビュー定義の変更・削除', 'help' => ''],
        //         'custom_view_share' => ['label' => 'ビュー定義の共有', 'help' => ''],
        //         'custom_view_use' => ['label' => 'ビュー定義の使用', 'help' => ''],
        //     ]
        // ]
        , self::AUTHORITY_TYPE_VALUE => [
            self::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT,
            self::AUTHORITY_VALUE_CUSTOM_VALUE_VIEW,
        ]
        , self::AUTHORITY_TYPE_PLUGIN => [
            self::AUTHORITY_VALUE_PLUGIN_ACCESS,
            self::AUTHORITY_VALUE_PLUGIN_SETTING,
        ]
    ];

    public const MENU_TYPE_SYSTEM = 'system';
    public const MENU_TYPE_PLUGIN = 'plugin';
    public const MENU_TYPE_TABLE = 'table';
    public const MENU_TYPE_CUSTOM = 'custom';

    public const MENU_TYPES = [
        self::MENU_TYPE_SYSTEM,
        self::MENU_TYPE_PLUGIN,
        self::MENU_TYPE_TABLE,
        self::MENU_TYPE_CUSTOM,
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
        'plugin' => [
            'uri' => 'plugin',
            'icon' => 'fa-plug',
        ],
        'custom_table' => [
            'uri' => 'table',
            'icon' => 'fa-table',
        ],
        'authority' => [
            'uri' => 'authority',
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
        'skip',
    ];

    public const SYSTEM_KEY_SESSION_INITIALIZE = "initialize";
    public const SYSTEM_KEY_SESSION_AUTHORITY = "authority";
    public const SYSTEM_KEY_SESSION_USER_SETTING = "user_setting";
    public const SYSTEM_KEY_SESSION_ORGANIZATION_IDS = "organization_ids";

    public const GRID_CHANGE_PAGE_MENULIST = [
        ['url' => 'table', 'icon' => 'fa-table', 'move_edit' => true, 'authorities' => [self::AUTHORITY_VALUE_CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_table'],
        ['url' => 'column', 'icon' => 'fa-list', 'authorities' => [self::AUTHORITY_VALUE_CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_column'],
        ['url' => 'form', 'icon' => 'fa-keyboard-o', 'authorities' => [self::AUTHORITY_VALUE_CUSTOM_FORM], 'exmtrans' => 'change_page_menu.custom_form'],
        ['url' => 'view', 'icon' => 'fa-th-list', 'authorities' => [self::AUTHORITY_VALUE_CUSTOM_VIEW], 'exmtrans' => 'change_page_menu.custom_view'],
        ['url' => 'relation', 'icon' => 'fa-compress', 'authorities' => [self::AUTHORITY_VALUE_CUSTOM_TABLE], 'exmtrans' => 'change_page_menu.custom_relation'],
        ['url' => 'data', 'icon' => 'fa-database', 'authorities' => self::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE, 'exmtrans' => 'change_page_menu.custom_value'],
    ];

    public const NOTIFY_TRIGGER = [
        '1' => 'time',
    ];
    public const NOTIFY_BEFOREAFTER = [
        '-1' => 'before',
        '1'  => 'after',
    ];
    public const NOTIFY_ACTION = [
        '1' => 'email',
    ];
    public const NOTIFY_ACTION_TARGET = [
        'has_authorities',
    ];
 
    // User Setting --------------------------------------------------
    public const USER_SETTING_DASHBOARD = 'user_setting_dashboard';
    public const USER_SETTING_FORM = 'user_setting_form';
    public const USER_SETTING_VIEW = 'user_setting_view';
    
    // Template --------------------------------------------------
    public const TEMPLATE_EXPORT_TARGET_TABLE = 'table';
    public const TEMPLATE_EXPORT_TARGET_DASHBOARD = 'dashboard';
    public const TEMPLATE_EXPORT_TARGET_MENU = 'menu';
    public const TEMPLATE_EXPORT_TARGET_AUTHORITY = 'authority';
    public const TEMPLATE_EXPORT_TARGET_MAIL_TEMPLATE = 'mail_template';
    
    public const TEMPLATE_EXPORT_TARGET = [
        self::TEMPLATE_EXPORT_TARGET_TABLE,
        self::TEMPLATE_EXPORT_TARGET_MENU,
        self::TEMPLATE_EXPORT_TARGET_DASHBOARD,
        self::TEMPLATE_EXPORT_TARGET_AUTHORITY,
        self::TEMPLATE_EXPORT_TARGET_MAIL_TEMPLATE,
    ];

    public const TEMPLATE_EXPORT_TARGET_DEFAULT = [
        self::TEMPLATE_EXPORT_TARGET_TABLE,
        self::TEMPLATE_EXPORT_TARGET_MENU,
    ];

    // Mail Template --------------------------------------------------

    public const MAIL_TEMPLATE_TYPE_HEADER = 'header';
    public const MAIL_TEMPLATE_TYPE_BODY = 'body';
    public const MAIL_TEMPLATE_TYPE_FOOTER = 'footer';
    public const MAIL_TEMPLATE_TYPE = [
        self::MAIL_TEMPLATE_TYPE_HEADER,
        self::MAIL_TEMPLATE_TYPE_BODY,
        self::MAIL_TEMPLATE_TYPE_FOOTER,
    ];

    // VIEW --------------------------------------------------
    public const VIEW_COLUMN_TYPE_SYSTEM = 'system';
    public const VIEW_COLUMN_TYPE_COLUMN = 'column';
    public const VIEW_COLUMN_SYSTEM_OPTIONS = [
        ['name' => 'id', 'default' => true, 'order' => 1, 'header' => true],
        ['name' => 'suuid', 'default' => false, 'order' => 2, 'header' => true],
        ['name' => 'created_at', 'default' => true, 'order' => 98, 'footer' => true],
        ['name' => 'updated_at', 'default' => true, 'order' => 99, 'footer' => true],
    ];
    public const VIEW_COLUMN_FILTER_TYPE_DEFAULT = 'default';
    public const VIEW_COLUMN_FILTER_TYPE_DAY = 'day';
    public const VIEW_COLUMN_FILTER_TYPE_USER = 'user';
    
    public const VIEW_COLUMN_FILTER_OPTION_EQ = 1; 
    public const VIEW_COLUMN_FILTER_OPTION_NE = 2; 
    public const VIEW_COLUMN_FILTER_OPTION_NOT_NULL = 3;
    public const VIEW_COLUMN_FILTER_OPTION_NULL = 4;

    public const VIEW_COLUMN_FILTER_OPTION_DAY_ON = 1001;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_ON_OR_AFTER = 1002;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_ON_OR_BEFORE = 1003;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NOT_NULL = 1004;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NULL = 1005;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_TODAY = 1011;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_TODAY_OR_AFTER = 1012;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_TODAY_OR_BEFORE = 1013;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_YESTERDAY = 1014;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_TOMORROW = 1015;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_THIS_MONTH = 1021;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_LAST_MONTH = 1022;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_MONTH = 1023;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_THIS_YEAR = 1031;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_LAST_YEAR = 1032;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_YEAR = 1033;

    public const VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_AFTER = 1041;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_BEFORE = 1042;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_AFTER = 1043;
    public const VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_BEFORE = 1044;
    
    public const VIEW_COLUMN_FILTER_OPTION_USER_EQ = 2001; 
    public const VIEW_COLUMN_FILTER_OPTION_USER_NE = 2002; 
    public const VIEW_COLUMN_FILTER_OPTION_USER_NOT_NULL = 2003;
    public const VIEW_COLUMN_FILTER_OPTION_USER_NULL = 2004;
    public const VIEW_COLUMN_FILTER_OPTION_USER_EQ_USER = 2011; 
    public const VIEW_COLUMN_FILTER_OPTION_USER_NE_USER = 2012; 


    public const VIEW_COLUMN_FILTER_OPTIONS = [
        self::VIEW_COLUMN_FILTER_TYPE_DEFAULT => [
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_EQ, 'name' => 'eq'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_NE, 'name' => 'ne'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_NOT_NULL, 'name' => 'not-null'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_NULL, 'name' => 'null'],
        ],
        self::VIEW_COLUMN_FILTER_TYPE_DAY => [
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_ON, 'name' => 'on'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_ON_OR_AFTER, 'name' => 'on-or-after'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_ON_OR_BEFORE, 'name' => 'on-or-before'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_TODAY, 'name' => 'today'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_TODAY_OR_AFTER, 'name' => 'today-or-after'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_TODAY_OR_BEFORE, 'name' => 'today-or-before'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_YESTERDAY, 'name' => 'yesterday'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_TOMORROW, 'name' => 'tomorrow'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_MONTH, 'name' => 'this-month'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_MONTH, 'name' => 'last-month'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_MONTH, 'name' => 'next-month'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_THIS_YEAR, 'name' => 'this-year'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_YEAR, 'name' => 'last-year'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_YEAR, 'name' => 'next-year'],
            
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_AFTER, 'name' => 'last-x-day-after'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_AFTER, 'name' => 'next-x-day-after'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_LAST_X_DAY_OR_BEFORE, 'name' => 'last-x-day-or-before'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NEXT_X_DAY_OR_BEFORE, 'name' => 'next-x-day-or-before'],
            
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NOT_NULL, 'name' => 'not-null'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_DAY_NULL, 'name' => 'null'],
        ],
        self::VIEW_COLUMN_FILTER_TYPE_USER => [
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_EQ_USER, 'name' => 'eq-user'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_NE_USER, 'name' => 'ne-user'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_EQ, 'name' => 'eq'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_NE, 'name' => 'ne'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_NOT_NULL, 'name' => 'not-null'],
            ['id' => self::VIEW_COLUMN_FILTER_OPTION_USER_NULL, 'name' => 'null'],
        ],
    ];

}