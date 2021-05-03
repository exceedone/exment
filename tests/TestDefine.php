<?php

namespace Exceedone\Exment\Tests;

class TestDefine
{
    public const TESTDATA_USER_LOGINID_ADMIN = '1'; // admin
    public const TESTDATA_USER_LOGINID_USER1 = '2'; // user1
    public const TESTDATA_USER_LOGINID_USER2 = '3'; // user2
    public const TESTDATA_USER_LOGINID_DEV_USERB = '6';  //dev-userB
    public const TESTDATA_USER_LOGINID_DEV1_USERC = '7'; //dev1-userC

    public const TESTDATA_ORGANIZATION_COMPANY1 = '1'; // company1
    public const TESTDATA_ORGANIZATION_DEV = '2'; // dev

    public const TESTDATA_ROLEGROUP_GENERAL = '4'; // 一般グループ

    public const TESTDATA_TABLE_NAME_VIEW_ALL = 'custom_value_view_all';
    public const TESTDATA_TABLE_NAME_EDIT_ALL = 'custom_value_edit_all';
    public const TESTDATA_TABLE_NAME_EDIT = 'custom_value_edit';
    public const TESTDATA_TABLE_NAME_VIEW = 'custom_value_view';
    
    public const TESTDATA_TABLE_NAME_PARENT_TABLE = 'parent_table';
    public const TESTDATA_TABLE_NAME_CHILD_TABLE = 'child_table';
    public const TESTDATA_TABLE_NAME_PIVOT_TABLE = 'pivot_table';
    public const TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY = 'parent_table_n_n';
    public const TESTDATA_TABLE_NAME_CHILD_TABLE_MANY_TO_MANY = 'child_table_n_n';
    public const TESTDATA_TABLE_NAME_PIVOT_TABLE_MANY_TO_MANY = 'pivot_table_n_n';
    public const TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT = 'parent_table_select';
    public const TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT = 'child_table_select';
    public const TESTDATA_TABLE_NAME_PIVOT_TABLE_SELECT = 'pivot_table_select';
    public const TESTDATA_TABLE_NAME_PIVOT_TABLE_USER_ORG = 'pivot_table_user_org';
    public const TESTDATA_TABLE_NAME_ALL_COLUMNS = 'all_columns_table';
    public const TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST = 'all_columns_table_fortest';

    public const TESTDATA_COLUMN_NAME_PARENT = 'parent';
    public const TESTDATA_COLUMN_NAME_CHILD = 'child';
    public const TESTDATA_COLUMN_NAME_CHILD_VIEW = 'child_view';
    public const TESTDATA_COLUMN_NAME_CHILD_AJAX = 'child_ajax';
    public const TESTDATA_COLUMN_NAME_CHILD_AJAX_VIEW = 'child_ajax_view';

    public const TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER = 'child_relation_filter';
    public const TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_VIEW = 'child_relation_filter_view';
    public const TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_AJAX = 'child_relation_filter_ajax';
    public const TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_AJAX_VIEW = 'child_relation_filter_ajax_view';
    
    public const TESTDATA_COLUMN_NAME_ORGANIZATION = 'organization';
    public const TESTDATA_COLUMN_NAME_USER = 'user';
    public const TESTDATA_COLUMN_NAME_USER_VIEW = 'user_view';
    public const TESTDATA_COLUMN_NAME_USER_AJAX = 'user_ajax';
    public const TESTDATA_COLUMN_NAME_USER_AJAX_VIEW = 'user_ajax_view';

    public const TESTDATA_COLUMN_NAME_USER_RELATION_FILTER = 'user_relation_filter';
    public const TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_VIEW = 'user_relation_filter_view';
    public const TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_AJAX = 'user_relation_filter_ajax';
    public const TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_AJAX_VIEW = 'user_relation_filter_ajax_view';
    
    public const FILE_TESTSTRING_TEST = 'test'; //"test"
    public const FILE_BASE64 = 'dGVzdA=='; //"test" text file.
    public const FILE_TESTSTRING = 'This is test file'; //text file.

    public const FILE2_BASE64 = 'RXhtZW50IGlzIE9wZW4gU291cmNlIFNvZnR3YXJlLg=='; //FILE2_TESTSTRING text file.
    public const FILE2_TESTSTRING = 'Exment is Open Source Software.'; //text file.

    public const TESTDATA_DUMMY_EMAIL = 'foobar@test.com';
    public const TESTDATA_DUMMY_EMAIL2 = 'foobar2@test.com';

    public const TESTDATA_COLUMN_NAMES = [
        'default' => [
            'default' => self::TESTDATA_COLUMN_NAME_CHILD,
            'view' => self::TESTDATA_COLUMN_NAME_CHILD_VIEW,
            'ajax' => self::TESTDATA_COLUMN_NAME_CHILD_AJAX,
            'ajax_view' => self::TESTDATA_COLUMN_NAME_CHILD_AJAX_VIEW,
        ],
        'user' => [
            'default' => self::TESTDATA_COLUMN_NAME_USER,
            'view' => self::TESTDATA_COLUMN_NAME_USER_VIEW,
            'ajax' => self::TESTDATA_COLUMN_NAME_USER_AJAX,
            'ajax_view' => self::TESTDATA_COLUMN_NAME_USER_AJAX_VIEW,
        ],
        'organization' => [
            'default' => self::TESTDATA_COLUMN_NAME_ORGANIZATION,
        ],
        'relation_filter' => [
            'default' => self::TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER,
            'view' => self::TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_VIEW,
            'ajax' => self::TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_AJAX,
            'ajax_view' => self::TESTDATA_COLUMN_NAME_CHILD_RELATION_FILTER_AJAX_VIEW,
        ],
        'user_relation_filter' => [
            'default' => self::TESTDATA_COLUMN_NAME_USER_RELATION_FILTER,
            'view' => self::TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_VIEW,
            'ajax' => self::TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_AJAX,
            'ajax_view' => self::TESTDATA_COLUMN_NAME_USER_RELATION_FILTER_AJAX_VIEW,
        ],
    ];
}
