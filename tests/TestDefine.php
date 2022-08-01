<?php

namespace Exceedone\Exment\Tests;

class TestDefine
{
    public const TESTDATA_USER_LOGINID_ADMIN = '1'; // admin
    public const TESTDATA_USER_LOGINID_USER1 = '2'; // user1
    public const TESTDATA_USER_LOGINID_USER2 = '3'; // user2
    public const TESTDATA_USER_LOGINID_DEV_USERB = '6';  //dev-userB
    public const TESTDATA_USER_LOGINID_DEV1_USERC = '7'; //dev1-userC
    public const TESTDATA_USER_LOGINID_DEV1_USERD = '8'; //dev1-userD

    public const TESTDATA_ORGANIZATION_COMPANY1 = '1'; // company1
    public const TESTDATA_ORGANIZATION_DEV = '2'; // dev

    public const TESTDATA_ROLEGROUP_GENERAL = '4'; // 一般グループ

    public const TESTDATA_TABLE_NAME_VIEW_ALL = 'custom_value_view_all';
    public const TESTDATA_TABLE_NAME_EDIT_ALL = 'custom_value_edit_all';
    public const TESTDATA_TABLE_NAME_EDIT = 'custom_value_edit';
    public const TESTDATA_TABLE_NAME_VIEW = 'custom_value_view';
    public const TESTDATA_TABLE_NO_PERMISSION = 'no_permission';

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
    public const TESTDATA_TABLE_NAME_UNICODE_DATA = 'unicode_data_table';

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

    public const FILE_USERDEFALUT_BASE64 = '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';

    // https://www.iconfinder.com/icons/403022/business_man_male_user_avatar_profile_person_man_icon
    public const FILE_USER_BASE64 = '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';

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
