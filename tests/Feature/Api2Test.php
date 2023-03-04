<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Tests\TestDefine;
use Carbon\Carbon;

class Api2Test extends ApiTestBase
{
    public function testOkAuthorize()
    {
        $response = $this->getPasswordToken('admin', 'adminadmin');

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'token_type',
                'expires_in',
                'access_token',
                'refresh_token',
            ]);
    }

    public function testOkAuthorizeApiKey()
    {
        $response = $this->getApiKey();

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'token_type',
                'expires_in',
                'access_token',
                'refresh_token',
            ]);
    }

    public function testErrorAuthorize()
    {
        $response = $this->getPasswordToken('adjfjke', 'adjfjkeadjfjkeadjfjkeadjfjke');

        $response
            ->assertStatus(401);
    }

    public function testErrorNoToken()
    {
        $this->get(admin_urls('api', 'version'))
            ->assertStatus(401);
    }

    public function testGetVersion()
    {
        $token = $this->getAdminAccessToken();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'version'))
            ->assertStatus(200)
            ->assertJson([
                'version' => \Exment::getExmentCurrentVersion()
            ]);
    }

    public function testGetVersionApiKey()
    {
        $token = $this->getAdminAccessTokenAsApiKey();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'version'))
            ->assertStatus(200)
            ->assertJson([
                'version' => \Exment::getExmentCurrentVersion()
            ]);
    }

    public function testGetTablesAdmin()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'table_name' => 'information',
            ])
        ;
    }

    public function testGetTablesWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetTablesById()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=7')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'table_name' => 'mail_send_log',
            ]);
    }

    public function testGetTablesByMultiId()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=3,5,8')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetTablesExpand()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=5&expands=columns')
            ->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'parent_organization',
                'column_view_name' => '親組織',
                'column_type' => 'organization',
                "system_flg"=> "1",
                "order"=> "0",
                'options' => [
                    "index_enabled"=> "1",
                    "freeword_search"=> "1",
                ]
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'custom_columns',
                        ],
                    ],
                ]);
    }

    public function testGetTablesUser()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');

        $this->assertTrue(!\is_nullorempty($data));
        $this->assertTrue(
            collect($data)->contains(function ($d) {
                return array_get($d, 'table_name') == 'custom_value_edit';
            })
        );
        $this->assertTrue(
            !collect($data)->contains(function ($d) {
                return array_get($d, 'table_name') == 'no_permission';
            })
        );
    }

    public function testGetTablesNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=999999')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetTables()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'custom_value_edit'))
            ->assertStatus(200);
    }

    public function testGetTableById()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', '2'))
            ->assertStatus(200);
    }

    public function testGetTableUser()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'custom_value_edit'))
            ->assertStatus(200);
    }

    public function testDenyGetTableUser()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'no_permission'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testNotFoundGetTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'fufhiuviveju'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testWrongScopeGetTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetTableColumns()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information', 'columns'))
            ->assertStatus(200)
            ->assertJsonCount(5)
            ->assertJsonFragment([
                'column_name' => 'view_flg',
                'column_view_name' => '表示フラグ',
                'column_type' => 'yesno',
                'system_flg'=> '0',
                'order'=> '0',
                'options' => [
                    'index_enabled'=> '1',
                    'freeword_search'=> '1',
                    'default'=> '1',
                    'required'=> '1',
                    'help'=> '一覧表示したい場合、YESに設定してください。',
                ]
            ]);
    }

    public function testGetWrongTableColumns()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'notable', 'columns'))
            ->assertStatus(404);
    }

    public function testDenyGetTableColumns()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'no_permission', 'columns'))
            ->assertStatus(403);
    }

    public function testGetColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $column = CustomColumn::getEloquent('user', 'mail_send_log');
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', $column->id))
            ->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'user',
                'column_view_name' => '送信対象ユーザー',
                'column_type' => 'user',
                'system_flg'=> '1',
                'order'=> '0',
                'options' => [
                    'index_enabled'=> '1',
                    'freeword_search' => '1',
                ]
            ]);
    }

    public function testNotFoundGetColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 999999999))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testWrongScopeGetColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 5))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testDenyGetColumn()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        // get no_permission table's column.
        $column = CustomColumn::getEloquent('text', CustomTable::getEloquent('no_permission'));

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', $column->id))
            ->assertStatus(403);
    }


    public function testGetColumnByName()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information', 'column', 'title'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'title',
                'column_view_name' => 'タイトル',
                'column_type' => 'text',
            ]);
    }

    public function testNotFoundGetColumnByName()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
            ])->get(admin_urls('api', 'table', 'information', 'column', 'foobar'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testWrongScopeGetColumnByName()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information', 'column', 'title'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testDenyGetColumnByName()
    {
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        // get no_permission table's column.
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'no_permission', 'column', 'text'))
            ->assertStatus(403);
    }




    // get custom value -------------------------------------


    public function testGetValues()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(200);
    }

    public function testGetValuesWithPage()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all').'?page=3')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testGetValuesWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all').'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetValuesWithOrder()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all').'?orderby=user%20desc,id%20asc')
            ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        $value = array_get($data[0], 'value');
        $this->assertMatch(array_get($value, 'user'), '9');
    }

    public function testGetValuesByMultiId()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit').'?id=1,2,4')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /**
     * Getting values children, and match ids
     */
    public function testGetValuesWithChildren()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE).'?children=1&count=5')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');

        // check children
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');

        foreach ($data as $d) {
            $this->_testChildrenValues($d, TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE, array_get($d, 'id'));
        }
    }

    /**
    * Getting values children, and match ids
    */
    public function testGetValuesWithChildren2()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
           'Authorization' => "Bearer $token",
       ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY).'?children=1&count=5')
           ->assertStatus(200)
           ->assertJsonCount(5, 'data');

        // check children
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');

        foreach ($data as $d) {
            $this->_testChildrenValues($d, TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY, array_get($d, 'id'));
        }
    }

    public function testWrongScopeGetValues()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testInvalidOrderGetValues()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all').'?orderby=id%20besc')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testNoIndexOrderGetValues()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all').'?orderby=text')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::NOT_INDEX_ENABLED
            ]);
    }

    public function testGetValuesPermissionCheck()
    {
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);
        // update config for test
        \Config::set('exment.api_max_data_count', 10000);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit').'?count=1000')
            ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        // get ids
        $ids = collect(array_get($json, 'data'))->map(function ($j) {
            return array_get($j, 'id');
        })->toArray();

        $this->checkCustomValuePermission(CustomTable::getEloquent('custom_value_edit'), $ids);
    }


    public function testNotFoundGetValues()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'afkheiufu'))
            ->assertStatus(404);
    }

    public function testGetValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 1))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 1
            ]);
    }

    /**
     * Getting value children, and match ids
     */
    public function testGetValueWithChildren()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE, '1?children=1'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 1
            ]);

        // check children
        $d = json_decode_ex($response->baseResponse->getContent(), true);
        $this->_testChildrenValues($d, TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE, array_get($d, 'id'));
    }

    /**
     * Getting value children, and match ids
     */
    public function testGetValueWithChildren2()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY, '1?children=1'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 1
            ]);

        // check children
        $d = json_decode_ex($response->baseResponse->getContent(), true);
        $this->_testChildrenValues($d, TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_MANY_TO_MANY, array_get($d, 'id'));
    }

    public function testWrongScopeGetValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 1))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testNotFoundGetValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'afkheiufu', 1))
            ->assertStatus(404);
    }

    public function testNotIdGetValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 99999))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }




    // get custom value with view -------------------------------------


    public function testGetViewData()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid))
            ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        $column_definitions = array_get($json, 'column_definitions');
        $user_key = collect($column_definitions)->filter(function ($val) {
            return array_get($val, 'column_name') == 'user';
        })->keys()->first();
        $this->assertMatch(array_get($data[0], $user_key), '1');
    }

    public function testGetViewDataWithPage()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid).'?page=3')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testGetViewDataWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid).'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetViewDataWithValueType()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid).'?valuetype=text')
            ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        $column_definitions = array_get($json, 'column_definitions');
        $user_key = collect($column_definitions)->filter(function ($val) {
            return array_get($val, 'column_name') == 'user';
        })->keys()->first();
        $this->assertMatch(array_get($data[0], $user_key), 'admin');
    }

    public function testGetViewDataWithSort()
    {
        $this->skipTempTest('ビューのソート処理について見直し');

        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST . '-select-table-1')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $custom_view->suuid))
            ->assertStatus(200);

        $check_data = $custom_view->getQueryData();

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        $column_definitions = array_get($json, 'column_definitions');
        $id_key = collect($column_definitions)->filter(function ($val) {
            return array_get($val, 'column_name') == 'id';
        })->keys()->first();
        foreach ($data as $index => $row) {
            $this->assertMatch(array_get($row, $id_key), array_get($check_data[$index], 'id'));
        }
    }

    public function testGetViewDataById()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid, 3))
            ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = array_get($json, 'value');
        $column_definitions = array_get($json, 'column_definitions');
        $id_key = collect($column_definitions)->filter(function ($val) {
            return array_get($val, 'column_name') == 'id';
        })->keys()->first();
        $this->assertMatch(array_get($data, $id_key), '3');
    }

    public function testWrongScopeGetViewData()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testNotFoundGetViewData()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, 'afkheiufu', 1))
            ->assertStatus(400);
    }

    public function testNotIdGetViewData()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid, 99999))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }




    // post value -------------------------------------

    public function testCreateValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 2
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 2
            ],
            'created_user_id' => "1" //ADMIN
        ]);
    }

    public function testCreateValueWithParent()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'child_table'), [
            'parent_id' => 5,
            'parent_type' => 'parent_table',
            'value' => [
                'text' => $text,
                'index_text' => $text,
                'user' => 2
            ]
        ])
        ->assertStatus(201);
        $this->assertJsonTrue($response, [
            'parent_id' => '5',
            'parent_type' => 'parent_table',
            'value' => [
                'text' => $text,
                'index_text' => $text,
                'user' => 2
            ],
            'created_user_id' => "1" //ADMIN
        ]);
    }

    public function testCreateValueWrongParent()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'child_table'), [
            'parent_id' => 999,
            'parent_type' => 'parent_table',
            'value' => [
                'text' => $text,
                'user' => 2
            ]
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testCreateMultipleValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $pre_count = CustomTable::getEloquent('custom_value_edit')->getValueModel()->count();
        $values = [];
        for ($i = 1; $i <= 3; $i++) {
            $values[] = ['text' => 'test' . date('YmdHis') . $i];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), ['value' => $values])
            ->assertStatus(200);
        $count = CustomTable::getEloquent('custom_value_edit')->getValueModel()->count();
        $this->assertMatch(($pre_count + 3), $count);
    }

    public function testCreateMultipleValueWithParent()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $pre_count = getModelName('parent_table')::find(4)
            ->getChildrenValues('child_table')->count();
        $values = [];
        for ($i = 1; $i <= 3; $i++) {
            $values[] = [
                'parent_id' => 4,
                'parent_type' => 'parent_table',
                'text' => 'test' . date('YmdHis') . $i
            ];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'child_table'), ['value' => $values])
            ->assertStatus(200);
        $count = getModelName('parent_table')::find(4)
            ->getChildrenValues('child_table')->count();
        $this->assertMatch(($pre_count + 3), $count);
    }

    public function testCreateMultipleValueWrongParent()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $values = [];
        for ($i = 1; $i <= 3; $i++) {
            $values[] = [
                'parent_id' => 4,
                'parent_type' => $i == 2 ? 'user' : 'parent_table',
                'text' => 'test' . date('YmdHis') . $i
            ];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'child_table'), ['value' => $values])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testCreateMultipleValueWithParent2()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $parents = getModelName('parent_table')::find([1,2,3]);
        $pre_count = $parents->sum(function ($parent) {
            return $parent->getChildrenValues('child_table')->count();
        });

        $data = [];
        for ($i = 1; $i <= 3; $i++) {
            $data[] = [
                'parent_id' => $i,
                'parent_type' => 'parent_table',
                'value' => [
                    'text' => 'test' . date('YmdHis') . $i
                ]
            ];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'child_table'), ['data' => $data])
            ->assertStatus(200);
        $parents = getModelName('parent_table')::find([1,2,3]);
        $count = $parents->sum(function ($parent) {
            return $parent->getChildrenValues('child_table')->count();
        });
        $this->assertMatch(($pre_count + 3), $count);
    }

    public function testCreateValueWithFindkey()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 'user3'
            ],
            'findKeys' => [
                'user' => 'user_name'
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 4
            ],
            'created_user_id' => "1" //ADMIN
        ]);
    }

    public function testCreateNoValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'novalue' => [
                'text' => $text
            ]
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testOverCreateValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $values = [];
        for ($i = 1; $i <= 101; $i++) {
            $values[] = ['text' => 'test' . date('YmdHis') . $i];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), ['value' => $values])
            ->assertStatus(400);
    }

    public function testWrongScopeCreateValue()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text
            ]
        ])
        ->assertStatus(403)
        ->assertJsonFragment([
            'code' => ErrorCode::WRONG_SCOPE
        ]);
    }

    public function testCreateValueInvalidFindkey()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 'user3'
            ],
            'findKeys' => [
                'user' => 'user_column'
            ]
        ])
        ->assertStatus(400);
    }

    public function testCreateValueFindkeyNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 'bjlfjadflvjlav'
            ],
            'findKeys' => [
                'user' => 'user_name'
            ]
        ])
        ->assertStatus(400);
    }

    public function testCreateValueRequiredError()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'user' => 3
            ]
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testUpdateValue()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()
            ->where('updated_user_id', '<>', '1')->first();
        $index_text = array_get($data->value, 'index_text');

        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $response =$this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', $data->id), [
            'value' => [
                'text' => $text,
                'user' => 3,
            ]
        ])->assertStatus(200);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 3,
                'index_text' => $index_text,
            ],
            'updated_user_id' => '1' //ADMIN
        ]);
    }

    public function testUpdateValueWithFindKey()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()
            ->where('updated_user_id', '<>', '2')->first();
        $old_text = array_get($data->value, 'text');
        $index_text = array_get($data->value, 'index_text');

        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', $data->id), [
            'value' => [
                'user' => 'dev1-userD',
            ],
            'findKeys' => [
                'user' => 'user_code'
            ]
        ])
        ->assertStatus(200);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $old_text,
                'user' => 8,
                'index_text' => $index_text,
            ],
            'updated_user_id' => '2' //ADMIN
        ]);
    }

    public function testUpdateValueWithParent()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('child_table')->getValueModel()
            ->where('parent_id', 1)->first();

        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'child_table', $data->id), [
            'parent_id' => 6,
            'value' => [
                'text' => $text,
            ]
        ])
        ->assertStatus(200);

        $this->assertJsonTrue($response, [
            'parent_id' => '6',
            'parent_type' => 'parent_table',
            'value' => [
                'text' => $text,
            ],
            'updated_user_id' => '2' //ADMIN
        ]);
    }

    public function testUpdateValueOnlyParent()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('child_table')->getValueModel()
            ->where('parent_id', 3)->first();

        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'child_table', $data->id), [
            'value' => [
                'parent_id' => 8,
                'parent_type' => 'parent_table',
            ]
        ])
        ->assertStatus(200);

        $this->assertJsonTrue($response, [
                'parent_id' => '8',
                'parent_type' => 'parent_table',
                'value' => $data->value,
                'updated_user_id' => '2' //ADMIN
            ]);
    }

    public function testUpdateValueWithParent2()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('child_table')->getValueModel()
            ->where('parent_id', 2)->first();

        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'child_table', $data->id), [
            'value' => [
                'parent_id' => 7,
                'parent_type' => 'parent_table',
                'text' => $text,
            ]
        ])
        ->assertStatus(200);

        $this->assertJsonTrue($response, [
            'parent_id' => '7',
            'parent_type' => 'parent_table',
            'value' => [
                'text' => $text,
            ],
            'updated_user_id' => '2' //ADMIN
        ]);
    }

    public function testUpdateValueNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', '99999'), [
            'value' => [
                'text' => $text,
            ]
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testUpdateValueNoPermissionData()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()
            ->where('created_user_id', '<>', '3')->first();

        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', $data->id), [
            'value' => [
                'text' => 'test' . date('YmdHis') . '_update',
            ]
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testUpdateValueInitOnly()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()
            ->where('updated_user_id', '<>', '1')->first();
        $init_text = array_get($data->value, 'init_text');

        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $response =$this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', $data->id), [
            'value' => [
                'init_text' => $text,
            ]
        ])->assertStatus(400);
    }


    public function testDeleteValue()
    {
        $this->_testDeleteValue(false, true);
    }

    /**
     * Force deleting
     *
     * @return void
     */
    public function testDeleteValueForce()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $this->_testDeleteValue(true, false);
    }

    /**
     * Force deleting
     *
     * @return void
     */
    public function testDeleteValueForceAlreadyTrashed()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $this->_testDeleteValue(true, false, true);
    }

    /**
     * Force deleting
     *
     * @return void
     */
    public function testDeleteValueForceConfig()
    {
        \Config::set('exment.delete_force_custom_value', true);
        $this->_testDeleteValue(false, false);
    }

    protected function _testDeleteValue(bool $appendForceQuery, bool $isGetTrashed, bool $isAlreadyTrashed = false)
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $id = 80;
        for ($i = 0; $i < 100; $i++) {
            $query = CustomTable::getEloquent('custom_value_edit')->getValueModel()->query();
            if ($isAlreadyTrashed) {
                /** @phpstan-ignore-next-line */
                $query->onlyTrashed();
            }
            $data = $query->find($id + $i);
            if (isset($data)) {
                $id += $i;
                break;
            }
        }
        $this->assertTrue(isset($data));

        $url = $appendForceQuery ? admin_urls_query('api', 'data', 'custom_value_edit', $id, ['force' => 1]) : admin_urls('api', 'data', 'custom_value_edit', $id);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete($url)
            ->assertStatus(204);

        // check not exists (and contains trashed data)
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()->find($id);
        $this->assertTrue(!isset($data));

        /** @phpstan-ignore-next-line */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()->query()->onlyTrashed()->find($id);
        $this->assertTrue($isGetTrashed ? isset($data) : !isset($data));
    }


    public function testDeleteValueNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete(admin_urls('api', 'data', 'custom_value_edit', '99999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }


    public function testDeleteValueNoPermissionData()
    {
        /** @var mixed $data */
        $data = CustomTable::getEloquent('custom_value_edit')->getValueModel()
            ->where('created_user_id', '<>', '3')->first();

        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete(admin_urls('api', 'data', 'custom_value_edit', $data->id))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }



    public function testDataQuery()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query').'?q=index_002')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data');
    }

    public function testDataQueryWithPage()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query').'?q=index&page=3')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testDataQueryWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query').'?q=index_001&count=5')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function testDataQueryNoParam()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testDenyDataQuery()
    {
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'no_permission', 'query').'?q=index_003')
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }


    public function testDataQueryPermissionCheck()
    {
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit', 'query').'?q=index_001&count=100')
            ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        // get ids
        $ids = collect(array_get($json, 'data'))->map(function ($j) {
            return array_get($j, 'id');
        })->toArray();

        $this->checkCustomValuePermission(CustomTable::getEloquent('custom_value_edit'), $ids, function ($query) {
            $query->where('value->index_text', 'LIKE', 'index_001%');
        });
    }



    // Query column ----------------------------------------------------
    public function testDataQueryColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit_all', 'query-column').'?q=index_text%20ne%20index_002_001,id%20gte%20100,id%20lte%201000')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testDataQueryColumnWithPage()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit_all', 'query-column').'?q=id%20lt%2050&page=2')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testDataQueryColumnWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit_all', 'query-column').'?q=created_user_id%20eq%202&count=4')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }


    public function testDataQueryColumnPermissionCheck()
    {
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        \Config::set('exment.api_max_data_count', 10000);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit', 'query-column').'?q=odd_even%20eq%20odd&count=1000')
            ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        // get ids
        $ids = collect(array_get($json, 'data'))->map(function ($j) {
            return array_get($j, 'id');
        })->toArray();

        $this->checkCustomValuePermission(CustomTable::getEloquent('custom_value_edit'), $ids, function ($query) {
            $query->where('value->odd_even', 'odd');
        });
    }


    public function testDataQueryColumnNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query-column').'?q=index_text%20eq%20index_002_001,created_user_id%20ne%202')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testDataQueryColumnNoParam()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query-column'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testDataQueryColumnErrorColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query-column').'?q=no_column%20eq%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDataQueryColumnErrorOperand()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query-column').'?q=id%20in%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDataQueryColumnNoIndex()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_access_all', 'query-column').'?q=text%20eq%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::NOT_INDEX_ENABLED
            ]);
    }

    public function testDenyDataQueryColumn()
    {
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'no_permission', 'query-column').'?q=index_text%20eq%20index_002_001')
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testGetNotify()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_READ]);
        $itemCount = NotifyNavbar::withoutGlobalScopes()->where('read_flg', 0)->where('target_user_id', TestDefine::TESTDATA_USER_LOGINID_ADMIN)->count();
        \Config::set('exment.api_max_data_count', 10000);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'notify', ['count' => 10000]))
            ->assertStatus(200)
            ->assertJsonCount($itemCount, 'data');
    }

    public function testGetNotifyAll()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_WRITE]);
        $itemCount = NotifyNavbar::withoutGlobalScopes()->where('target_user_id', TestDefine::TESTDATA_USER_LOGINID_ADMIN)->count();
        \Config::set('exment.api_max_data_count', 10000);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'notify', ['count' => 10000, 'all' => 1]))
            ->assertStatus(200)
            ->assertJsonCount($itemCount, 'data');
    }

    public function testGetNotifyWithCount()
    {
        $token = $this->getUser1AccessToken([ApiScope::NOTIFY_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify').'?count=4')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function testGetNotifyNotFound()
    {
        $token = $this->getUser2AccessToken([ApiScope::NOTIFY_READ]);
        NotifyNavbar::withoutGlobalScopes()->where('target_user_id', TestDefine::TESTDATA_USER_LOGINID_USER2)->delete();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetNotify()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }





    // file, document, attachment -------------------------------------
    // test file column
    public function testPostFile()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 2,
                'file' => [
                    'name' => 'test.txt',
                    'base64' => TestDefine::FILE_BASE64,
                ],
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 2
            ],
            'created_user_id' => "1" //ADMIN
        ]);

        $this->assertFileUrl($token, $response);
    }

    public function testPutFile()
    {
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'custom_value_edit', 1), [
            'value' => [
                'file' => [
                    'name' => 'test.txt',
                    'base64' => TestDefine::FILE_BASE64,
                ],
            ]
        ])
        ->assertStatus(200);

        $this->assertFileUrl($token, $response);
    }

    public function testPostFileMultiple()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST), [
            'value' => [
                'text' => $text,
                'user' => 2,
                'file_multiple' => [
                    [
                        'name' => 'test.txt',
                        'base64' => TestDefine::FILE_BASE64,
                    ],
                    [
                        'name' => 'test2.txt',
                        'base64' => TestDefine::FILE2_BASE64,
                    ],
                ],
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 2
            ],
            'created_user_id' => "1" //ADMIN
        ]);

        $this->assertFilesUrl($token, $response, ['test', TestDefine::FILE2_TESTSTRING]);
    }

    /**
     * Put file multiple, not contains file.
     */
    public function testPutFileMultiple()
    {
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);
        $custom_column = CustomColumn::getEloquent('file_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);

        /** @var CustomTable $custom_value */
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)
            ->getValueQuery()
            ->whereNull($custom_column->getQueryKey())
            ->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $custom_value->id), [
            'value' => [
                'file_multiple' => [
                    [
                        'name' => 'test.txt',
                        'base64' => TestDefine::FILE_BASE64,
                    ],
                    [
                        'name' => 'test2.txt',
                        'base64' => TestDefine::FILE2_BASE64,
                    ],
                ],
            ]
        ])
        ->assertStatus(200);

        $this->assertFilesUrl($token, $response, ['test', TestDefine::FILE2_TESTSTRING]);
    }

    /**
     * Put file multiple, append file.
     */
    public function testPutFileMultipleAppend()
    {
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);
        $custom_column = CustomColumn::getEloquent('file_multiple', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);

        /** @var CustomTable $custom_value */
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)
            ->getValueQuery()
            ->whereNull($custom_column->getQueryKey())
            ->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $custom_value->id), [
            'value' => [
                'file_multiple' => [
                    [
                        'name' => 'test2.txt',
                        'base64' => TestDefine::FILE2_BASE64,
                    ],
                ],
            ]
        ])
        ->assertStatus(200);
        $this->assertFilesUrl($token, $response, [TestDefine::FILE2_TESTSTRING]);


        // Append file ----------------------------------------------------
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $custom_value->id), [
            'value' => [
                'file_multiple' => [
                    [
                        'name' => 'test.txt',
                        'base64' => TestDefine::FILE_BASE64,
                    ],
                ],
            ]
        ])
        ->assertStatus(200);

        $this->assertFilesUrl($token, $response, [TestDefine::FILE2_TESTSTRING, 'test']);
    }

    public function testPostDocument()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'document', 'custom_value_edit', 1), [
            'name' => 'test1.txt',
            'base64' => TestDefine::FILE_BASE64, //"test" text file.
        ])
        ->assertStatus(201);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $this->assertTrue(array_has($json, 'url'));
        $this->assertTrue(array_has($json, 'created_at'));
        $this->assertTrue(array_has($json, 'created_user_id'));
        $this->assertMatch(array_get($json, 'name'), 'test1.txt');
    }

    public function testGetDocument()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'document', 'custom_value_edit', 1))
        ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $data = collect(array_get($json, 'data'))->first();

        $this->assertMatch(array_get($data, 'url'), $document->url);
        $this->assertMatch(array_get($data, 'api_url'), $document->api_url);
        $this->assertMatch(array_get($data, 'name'), $document->label);
        $this->assertMatch(array_get($data, 'created_at'), $document->created_at->__toString());
        $this->assertMatch(array_get($data, 'created_user_id'), $document->created_user_id);
    }

    public function testDownloadFile()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->sortBy('id')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'files', $document->file_uuid))
        ->assertStatus(200);

        $file = $response->baseResponse->getContent();

        $this->assertMatch($file, TestDefine::FILE_TESTSTRING);
    }

    public function testDownloadFileJson()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->sortBy('id')->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'files', $document->file_uuid . '?base64=1'))
        ->assertStatus(200);

        $json = json_decode_ex($response->baseResponse->getContent(), true);

        $this->assertMatch(array_get($json, 'name'), $document->label);
        $this->assertMatch(array_get($json, 'base64'), base64_encode(TestDefine::FILE_TESTSTRING));
    }

    public function testNoPermissionCreateDocument()
    {
        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'document', 'custom_value_edit', 1))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testNoPermissionGetDocuments()
    {
        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'document', 'custom_value_edit', 1))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testNoPermissionDownloadFile()
    {
        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get($document->api_url)
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testNoPermissionDeleteFile()
    {
        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete($document->api_url)
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testWrongScopeCreateDocument()
    {
        /// check not permission by user
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'document', 'custom_value_edit', 1), [
            'name' => 'test1.txt',
            'base64' => TestDefine::FILE_BASE64, //"test" text file.
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testWrongScopeGetDocuments()
    {
        /// check not permission by user
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'document', 'custom_value_edit', 1))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testWrongScopeDownloadFile()
    {
        /// check not permission by user
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get($document->api_url)
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testWrongScopeDeleteFile()
    {
        /// check not permission by user
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete($document->api_url)
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testDeleteFile()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')->getValueModel(1);
        $document = $custom_value->getDocuments()->first();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete($document->api_url)
            ->assertStatus(204);
    }

    // post notify -------------------------------------

    public function testCreateNotify()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_WRITE]);

        $subject = 'subject_' . date('YmdHis');
        $body = 'body_' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'notify'), [
            'target_users' => 10,
            'notify_subject' => $subject,
            'notify_body' => $body
        ])
            ->assertStatus(201)
            ->assertSeeText($subject)
            ->assertSeeText($body);
    }

    public function testCreateNotifyMultiple()
    {
        $token = $this->getUser1AccessToken([ApiScope::NOTIFY_WRITE]);

        $subject = 'subject_' . date('YmdHis');
        $body = 'body_' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'notify'), [
            'target_users' => '4,6,8',
            'notify_subject' => $subject,
            'notify_body' => $body
        ])
            ->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testCreateNotifyNoRequired()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_WRITE]);

        $subject = 'subject_' . date('YmdHis');
        $body = 'body_' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'notify'), [
            'target_users' => 1,
            'notify_subject' => $subject
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testCreateNotifyNoUser()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_WRITE]);

        $subject = 'subject_' . date('YmdHis');
        $body = 'body_' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'notify'), [
            'target_users' => [4,6,999],
            'notify_subject' => $subject,
            'notify_body' => $body
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testWrongScopeCreateNotify()
    {
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_READ]);

        $subject = 'subject_' . date('YmdHis');
        $body = 'body_' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'notify'), [
            'target_users' => 3,
            'notify_subject' => $subject,
            'notify_body' => $body
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    // Log ----------------------------------------------------
    public function testGetLogs()
    {
        $token = $this->getAdminAccessToken([ApiScope::LOG]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'log'))
            ->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure([
                'current_page',
                'data',
                'first_page_url',
                'from',
                'last_page',
                'last_page_url',
                'next_page_url',
                'path',
                'per_page',
                'prev_page_url',
                'to',
                'total',
            ])
        ;
    }

    public function testGetLogsWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::LOG]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'log').'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetLogsById()
    {
        $token = $this->getAdminAccessToken([ApiScope::LOG]);

        $data = OperationLog::first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'log', $data->id))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => $data->id,
            ]);
    }

    public function testGetLogsFilterLoginUserId()
    {
        $filters = ['login_user_id' => 0, 'count' => 1000000];

        $this->assertLogsFilterResult($filters, function ($result, $filterValue) {
            return array_get($result, 'user_id') == $filterValue;
        });
    }

    public function testGetLogsFilterBaseUserId()
    {
        $filters = ['base_user_id' => 1, 'count' => 1000000];
        $base_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(1);
        $login_user_ids = $base_user->login_users->pluck('id')->toArray();
        $this->assertLogsFilterResult($filters, function ($result, $filterValue) use ($login_user_ids) {
            return in_array(array_get($result, 'user_id'), $login_user_ids);
        });
    }

    public function testGetLogsFilterPath()
    {
        $filters = ['path' => admin_base_path('auth/login'), 'count' => 1000000];

        $this->assertLogsFilterResult($filters);
    }

    public function testGetLogsFilterMethod()
    {
        $filters = ['method' => 'POST', 'count' => 1000000];

        $this->assertLogsFilterResult($filters);
    }

    public function testGetLogsFilterIp()
    {
        $filters = ['ip' => '127.0.0.1', 'count' => 1000000];

        $this->assertLogsFilterResult($filters);
    }

    public function testGetLogsFilterDatetimeStart()
    {
        $count = intval(OperationLog::count() / 2);
        $target_created_at = null;
        foreach (range(0, 1000) as $i) {
            $operation_log = OperationLog::find($count + $i);
            if ($operation_log) {
                $target_created_at = $operation_log->created_at->format('Y-m-d H:i:s') ?? null;
                break;
            }
        }
        $filters = ['target_datetime_start' => $target_created_at, 'count' => 1000000];

        $this->assertLogsFilterResult($filters, function ($result, $filterValue) {
            return Carbon::parse(array_get($result, 'created_at'))->format('Y-m-d H:i:s') >= $filterValue;
        });
    }

    public function testGetLogsFilterDatetimeEnd()
    {
        $count = intval(OperationLog::count() / 2);
        $target_created_at = null;
        foreach (range(0, 1000) as $i) {
            $operation_log = OperationLog::find($count + $i);
            if ($operation_log) {
                $target_created_at = $operation_log->created_at->format('Y-m-d H:i:s') ?? null;
                break;
            }
        }

        $filters = ['target_datetime_end' => $target_created_at, 'count' => 1000000];

        $this->assertLogsFilterResult($filters, function ($result, $filterValue) {
            return Carbon::parse(array_get($result, 'created_at'))->format('Y-m-d H:i:s') <= $filterValue;
        });
    }

    public function testWrongScopeGetLogs()
    {
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'log'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testDenyGetLogs()
    {
        $token = $this->getUser1AccessToken([ApiScope::LOG]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'log'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    /**
     * Test assert logs, filtering value
     *
     * @param array $filters
     * @return void
     */
    protected function assertLogsFilterResult(array $filters, ?\Closure $ckeckCallback = null)
    {
        \Config::set('exment.api_max_data_count', 1000000);
        $token = $this->getAdminAccessToken([ApiScope::LOG]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'log', $filters))
            ->assertStatus(200);

        $results = json_decode_ex($response->baseResponse->getContent(), true)['data'];
        foreach ($results as $result) {
            foreach ($filters as $key => $value) {
                if ($key == 'count') {
                    continue;
                }
                if ($ckeckCallback) {
                    $this->assertTrue($ckeckCallback($result, $value));
                } else {
                    $this->assertMatch(array_get($result, $key), $value);
                }
            }
        }

        // Check not contains
        $query = OperationLog::query();
        $notResults = $query->whereNotIn('id', collect($results)->pluck('id')->toArray())->get();
        foreach ($notResults as $result) {
            foreach ($filters as $key => $value) {
                if ($key == 'count') {
                    continue;
                }

                if ($ckeckCallback) {
                    $this->assertFalse($ckeckCallback($result, $value));
                } else {
                    $this->assertNotMatch(array_get($result, $key), $value);
                }
            }
        }
    }




    protected function assertFileUrl($token, $response)
    {
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $id = array_get($json, 'id');

        // get file url as uuid
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit', $id . '?valuetype=text'))
        ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $url = array_get($json, 'value.file');
        $this->assertTrue(isset($url));

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get($url);

        $file = $response->baseResponse->getContent();

        $this->assertMatch($file, 'test');


        // get file url as tableKey and filename
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit', $id))
        ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $path = array_get($json, 'value.file');
        $this->assertTrue(isset($path));

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'files', str_replace("\\", "/", $path)));

        $file = $response->baseResponse->getContent();

        $this->assertMatch($file, 'test');
    }


    protected function assertFilesUrl($token, $response, $matchValues)
    {
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $id = array_get($json, 'id');

        // get file url as uuid
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $id . '?valuetype=text'))
        ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $urls = array_get($json, 'value.file_multiple');
        $this->assertTrue(isset($urls));
        $this->assertMatch(count(stringToArray($urls)), count($matchValues));

        foreach (stringToArray($urls) as $index => $url) {
            $response = $this->withHeaders([
                'Authorization' => "Bearer $token",
            ])->get($url);

            $file = $response->baseResponse->getContent();

            $this->assertMatch($file, $matchValues[$index]);
        }


        // get file url as tableKey and filename
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, $id))
        ->assertStatus(200);
        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $paths = array_get($json, 'value.file_multiple');
        $this->assertTrue(isset($paths));
        $this->assertMatch(count($paths), count($matchValues));

        foreach ($paths as $index => $path) {
            $response = $this->withHeaders([
                'Authorization' => "Bearer $token",
            ])->get(admin_urls('api', 'files', str_replace("\\", "/", $path)));

            $file = $response->baseResponse->getContent();

            $this->assertMatch($file, $matchValues[$index]);
        }
    }

    protected function _testChildrenValues($data, $table_name, $id)
    {
        $relations = CustomRelation::getRelationsByParent($table_name);

        // Whether has children
        $this->assertTrue(array_has($data, 'children'));
        foreach ($relations as $relation) {
            $this->assertTrue(array_has($data, 'children.' . $relation->child_custom_table_cache->table_name));
            $children = array_get($data, 'children.' . $relation->child_custom_table_cache->table_name);

            // get children id
            $children_ids = collect($children)->map(function ($child) {
                return array_get($child, 'id');
            })->toArray();


            if ($relation->relation_type == RelationType::ONE_TO_MANY) {
                // Get value directly with parent_id
                $childValueQuery = $relation->child_custom_table_cache->getValueQuery();
                $childValueQuery->where('parent_id', $id);
                $childIds = $childValueQuery->select('id')->get()->pluck('id')->toArray();
            }
            ////// Check as n:n
            else {
                // Get value using pivot table
                $childIds = \DB::table($relation->getRelationName())
                    ->where('parent_id', $id)
                    ->select('child_id')
                    ->distinct()
                    ->pluck('child_id')
                    ->toArray();
            }

            if (count($childIds) == 0) {
                $this->assertTrue(count($children_ids) == 0, "{$id}' children's count expects 0, but real count is " . count($children_ids));
            } else {
                foreach ($childIds as $childId) {
                    $this->assertTrue(in_array($childId, $children_ids), "{$childId} expects containing {$id}' children, but not has.");
                }
                foreach ($children_ids as $childId) {
                    $this->assertTrue(in_array($childId, $childIds), "{$childId} expects containing {$id}' children, but not has.");
                }
            }
        }
    }
}
