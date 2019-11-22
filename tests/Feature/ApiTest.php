<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;

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

    public function testGetTables(){
        $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table'))
            ->assertStatus(200)
//            ->assertJsonCount(0, 'data');
            ->assertJsonCount(6, 'data');
    }

    public function testGetTablesNoData(){
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

    public function testGetTableById(){
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'table', 'information'));
        $response->assertStatus(200);
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

    public function testGetColumnNotFound(){
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

    // public function testGetColumnAuth(){
    //     $token = $this->getUser2AccessToken([ApiScope::TABLE_READ]);

    //     $this->withHeaders([
    //         'Authorization' => "Bearer $token",
    //     ])->get(admin_urls('api', 'column', 5))
    //         ->assertStatus(403);
    // }
}
