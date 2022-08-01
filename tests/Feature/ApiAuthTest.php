<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
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

    public function testApiAuthReadTrue()
    {
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

    public function testApiAuthWriteTrue()
    {
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

    public function testApiAuthReadFalse()
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        $this->get(admin_urls('api', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->get(admin_urls('api', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);

        $this->get(asset_urls('publicformapi', 'data', 'custom_value_edit'))
            ->assertStatus(404);

        $this->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(404);
    }

    public function testApiAuthWriteFalse()
    {
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
        ->assertStatus(404);
    }

    public function testWebApiAuthTrue()
    {
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

    public function testWebApiAuthReadFalse()
    {
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
            ->assertStatus(404);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(404);
    }

    public function testWebApiAuthWriteFalse()
    {
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
        ])->assertStatus(404);
    }


    // public form api ----------------------------------------------------
    public function testPublicFormApiAuthTrue()
    {
        $uri = $this->getPublicFormApiUri(TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->get(url_join($uri, 'data', 'custom_value_edit', 'select?q=index_001_002'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->get(url_join($uri, 'data', 'custom_value_edit', 5))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 5
            ]);
    }

    public function testPublicFormApiAuthReadFalse()
    {
        // dummy uri
        $uri = url_join(public_form_url(), 'naofenofwnefielk');

        $this->withHeaders([
        ])->get(url_join($uri, 'data', 'custom_value_edit', 5))
            ->assertStatus(404);

        $this->withHeaders([
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit'))
            ->assertStatus(401);

        $this->withHeaders([
        ])->get(admin_urls('webapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(401);

        $this->withHeaders([
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit'))
            ->assertStatus(404);

        $this->withHeaders([
        ])->get(asset_urls('publicformapi', 'data', 'custom_value_edit', 5))
            ->assertStatus(404);
    }

    public function testPublicFormApiAuthWriteFalse()
    {
        // dummy uri
        $uri = asset_urls('publicformapi', 'naofenofwnefielk');

        // not allowed
        $text = 'test' . date('YmdHis');
        $response = $this->withHeaders([
        ])->post(url_join($uri, 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(404);

        // not allowed
        $response = $this->withHeaders([
        ])->post(asset_urls('publicformapi', 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])->assertStatus(404);


        ///// Cannot post
        $uri = $this->getPublicFormApiUri(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $text = 'test' . date('YmdHis');
        $response = $this->post(url_join($uri, 'data', 'custom_value_edit'), [
            'value' => [
                'text' => $text,
                'user' => 3
            ]
        ])
        ->assertStatus(404);
    }
}
