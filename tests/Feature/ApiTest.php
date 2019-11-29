<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\WorkflowValueAuthority;

class ApiTest extends ApiTestBase
{
    public function testOkAuthorize(){
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

    public function testErrorAuthorize(){
        $response = $this->getPasswordToken('adjfjke', 'adjfjkeadjfjkeadjfjkeadjfjke');
        
        $response
            ->assertStatus(401);
    }
    
    public function testErrorNoToken(){
        $this->get(admin_urls('api', 'version'))
            ->assertStatus(401);
    }
    
    public function testGetVersion(){
        $token = $this->getAdminAccessToken();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'version'))
            ->assertStatus(200)
            ->assertJson([
                'version' => getExmentCurrentVersion()
            ]);
    }

    public function testWrongScopeMe(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetMe(){
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'value' => [
                    "email"=> "admin@admin.foobar.test",
                    "user_code"=> "admin",
                    "user_name"=> "admin"
                ]
            ])
            ->assertJsonStructure([
                'id',
                'suuid',
                'created_at',
                'updated_at',
                'created_user_id',
                'updated_user_id',
                'label',
            ]);
    }

    public function testGetTablesAdmin(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'table_name' => 'base_info',
            ])
            ;
    }

    public function testGetTablesWithCount(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetTablesById(){
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

    public function testGetTablesByMultiId(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=3,5,8')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetTablesExpand(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=5&expands=columns')
            ->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'parent_organization',
                'column_view_name' => '親組織',
                'column_type' => 'select_table',
                "system_flg"=> "1",
                "order"=> "0",
                'options' => [
                    "index_enabled"=> "1",
                    "select_target_table"=> 5,
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

    public function testGetTablesUser(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(200);

        $json = json_decode($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        
        $this->assertTrue(!\is_nullorempty($data));
        $this->assertTrue(
                collect($data)->contains(function($d){
                    return array_get($d, 'table_name') == 'roletest_custom_value_edit';
                })
            );
        $this->assertTrue(
            !collect($data)->contains(function($d){
                return array_get($d, 'table_name') == 'no_permission';
            })
        );
    }

    public function testGetTablesNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table').'?id=999999')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetTables(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetTable(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'roletest_custom_value_edit'))
            ->assertStatus(200);
    }

    public function testGetTableById(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', '2'))
            ->assertStatus(200);
    }

    public function testGetTableUser(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'roletest_custom_value_edit'))
            ->assertStatus(200);
    }

    public function testDenyGetTableUser(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'no_permission'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testNotFoundGetTable(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'fufhiuviveju'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testWrongScopeGetTable(){
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetTableColumns(){
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
                    'default'=> '1',
                    'required'=> '1',
                    'help'=> '一覧表示したい場合、YESに設定してください。',
                ]
            ]);
    }

    public function testGetWrongTableColumns(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'notable', 'columns'))
            ->assertStatus(404);
    }

    public function testDenyGetTableColumns(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'no_permission', 'columns'))
            ->assertStatus(403);
    }

    public function testGetColumn(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 42))
            ->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'user',
                'column_view_name' => '送信対象ユーザー',
                'column_type' => 'user',
                'system_flg'=> '1',
                'order'=> '0',
                'options' => [
                    'index_enabled'=> '1',
                ]
            ]);
    }

    public function testNotFoundGetColumn(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 99))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testWrongScopeGetColumn(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 5))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testDenyGetColumn(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'column', 65))
            ->assertStatus(403);
    }

    public function testGetValues(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit'))
            ->assertStatus(200);
    }

    public function testGetValuesWithPage(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all').'?page=3')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testGetValuesWithCount(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all').'?count=3')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testGetValuesWithOrder(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all').'?orderby=user%20desc,id%20asc')
            ->assertStatus(200);

        $json = json_decode($response->baseResponse->getContent(), true);
        $data = array_get($json, 'data');
        $value = array_get($data[0], 'value');
        $this->assertTrue(array_get($value, 'user') == '9');
    }

    public function testGetValuesByMultiId(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit').'?id=1,2,4')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function testWrongScopeGetValues(){
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testInvalidOrderGetValues(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all').'?orderby=id%20besc')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testNoIndexOrderGetValues(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all').'?orderby=text')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::NOT_INDEX_ENABLED
            ]);
    }

    public function testNotFoundGetValues(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'afkheiufu'))
            ->assertStatus(404);
    }

    public function testGetValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 1))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 1
            ]);
    }

    public function testWrongScopeGetValue(){
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 1))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testNotFoundGetValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'afkheiufu', 1))
            ->assertStatus(404);
    }

    public function testNotIdGetValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'information', 99999))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }





    
    // post value -------------------------------------

    public function testCreateValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 2
            ]
        ])
        ->assertStatus(200)
        ->assertJsonFragment([
            'value' => [
                'text' => $text,
                'user' => 2
            ],
            'created_user_id' => "1" //ADMIN
        ]);
    }

    public function testCreateMultipleValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $pre_count = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()->count();
        $values = [];
        for ($i = 1; $i <= 3; $i++) {
            $values[] = ['text' => 'test' . date('YmdHis') . $i];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), ['value' => $values])
            ->assertStatus(200);
        $count = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()->count();
        $this->assertTrue(($pre_count + 3) == $count);
    }

    public function testCreateValueWithFindkey(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 'user3'
            ],
            'findKeys' => [
                'user' => 'user_name'
            ]
        ])
        ->assertStatus(200)
        ->assertJsonFragment([
            'value' => [
                'text' => $text,
                'user' => 4
            ],
            'created_user_id' => "1" //ADMIN
        ]);
    }

    public function testCreateNoValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'novalue' => [
                'text' => $text
            ]
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testOverCreateValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $values = [];
        for ($i = 1; $i <= 101; $i++) {
            $values[] = ['text' => 'test' . date('YmdHis') . $i];
        }
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), ['value' => $values])
            ->assertStatus(400);
    }

    public function testWrongScopeCreateValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'value' => [
                'text' => $text
            ]
        ])
        ->assertStatus(403)
        ->assertJsonFragment([
            'code' => ErrorCode::WRONG_SCOPE
        ]);
    }

    public function testCreateValueInvalidFindkey(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 'user3'
            ],
            'findKeys' => [
                'user' => 'user_column'
            ]
        ])
        ->assertStatus(500);
    }

    public function testCreateValueFindkeyNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
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

    public function testCreateValueRequiredError(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'roletest_custom_value_edit'), [
            'value' => [
                'user' => 3
            ]
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testUpdateValue(){
        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()
            ->where('updated_user_id', '<>', '1')->first();
        $index_text = array_get($data->value, 'index_text');

        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'roletest_custom_value_edit', $data->id), [
            'value' => [
                'text' => $text,
                'user' => 3,
            ]
        ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'value' => [
                    'text' => $text,
                    'user' => 3,
                    'index_text' => $index_text,
                ],
                'updated_user_id' => '1' //ADMIN
            ]);
    }

    public function testUpdateValueWithFindKey(){
        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()
            ->where('updated_user_id', '<>', '2')->first();
        $old_text = array_get($data->value, 'text');
        $index_text = array_get($data->value, 'index_text');

        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'roletest_custom_value_edit', $data->id), [
            'value' => [
                'user' => 'dev1-userD',
            ],
            'findKeys' => [
                'user' => 'user_code'
            ]
        ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'value' => [
                    'text' => $old_text,
                    'user' => 8,
                    'index_text' => $index_text,
                ],
                'updated_user_id' => '2' //ADMIN
            ]);
    }

    public function testUpdateValueNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'roletest_custom_value_edit', '99999'), [
            'value' => [
                'text' => $text,
            ]
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testUpdateValueNoPermissionData(){
        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()
            ->where('created_user_id', '<>', '3')->first();

        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->put(admin_urls('api', 'data', 'roletest_custom_value_edit', $data->id), [
            'value' => [
                'text' => 'test' . date('YmdHis') . '_update',
            ]
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDeleteValue(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()->find(80);
        $this->assertTrue(isset($data));

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete(admin_urls('api', 'data', 'roletest_custom_value_edit', 80))
            ->assertStatus(204);

        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()->find(80);
        $this->assertTrue(!isset($data));
    }

    public function testDeleteValueNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis') . '_update';

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete(admin_urls('api', 'data', 'roletest_custom_value_edit', '99999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testDeleteValueNoPermissionData(){
        $data = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()
            ->where('created_user_id', '<>', '3')->first();

        /// check not permission by user
        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->delete(admin_urls('api', 'data', 'roletest_custom_value_edit', $data->id))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDataQuery(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query').'?q=index_2')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data');
    }

    public function testDataQueryWithPage(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query').'?q=index&page=3')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testDataQueryWithCount(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query').'?q=index_1&count=5')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function testDataQueryNoParam(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testDenyDataQuery(){
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'no_permission', 'query').'?q=index_3')
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDataQueryColumn(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit_all', 'query-column').'?q=index_text%20ne%20index_2_1,id%20gte%20100')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testDataQueryColumnWithPage(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit_all', 'query-column').'?q=id%20lt%2050&page=2')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');
    }

    public function testDataQueryColumnWithCount(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_edit_all', 'query-column').'?q=created_user_id%20eq%202&count=4')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function testDataQueryColumnNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query-column').'?q=index_text%20eq%20index_2_1,created_user_id%20ne%202')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testDataQueryColumnNoParam(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query-column'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::VALIDATION_ERROR
            ]);
    }

    public function testDataQueryColumnErrorColumn(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query-column').'?q=no_column%20eq%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDataQueryColumnErrorOperand(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query-column').'?q=id%20in%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDataQueryColumnNoIndex(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'roletest_custom_value_access_all', 'query-column').'?q=text%20eq%20123')
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::NOT_INDEX_ENABLED
            ]);
    }

    public function testDenyDataQueryColumn(){
        $token = $this->getUser2AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'no_permission', 'query-column').'?q=index_text%20eq%20index_2_1')
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testGetNotify(){
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify'))
            ->assertStatus(200)
            ->assertJsonCount(6, 'data');
    }

    public function testGetNotifyAll(){
        $token = $this->getAdminAccessToken([ApiScope::NOTIFY_WRITE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify').'?all=1')
            ->assertStatus(200)
            ->assertJsonCount(12, 'data');
    }

    public function testGetNotifyWithCount(){
        $token = $this->getUser1AccessToken([ApiScope::NOTIFY_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify').'?count=4')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');
    }

    public function testGetNotifyNotFound(){
        $token = $this->getUser2AccessToken([ApiScope::NOTIFY_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetNotify(){
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'notify'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    // post notify -------------------------------------

    public function testCreateNotify(){
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

    public function testCreateNotifyMultiple(){
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

    public function testCreateNotifyNoRequired(){
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

    public function testCreateNotifyNoUser(){
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

    public function testWrongScopeCreateNotify(){
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

    public function testGetWorkflowList(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow'))
            ->assertStatus(200)
            ->assertDontSeeText('workflow_common_no_complete')
            ->assertJsonCount(2, 'data');
    }

    public function testGetWorkflowListAll(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?all=1')
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete')
            ->assertJsonCount(3, 'data');
    }

    public function testGetWorkflowListWithCount(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?all=1&count=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testGetWorkflowListById(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=2')
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete');
    }

    public function testGetWorkflowListByMultiId(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=1,3')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testGetWorkflowListExpand(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?expands=statuses,actions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'workflow_statuses',
                        'workflow_actions',
                        ],
                    ],
                ]);
    }

    public function testGetWorkflowListNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=9999')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetWorkflowList(){
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetWorkflow(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '2'))
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete');
    }

    public function testGetWorkflowExpand(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '2') . '?expands=statuses,actions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'workflow_statuses',
                'workflow_actions'
            ]);
    }

    public function testGetWorkflowNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '9999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowStatusList(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '3', 'statuses'))
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function testGetWorkflowStatusListNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '999', 'statuses'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }

    public function testGetWorkflowActionList(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '3', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testGetWorkflowActionListNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '999', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }

    public function testGetWorkflowStatus(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'status', '4'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 4,
                'workflow_id' => '2',
                'status_type'=> '0',
                'order'=> '0',
                'status_name' => 'waiting',
                'datalock_flg'=> '0',
                'completed_flg'=> '0',
            ]);
    }
    
    public function testGetWorkflowStatusNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'status', '999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }
    
    public function testGetWorkflowAction(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'action', '4'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 4,
                'workflow_id' => '2',
                'status_from' => 'start',
                'action_name' => 'send',
                'ignore_work'=> '0',
                'options'=> [
                    'comment_type' => 'nullable',
                    'flow_next_type' => 'some',
                    'flow_next_count' => '1',
                    'work_target_type' => 'fix'
                ],
            ]);
    }

    public function testGetWorkflowActionNotFound(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'action', '999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }
    
    public function testGetWorkflowData(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '1000', 'value'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'workflow_id' => '2',
                'morph_type' => 'roletest_custom_value_access_all',
                'morph_id' => '1000',
                'workflow_action_id'=> '5',
                'workflow_status_from_id'=> '4',
                'workflow_status_to_id'=> '5',
                'action_executed_flg'=> '0',
                'latest_flg'=> '1',
            ]);
    }
    
    public function testGetWorkflowDataExpand(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value') . '?expands=status_from,status_to,actions')
            ->assertStatus(200);
            $response->assertJsonStructure([
                'workflow_status_from',
                'workflow_status',
                'workflow_action',
            ]);
    }

    public function testGetWorkflowDataNotFound(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '9999', 'value'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowDataNotStart(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '10', 'value'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_NOSTART
            ]);
    }

    public function testDenyGetWorkflowDataTable(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'value'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowData(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }
    
    public function testGetWorkflowUser(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'work_users'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'organization_name' => 'dev'
            ]);
    }
    
    public function testGetWorkflowUserOrg(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'work_users'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'organization_name' => 'dev'
            ]);
    }
    
    public function testGetWorkflowUserAll(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'work_users') . '?all=1')
            ->assertStatus(200)
            ->assertJsonCount(2);
    }
    
    public function testGetWorkflowUserAsUser(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'work_users') . '?as_user=1')
            ->assertStatus(200)
            ->assertSeeText('dev-userB');
    }

    public function testGetWorkflowUserNotFound(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '9999', 'work_users'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowUserEnd(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '1000', 'work_users'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_END
            ]);
    }

    public function testDenyGetWorkflowUserTable(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'work_users'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowUser(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'work_users'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }
    
    public function testGetWorkflowExecAction(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertSeeText('action3');
    }
    
    public function testGetWorkflowExecActionAll(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'actions') . '?all=1')
            ->assertStatus(200)
            ->assertJsonCount(2)
            ->assertSeeText('action2');
    }
    
    public function testGetWorkflowExecActionZero(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_view_all', '1', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(0);
    }
    
    public function testGetWorkflowExecActionNotFound(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '99999', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }
    
    public function testGetWorkflowExecActionNoTable(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }
    
    public function testGetWorkflowExecActionEnd(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_END
            ]);
    }

    public function testDenyGetWorkflowExecActionTable(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'actions'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowExecAction(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'actions'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }
    
    public function testGetWorkflowHistory(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '1000', 'histories'))
            ->assertStatus(200)
            ->assertJsonCount(2);
    }
    
    public function testGetWorkflowHistoryZero(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_access_all', '10', 'histories'))
            ->assertStatus(200)
            ->assertJsonCount(0);
    }
    
    public function testGetWorkflowHistoryNotFound(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '99999', 'histories'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }
    
    public function testGetWorkflowHistoryNoTable(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'histories'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDenyGetWorkflowHistoryTable(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'histories'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowHistory(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'histories'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    
    // post value (!!! test execute workflow at once !!!)-------------------------------------

    public function testExecuteWorkflowNoNext(){
        $token = $this->getUserAccessToken('dev-userB', 'dev-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 2,
            'comment' => $comment
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflowWithNext(){
        $token = $this->getUserAccessToken('dev-userB', 'dev-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 2,
            'next_users' => '4,3',
            'next_organizations' => 2,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 2,
            'comment' => $comment,
            'created_user_id' => "6" //dev-userB
        ]);

        $json = json_decode($response->baseResponse->getContent(), true);
        $id = array_get($json, 'id');
        
        $authorities = WorkflowValueAuthority::where('workflow_value_id', $id)->get();
        $this->assertTrue(!\is_nullorempty($authorities));
        $this->assertTrue(count($authorities) === 3);
        foreach ($authorities as $authority) {
            $this->assertTrue(
                ($authority->related_id == '2' && $authority->related_type == 'organization') ||
                ($authority->related_id == '3' && $authority->related_type == 'user') ||
                ($authority->related_id == '4' && $authority->related_type == 'user')
            );
        }
    }

    public function testExecuteWorkflowNoParam(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value'), [
            'comment' => 'comment'
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflowNoComment(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflow(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 3,
            'workflow_status_to_id' => '2',
            'created_user_id' => "3", //User1
            'comment' => $comment
        ]);
    }

    public function testExecuteWorkflowMultiUser(){
        $token = $this->getUserAccessToken('dev-userB', 'dev-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 3,
            'workflow_status_to_id' => '3',
            'created_user_id' => "6", //User1
            'comment' => $comment
        ]);
    }

    public function testExecuteWorkflowNoAction(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 99999
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecuteWorkflowWrongAction(){
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1', 'value'), [
            'workflow_action_id' => 6
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }
    
    public function testExecuteWorkflowNotFound(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '99999', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }
    
    public function testExecuteWorkflowNoTable(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDenyExecuteWorkflowTable(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyExecuteWorkflow(){
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testWrongScopeExecuteWorkflow(){
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'roletest_custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }
}
