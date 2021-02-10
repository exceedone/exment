<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Auth API test.
 * Exment has 3 endpoints, so check whether endpoint is OK.
 */
class ApiAuthTest extends ApiTestBase
{
    use DatabaseTransactions;

    public function testApiAuthReadTrue(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'data', 'custom_value_edit', 5))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 5
            ]);
    }

    public function testApiAuthWriteTrue(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);
        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 3
            ],
            'created_user_id' => "2" //user1
        ]);
    }    

    public function testApiAuthReadFalse(){
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        $this->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->get(admin_urls('api', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
            
        $this->get(asset_urls('publicformapi', 'data', 'custom_value_edit'))
        ->assertStatus(401);

        $this->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
    }

    public function testApiAuthWriteFalse(){
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        $text = 'test' . date('YmdHis');
        $response = $this->post(admin_urls('api', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(401);
        
        // not allowed
        $response = $this->post(asset_urls('publicformapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(401);
    }    

    public function testWebApiAuthTrue(){
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        $this->get(admin_urls('webapi', 'data', 'custom_value_edit'))
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');

        $this->get(admin_urls('webapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 5
            ]);

        $text = 'test' . date('YmdHis');
        $response = $this->post(admin_urls('webapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 3
            ],
            'created_user_id' => "2" //user1
        ]);
    }

    public function testWebApiAuthReadFalse(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
            
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
    }

    public function testWebApiAuthWriteFalse(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('webapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(401);
        
        // not allowed
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(asset_urls('publicformapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(405);
    }


    // public form api ----------------------------------------------------
    public function testPublicFormApiAuthTrue(){
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        $this->get(admin_urls('webapi', 'data', 'custom_value_edit'))
            ->assertStatus(200)
            ->assertJsonCount(20, 'data');

        $this->get(admin_urls('webapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 5
            ]);

        $text = 'test' . date('YmdHis');
        $response = $this->post(admin_urls('webapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(201);

        $this->assertJsonTrue($response, [
            'value' => [
                'text' => $text,
                'user' => 3
            ],
            'created_user_id' => "2" //user1
        ]);
    }

    public function testPublicFormApiAuthReadFalse(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
            
        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);
    }

    public function testPublicFormApiAuthWriteFalse(){
        $token = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);

        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('webapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(401);
        
        // not allowed
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(asset_urls('publicformapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(405);
    }
}